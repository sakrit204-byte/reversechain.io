import {
  toLibraryDocument,
  toMe,
  toNotification,
  toPassport,
  toPassportSummary,
  toProgram,
  toProgramDetail,
} from './normalize';
import type {
  AppConfig,
  AppNotification,
  ClientPlatform,
  DeleteAccountInput,
  DeleteAccountResult,
  GatedList,
  Holding,
  LanguageCode,
  LibraryDocument,
  LoginResult,
  Me,
  MfaEnableResult,
  MfaSetup,
  OkResult,
  Passport,
  PassportSummary,
  Program,
  ProgramDetail,
  RegisterInput,
  SupportInput,
  SupportResult,
  Tokens,
  Transaction,
  VerifyResult,
} from './types';

/** Error raised for any non-2xx response or network failure (WP format {code,message,data}). */
export class ApiError extends Error {
  readonly status: number;
  readonly code: string | null;
  /** Field-level validation errors when the backend provides `data.fields`. */
  readonly fields: Record<string, string> | null;
  constructor(message: string, status: number, code: string | null = null, fields: Record<string, string> | null = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.fields = fields;
  }
}

/** Persistence for bearer + refresh tokens (SecureStore in the app, in-memory in tests). */
export interface TokenStore {
  get(): Promise<Tokens | null>;
  set(tokens: Tokens): Promise<void>;
  clear(): Promise<void>;
}

export interface ReserveChainApi {
  readonly mock: boolean;
  getConfig(): Promise<AppConfig>;
  getPrograms(): Promise<Program[]>;
  getProgram(slug: string): Promise<ProgramDetail>;
  getPassports(program?: string): Promise<PassportSummary[]>;
  getPassport(passportNo: string): Promise<Passport>;
  verifyHash(sha256: string): Promise<VerifyResult>;
  getDocuments(): Promise<LibraryDocument[]>;
  register(input: RegisterInput): Promise<OkResult>;
  login(email: string, password: string): Promise<LoginResult>;
  /** `code` is a 6-digit TOTP or a 10-character recovery code. */
  verifyMfa(mfaToken: string, code: string): Promise<Tokens>;
  setupMfa(): Promise<MfaSetup>;
  enableMfa(code: string): Promise<MfaEnableResult>;
  refresh(): Promise<Tokens>;
  /** Revokes all server sessions (best effort) and clears local tokens. */
  logout(): Promise<void>;
  getMe(): Promise<Me>;
  updateMe(patch: { name?: string; language?: LanguageCode }): Promise<OkResult>;
  getHoldings(): Promise<GatedList<Holding>>;
  getTransactions(): Promise<GatedList<Transaction>>;
  getNotifications(): Promise<AppNotification[]>;
  markNotificationRead(id: string | number): Promise<OkResult>;
  submitSupport(input: SupportInput): Promise<SupportResult>;
  registerDevice(pushToken: string): Promise<OkResult>;
  /**
   * Permanently deletes (or anonymises) the signed-in account. On success the local tokens are cleared.
   * Errors: 422 `rc_delete_confirm` (wrong password / confirmation), 403 `rc_delete_staff` (staff accounts).
   */
  deleteAccount(input: DeleteAccountInput): Promise<DeleteAccountResult>;
}

export interface ClientOptions {
  baseUrl: string;
  tokenStore: TokenStore;
  client: ClientPlatform;
  fetchImpl?: typeof fetch;
  /** Called when the session can no longer be refreshed (forces logout in the UI). */
  onAuthFailure?: () => void;
  /** Accept-Language header source. */
  getLanguage?: () => string;
  timeoutMs?: number;
}

type AuthMode = 'none' | 'bearer' | { token: string };

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH';
  body?: unknown;
  auth?: AuthMode;
  query?: Record<string, string | undefined>;
}

/** Accepts either a bare array or a `{items:[...]}` envelope. */
export function asList(data: unknown): unknown[] {
  if (Array.isArray(data)) return data;
  if (data && typeof data === 'object') {
    const o = data as Record<string, unknown>;
    for (const key of ['items', 'data', 'results']) {
      if (Array.isArray(o[key])) return o[key] as unknown[];
    }
  }
  return [];
}

/** Fail-closed normalisation of gated endpoints: anything but an explicit `enabled:true` is disabled. */
export function asGated<T>(data: unknown): GatedList<T> {
  const o = (data && typeof data === 'object' ? data : {}) as Record<string, unknown>;
  const enabled = o.enabled === true;
  return {
    enabled,
    reason: typeof o.reason === 'string' ? o.reason : null,
    items: enabled ? (asList(o.items) as T[]) : [],
  };
}

export function isTokens(x: unknown): x is Tokens {
  return (
    !!x &&
    typeof x === 'object' &&
    typeof (x as Tokens).access_token === 'string' &&
    typeof (x as Tokens).refresh_token === 'string'
  );
}

const pickTokens = (t: Tokens): Tokens => ({
  access_token: t.access_token,
  refresh_token: t.refresh_token,
  token_type: t.token_type,
  expires_in: t.expires_in,
});

export function createHttpApi(opts: ClientOptions): ReserveChainApi {
  const base = opts.baseUrl.replace(/\/+$/, '');
  const doFetch = opts.fetchImpl ?? fetch;
  const timeoutMs = opts.timeoutMs ?? 20000;
  let refreshInFlight: Promise<Tokens> | null = null;

  async function raw<T>(path: string, ro: RequestOptions): Promise<T> {
    const headers: Record<string, string> = { Accept: 'application/json' };
    const lang = opts.getLanguage?.();
    if (lang) headers['Accept-Language'] = lang;
    if (ro.body !== undefined) headers['Content-Type'] = 'application/json';

    if (ro.auth === 'bearer') {
      const t = await opts.tokenStore.get();
      if (!t) throw new ApiError('Not signed in', 401, 'no_session');
      headers.Authorization = `Bearer ${t.access_token}`;
    } else if (ro.auth && typeof ro.auth === 'object') {
      headers.Authorization = `Bearer ${ro.auth.token}`;
    }

    let url = `${base}${path}`;
    if (ro.query) {
      const qs = Object.entries(ro.query)
        .filter((e): e is [string, string] => typeof e[1] === 'string' && e[1].length > 0)
        .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
        .join('&');
      if (qs) url += `?${qs}`;
    }

    const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    const timer = controller ? setTimeout(() => controller.abort(), timeoutMs) : null;
    let res: Response;
    try {
      res = await doFetch(url, {
        method: ro.method ?? 'GET',
        headers,
        body: ro.body !== undefined ? JSON.stringify(ro.body) : undefined,
        signal: controller?.signal,
      });
    } catch (e) {
      throw new ApiError(e instanceof Error ? e.message : 'Network error', 0, 'network');
    } finally {
      if (timer) clearTimeout(timer);
    }

    const text = await res.text();
    let data: unknown = null;
    if (text) {
      try {
        data = JSON.parse(text);
      } catch {
        data = null;
      }
    }
    if (!res.ok) {
      const o = (data ?? {}) as { message?: unknown; code?: unknown; data?: { fields?: unknown } };
      const fields =
        o.data && o.data.fields && typeof o.data.fields === 'object'
          ? (o.data.fields as Record<string, string>)
          : null;
      throw new ApiError(
        typeof o.message === 'string' ? o.message : `HTTP ${res.status}`,
        res.status,
        typeof o.code === 'string' ? o.code : null,
        fields,
      );
    }
    return data as T;
  }

  async function refresh(): Promise<Tokens> {
    // Coalesce concurrent refreshes: the backend rotates refresh tokens (old one becomes invalid).
    if (!refreshInFlight) {
      refreshInFlight = (async () => {
        const current = await opts.tokenStore.get();
        if (!current) throw new ApiError('Not signed in', 401, 'no_session');
        const data = await raw<unknown>('/auth/refresh', {
          method: 'POST',
          body: { refresh_token: current.refresh_token, client: opts.client },
        });
        if (!isTokens(data)) throw new ApiError('Invalid refresh response', 500, 'bad_refresh');
        const t = pickTokens(data);
        await opts.tokenStore.set(t);
        return t;
      })().finally(() => {
        refreshInFlight = null;
      });
    }
    return refreshInFlight;
  }

  /** Bearer request with a single transparent refresh-and-retry on 401. */
  async function authed<T>(path: string, ro: RequestOptions = {}): Promise<T> {
    try {
      return await raw<T>(path, { ...ro, auth: 'bearer' });
    } catch (e) {
      if (!(e instanceof ApiError) || e.status !== 401 || e.code === 'no_session') throw e;
      try {
        await refresh();
      } catch {
        await opts.tokenStore.clear();
        opts.onAuthFailure?.();
        throw new ApiError('Session expired', 401, 'session_expired');
      }
      return raw<T>(path, { ...ro, auth: 'bearer' });
    }
  }

  return {
    mock: false,
    getConfig: () => raw<AppConfig>('/config', {}),
    getPrograms: async () => asList(await raw('/programs', {})).map(toProgram),
    getProgram: async (slug) => toProgramDetail(await raw(`/programs/${encodeURIComponent(slug)}`, {})),
    getPassports: async (program) =>
      asList(await raw('/passports', { query: { program } })).map(toPassportSummary),
    getPassport: async (no) => toPassport(await raw(`/passports/${encodeURIComponent(no)}`, {})),
    verifyHash: (hash) => raw<VerifyResult>('/verify', { query: { hash } }),
    getDocuments: async () => asList(await raw('/documents', {})).map(toLibraryDocument),
    register: (input) => raw<OkResult>('/auth/register', { method: 'POST', body: input }),
    login: async (email, password) => {
      const data = await raw<LoginResult>('/auth/login', {
        method: 'POST',
        body: { email, password, client: opts.client },
      });
      if (data.mfa_required === true) return data;
      if (!isTokens(data)) throw new ApiError('Invalid login response', 500, 'bad_login');
      await opts.tokenStore.set(pickTokens(data));
      return data;
    },
    verifyMfa: async (mfaToken, code) => {
      const data = await raw<unknown>('/auth/mfa/verify', {
        method: 'POST',
        body: { mfa_token: mfaToken, code, client: opts.client },
        auth: { token: mfaToken },
      });
      if (!isTokens(data)) throw new ApiError('Invalid MFA response', 500, 'bad_mfa');
      const t = pickTokens(data);
      await opts.tokenStore.set(t);
      return t;
    },
    setupMfa: () => authed<MfaSetup>('/auth/mfa/setup', { method: 'POST' }),
    enableMfa: async (code) => {
      const r = await authed<{ ok?: unknown; recovery_codes?: unknown }>('/auth/mfa/enable', {
        method: 'POST',
        body: { code },
      });
      return {
        ok: r.ok === true,
        recovery_codes: Array.isArray(r.recovery_codes)
          ? r.recovery_codes.filter((c): c is string => typeof c === 'string')
          : [],
      };
    },
    refresh,
    logout: async () => {
      try {
        if (await opts.tokenStore.get()) await raw('/auth/logout', { method: 'POST', auth: 'bearer' });
      } catch {
        /* best effort — local tokens are cleared regardless */
      } finally {
        await opts.tokenStore.clear();
      }
    },
    getMe: async () => toMe(await authed('/me')),
    updateMe: (patch) => authed<OkResult>('/me', { method: 'PATCH', body: patch }),
    getHoldings: async () => asGated<Holding>(await authed('/me/holdings')),
    getTransactions: async () => asGated<Transaction>(await authed('/me/transactions')),
    getNotifications: async () => asList(await authed('/me/notifications')).map(toNotification),
    markNotificationRead: (nid) =>
      authed<OkResult>(`/me/notifications/${encodeURIComponent(String(nid))}/read`, { method: 'POST' }),
    submitSupport: async (input) => {
      const r = await authed<{ ok?: unknown; ticket?: unknown }>('/support', { method: 'POST', body: input });
      return { ok: r.ok === true, ticket: typeof r.ticket === 'string' ? r.ticket : null };
    },
    registerDevice: (pushToken) =>
      authed<OkResult>('/me/devices', { method: 'POST', body: { push_token: pushToken, platform: opts.client } }),
    deleteAccount: async (input) => {
      const r = await authed<{ ok?: unknown; mode?: unknown; message?: unknown }>('/me/delete', {
        method: 'POST',
        body: { password: input.password, confirm: input.confirm },
      });
      if (r.ok !== true) throw new ApiError('Account deletion was not confirmed', 500, 'bad_delete');
      // The server sessions no longer exist: drop local tokens without calling /auth/logout.
      await opts.tokenStore.clear();
      return {
        ok: true,
        mode: r.mode === 'deleted' || r.mode === 'anonymised' ? r.mode : null,
        message: typeof r.message === 'string' ? r.message : null,
      };
    },
  };
}
