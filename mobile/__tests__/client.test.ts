import { ApiError, asGated, createHttpApi, type TokenStore } from '@/api/client';
import { toClaimStatus, toPassport } from '@/api/normalize';
import type { Tokens } from '@/api/types';

function memoryStore(initial: Tokens | null = null): TokenStore & { value: Tokens | null } {
  const s = {
    value: initial,
    get: async () => s.value,
    set: async (t: Tokens) => {
      s.value = t;
    },
    clear: async () => {
      s.value = null;
    },
  };
  return s;
}

type Call = { url: string; init: RequestInit };
function fakeFetch(responses: { status: number; body: unknown }[]) {
  const calls: Call[] = [];
  const impl = (async (url: string, init: RequestInit) => {
    calls.push({ url, init });
    const r = responses.shift();
    if (!r) throw new Error('unexpected request');
    return {
      ok: r.status >= 200 && r.status < 300,
      status: r.status,
      text: async () => JSON.stringify(r.body),
    } as Response;
  }) as unknown as typeof fetch;
  return { impl, calls };
}

const BASE = 'https://api.test/wp-json/rc/v1';
const body = (c: Call | undefined) => JSON.parse(String(c?.init.body)) as Record<string, unknown>;

describe('HTTP API client', () => {
  it('stores tokens on login without MFA and sends the client platform', async () => {
    const store = memoryStore();
    const f = fakeFetch([
      { status: 200, body: { mfa_required: false, access_token: 'a1', refresh_token: 'r1', token_type: 'Bearer', expires_in: 3600 } },
    ]);
    const api = createHttpApi({ baseUrl: `${BASE}/`, tokenStore: store, client: 'ios', fetchImpl: f.impl });
    await api.login('x@y.io', 'pw');
    expect(store.value?.access_token).toBe('a1');
    expect(f.calls[0]?.url).toBe(`${BASE}/auth/login`);
    expect(body(f.calls[0])).toEqual({ email: 'x@y.io', password: 'pw', client: 'ios' });
  });

  it('does not store tokens when MFA is required, then stores after verify', async () => {
    const store = memoryStore();
    const f = fakeFetch([
      { status: 200, body: { mfa_required: true, mfa_token: 'm1' } },
      { status: 200, body: { access_token: 'a2', refresh_token: 'r2' } },
    ]);
    const api = createHttpApi({ baseUrl: BASE, tokenStore: store, client: 'android', fetchImpl: f.impl });
    const r = await api.login('x@y.io', 'pw');
    expect(r.mfa_required).toBe(true);
    expect(store.value).toBeNull();
    await api.verifyMfa('m1', '123456');
    expect(store.value?.access_token).toBe('a2');
    expect(body(f.calls[1])).toEqual({ mfa_token: 'm1', code: '123456', client: 'android' });
  });

  it('refreshes once on 401 and retries with the rotated token', async () => {
    const store = memoryStore({ access_token: 'old', refresh_token: 'r-old' });
    const f = fakeFetch([
      { status: 401, body: { code: 'rc_token_expired', message: 'expired', data: { status: 401 } } },
      { status: 200, body: { access_token: 'new', refresh_token: 'r-new' } },
      { status: 200, body: { id: 1, name: 'A', email: 'a@b.io', mfa_enabled: true, eligibility: { kyc: 'approved' } } },
    ]);
    const api = createHttpApi({ baseUrl: BASE, tokenStore: store, client: 'ios', fetchImpl: f.impl });
    const me = await api.getMe();
    expect(me.eligibility.kyc).toBe('approved');
    expect(me.eligibility.jurisdiction).toBe('pending');
    expect(body(f.calls[1]).refresh_token).toBe('r-old');
    expect((f.calls[2]?.init.headers as Record<string, string>).Authorization).toBe('Bearer new');
    expect(store.value?.refresh_token).toBe('r-new');
  });

  it('clears the session and notifies when refresh fails', async () => {
    const store = memoryStore({ access_token: 'old', refresh_token: 'r-old' });
    const onAuthFailure = jest.fn();
    const f = fakeFetch([
      { status: 401, body: {} },
      { status: 401, body: { code: 'rc_invalid_refresh', message: 'invalid' } },
    ]);
    const api = createHttpApi({ baseUrl: BASE, tokenStore: store, client: 'ios', fetchImpl: f.impl, onAuthFailure });
    await expect(api.getMe()).rejects.toMatchObject({ status: 401, code: 'session_expired' });
    expect(store.value).toBeNull();
    expect(onAuthFailure).toHaveBeenCalledTimes(1);
  });

  it('parses WordPress error envelopes including field errors', async () => {
    const f = fakeFetch([
      { status: 400, body: { code: 'rc_invalid', message: 'Invalid', data: { status: 400, fields: { password: 'Too weak' } } } },
    ]);
    const api = createHttpApi({ baseUrl: BASE, tokenStore: memoryStore(), client: 'ios', fetchImpl: f.impl });
    const err = await api
      .register({
        email: 'a@b.io',
        password: 'x',
        name: 'A',
        country: 'CH',
        entity_type: 'individual',
        accept_disclosure: true,
        accept_terms: true,
      })
      .catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).code).toBe('rc_invalid');
    expect((err as ApiError).fields).toEqual({ password: 'Too weak' });
  });

  it('reports network failures as status 0', async () => {
    const impl = (async () => {
      throw new TypeError('Network request failed');
    }) as unknown as typeof fetch;
    const api = createHttpApi({ baseUrl: BASE, tokenStore: memoryStore(), client: 'ios', fetchImpl: impl });
    await expect(api.getConfig()).rejects.toMatchObject({ status: 0, code: 'network' });
  });

  it('logout clears local tokens even if the server call fails', async () => {
    const store = memoryStore({ access_token: 'a', refresh_token: 'r' });
    const f = fakeFetch([{ status: 500, body: {} }]);
    const api = createHttpApi({ baseUrl: BASE, tokenStore: store, client: 'ios', fetchImpl: f.impl });
    await api.logout();
    expect(f.calls[0]?.url).toBe(`${BASE}/auth/logout`);
    expect(store.value).toBeNull();
  });

  it('gated lists are fail-closed', () => {
    expect(asGated({ enabled: false, items: [{ a: 1 }] })).toEqual({ enabled: false, reason: null, items: [] });
    expect(asGated({ enabled: 'true', items: [{ a: 1 }] }).enabled).toBe(false);
    expect(asGated(null).enabled).toBe(false);
    expect(asGated({ enabled: true, items: [{ a: 1 }] }).items).toHaveLength(1);
  });
});

describe('normalisation', () => {
  it('never upgrades unknown statuses', () => {
    expect(toClaimStatus('verified')).toBe('verified');
    expect(toClaimStatus('pending')).toBe('pending_verification');
    expect(toClaimStatus('totally_confirmed')).toBe('proposed');
    expect(toClaimStatus(undefined)).toBe('proposed');
  });

  it('maps the backend passport shape and keeps pending values null', () => {
    const p = toPassport({
      passport_no: 'RC-CU-LOT-000001',
      entity_type: 'lot',
      entity_label: 'Lot',
      title: 'Lot 1',
      status: 'pending_verification',
      program: { name: 'Copper Powder', slug: 'copper-powder', symbol: 'Cu', atomic_number: 29 },
      fields: [{ key: 'purity', label: 'Purity', value: '99.9', unit: '%', state: 'pending', pending: true }],
      documents: [
        {
          record_no: 'D1',
          title: 'CoA',
          type: 'coa',
          type_label: 'Certificate',
          sha256: 'AB'.repeat(32),
          status: 'pending',
          issued_by: null,
          url: null,
        },
      ],
      timeline: [{ key: 'k', label: 'Sampled', status: 'pending', date: null }],
      merkle_root: null,
      completeness: { present: 1, total: 8, percent: 12.5 },
      url: 'https://reservechain.io/passport/RC-CU-LOT-000001',
    });
    expect(p.program).toBe('copper-powder');
    expect(p.fields[0]?.value).toBeNull();
    expect(p.fields[0]?.status).toBe('pending_verification');
    expect(p.documents[0]?.sha256).toBe('ab'.repeat(32));
    expect(p.documents[0]?.type).toBe('Certificate');
    expect(p.timeline[0]?.status).toBe('pending_verification');
    expect(p.completeness?.total).toBe(8);
    expect(p.url).toContain('RC-CU-LOT-000001');
  });

  it('rejects malformed hashes instead of displaying them', () => {
    const p = toPassport({ documents: [{ title: 'x', sha256: 'not-a-hash' }] });
    expect(p.documents[0]?.sha256).toBeNull();
  });
});
