/**
 * Account deletion: typed HTTP client (POST /me/delete), mock implementation, and the in-app flow
 * (password + typed DELETE → final confirmation → local sign-out and secure-storage wipe).
 */
import { act, fireEvent, waitFor } from '@testing-library/react-native';
import { renderRouter } from 'expo-router/testing-library';
import { ApiError, createHttpApi, type TokenStore } from '@/api/client';
import { createMockApi } from '@/api/mock';
import type { Tokens } from '@/api/types';

jest.mock('@/config/env', () => ({
  env: { apiUrl: 'http://localhost/wp-json/rc/v1', mock: true, inactivityMinutes: 5 },
}));

jest.mock('@/storage/secure', () => {
  const mem = new Map<string, string>();
  const secureTokenStore = {
    get: async () => {
      const raw = mem.get('tokens');
      return raw ? JSON.parse(raw) : null;
    },
    set: async (t: unknown) => void mem.set('tokens', JSON.stringify(t)),
    clear: async () => void mem.delete('tokens'),
  };
  return {
    __mem: mem,
    secureStorage: {
      get: async (k: string) => mem.get(k) ?? null,
      set: async (k: string, v: string) => void mem.set(k, v),
      remove: async (k: string) => void mem.delete(k),
    },
    prefs: {
      get: async (k: string) => mem.get(`pref.${k}`) ?? null,
      set: async (k: string, v: string) => void mem.set(`pref.${k}`, v),
    },
    secureTokenStore,
    clearAccountData: async () => {
      mem.delete('tokens');
      mem.set('pref.biometric_enabled', '0');
    },
    PREF_KEYS: { disclosureAck: 'disclosure_ack_version', language: 'language', biometric: 'biometric_enabled' },
  };
});

const mem = (jest.requireMock('@/storage/secure') as { __mem: Map<string, string> }).__mem;

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

function fakeFetch(responses: { status: number; body: unknown }[]) {
  const calls: { url: string; init: RequestInit }[] = [];
  const impl = (async (url: string, init: RequestInit) => {
    calls.push({ url, init });
    const r = responses.shift();
    if (!r) throw new Error('unexpected request');
    return { ok: r.status >= 200 && r.status < 300, status: r.status, text: async () => JSON.stringify(r.body) } as Response;
  }) as unknown as typeof fetch;
  return { impl, calls };
}

const BASE = 'https://api.test/wp-json/rc/v1';

describe('deleteAccount — HTTP client', () => {
  it('posts password + DELETE with the bearer token and clears local tokens', async () => {
    const store = memoryStore({ access_token: 'a1', refresh_token: 'r1' });
    const f = fakeFetch([{ status: 200, body: { ok: true, mode: 'anonymised', message: 'Done' } }]);
    const api = createHttpApi({ baseUrl: BASE, tokenStore: store, client: 'ios', fetchImpl: f.impl });
    const r = await api.deleteAccount({ password: 'pw', confirm: 'DELETE' });
    expect(r).toEqual({ ok: true, mode: 'anonymised', message: 'Done' });
    expect(f.calls[0]?.url).toBe(`${BASE}/me/delete`);
    expect(f.calls[0]?.init.method).toBe('POST');
    expect(JSON.parse(String(f.calls[0]?.init.body))).toEqual({ password: 'pw', confirm: 'DELETE' });
    expect((f.calls[0]?.init.headers as Record<string, string>).Authorization).toBe('Bearer a1');
    expect(store.value).toBeNull();
  });

  it.each([
    [422, 'rc_delete_confirm'],
    [403, 'rc_delete_staff'],
  ])('surfaces %i %s and keeps the session', async (status, code) => {
    const store = memoryStore({ access_token: 'a1', refresh_token: 'r1' });
    const f = fakeFetch([{ status, body: { code, message: 'no', data: { status } } }]);
    const api = createHttpApi({ baseUrl: BASE, tokenStore: store, client: 'android', fetchImpl: f.impl });
    const err = await api.deleteAccount({ password: 'x', confirm: 'DELETE' }).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    expect((err as ApiError).code).toBe(code);
    expect((err as ApiError).status).toBe(status);
    expect(store.value?.access_token).toBe('a1');
  });
});

describe('deleteAccount — mock API', () => {
  it('rejects a wrong confirmation with 422 and succeeds with DELETE', async () => {
    const store = memoryStore({ access_token: 'a', refresh_token: 'r' });
    const api = createMockApi({ tokenStore: store, delayMs: 0 });
    const bad = api.deleteAccount({ password: 'pw', confirm: 'delete' as 'DELETE' });
    await expect(bad).rejects.toMatchObject({ status: 422, code: 'rc_delete_confirm' });
    await expect(api.deleteAccount({ password: 'pw', confirm: 'DELETE' })).resolves.toMatchObject({ ok: true, mode: 'deleted' });
    expect(store.value).toBeNull();
  });
});

jest.setTimeout(30000);

describe('deleteAccount — in-app flow (mock mode)', () => {
  beforeEach(() => mem.clear());

  it('requires password + DELETE, asks for final confirmation, then signs out and wipes storage', async () => {
    const { DISCLOSURE_VERSION } = jest.requireActual('@/config/compliance') as { DISCLOSURE_VERSION: string };
    mem.set('pref.disclosure_ack_version', DISCLOSURE_VERSION);
    mem.set('pref.language', 'en');
    mem.set('pref.biometric_enabled', '0');
    mem.set('tokens', JSON.stringify({ access_token: 'mock-a', refresh_token: 'mock-r' }));

    const screen = renderRouter('./app', { initialUrl: '/delete-account' });
    await waitFor(() => expect(screen.getByText('What is erased')).toBeTruthy(), { timeout: 8000 });
    expect(screen.getByText('What may be kept')).toBeTruthy();

    // Validation: nothing entered.
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Delete account…' })));
    expect(screen.getByText('Enter your current password.')).toBeTruthy();
    expect(screen.getByText('Type DELETE in capital letters to confirm.')).toBeTruthy();
    expect(mem.get('tokens')).toBeTruthy();

    fireEvent.changeText(screen.getByLabelText('Current password'), 'Secret-password-1');
    fireEvent.changeText(screen.getByLabelText('Type DELETE to confirm'), 'DELETE');
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Delete account…' })));

    // Final confirmation step.
    expect(screen.getByText('Delete your account permanently?')).toBeTruthy();
    expect(mem.get('tokens')).toBeTruthy();
    await act(async () => fireEvent.press(screen.getByRole('button', { name: 'Yes, delete permanently' })));

    await waitFor(
      () => expect(screen.getByText('Your account has been deleted and this device has been signed out.')).toBeTruthy(),
      { timeout: 8000 },
    );
    expect(mem.get('tokens')).toBeUndefined();
    expect(mem.get('pref.biometric_enabled')).toBe('0');
  });
});
