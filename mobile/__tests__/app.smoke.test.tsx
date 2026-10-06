/**
 * End-to-end smoke test of the real route tree in MOCK mode:
 * onboarding disclosure gate → login → guest browsing → passport detail → locked asset modules.
 */
import { act, fireEvent, waitFor } from '@testing-library/react-native';
import { renderRouter } from 'expo-router/testing-library';

jest.mock('@/config/env', () => ({
  env: { apiUrl: 'http://localhost/wp-json/rc/v1', mock: true, inactivityMinutes: 5 },
}));

// In-memory replacement for SecureStore-backed storage.
jest.mock('@/storage/secure', () => {
  const mem = new Map<string, string>();
  const secureStorage = {
    get: async (k: string) => mem.get(k) ?? null,
    set: async (k: string, v: string) => void mem.set(k, v),
    remove: async (k: string) => void mem.delete(k),
  };
  return {
    __mem: mem,
    secureStorage,
    prefs: {
      get: async (k: string) => mem.get(`pref.${k}`) ?? null,
      set: async (k: string, v: string) => void mem.set(`pref.${k}`, v),
    },
    secureTokenStore: {
      get: async () => {
        const raw = mem.get('tokens');
        return raw ? JSON.parse(raw) : null;
      },
      set: async (t: unknown) => void mem.set('tokens', JSON.stringify(t)),
      clear: async () => void mem.delete('tokens'),
    },
    PREF_KEYS: { disclosureAck: 'disclosure_ack_version', language: 'language', biometric: 'biometric_enabled' },
  };
});

const mem = (jest.requireMock('@/storage/secure') as { __mem: Map<string, string> }).__mem;

/** Start as a returning, signed-in user who has acknowledged the current disclosure. */
function seedSignedIn() {
  const { DISCLOSURE_VERSION } = jest.requireActual('@/config/compliance') as { DISCLOSURE_VERSION: string };
  mem.set('pref.disclosure_ack_version', DISCLOSURE_VERSION);
  mem.set('pref.language', 'en');
  mem.set('tokens', JSON.stringify({ access_token: 'mock-a', refresh_token: 'mock-r' }));
}

jest.setTimeout(30000);

describe('app smoke test (mock mode)', () => {
  beforeEach(() => mem.clear());

  it('requires the disclosure, then lets a guest browse the public registry', async () => {
    const screen = renderRouter('./app', { initialUrl: '/' });

    // 1. Onboarding with mandatory disclosure; Continue is disabled until acknowledged.
    expect(await screen.findByText('Industrial metals, documented.', {}, { timeout: 8000 })).toBeTruthy();
    expect(screen.getByText(/No tokens are being offered or sold/)).toBeTruthy();
    expect(screen.getByText(/does not currently intend to offer tokens to residents/)).toBeTruthy();
    fireEvent.press(screen.getByRole('checkbox'));
    await act(async () => {
      fireEvent.press(screen.getByRole('button', { name: 'Continue' }));
    });

    // 2. Login screen → browse as guest.
    expect(await screen.findByText('Browse public registry', {}, { timeout: 8000 })).toBeTruthy();
    await act(async () => {
      fireEvent.press(screen.getByRole('button', { name: 'Browse public registry' }));
    });

    // 3. Overview lists both programs (from the mock API) with element tiles.
    expect(await screen.findByLabelText(/Copper Powder, element symbol Cu, atomic number 29/, {}, { timeout: 8000 })).toBeTruthy();
    expect(screen.getAllByText('Nickel Wire').length).toBeGreaterThan(0);
  });

  it('renders a passport with pending fields and monospace SHA-256 fingerprints', async () => {
    seedSignedIn();
    const screen = renderRouter('./app', { initialUrl: '/passport/RC-CU-LOT-000001' });
    await waitFor(() => expect(screen.getByText('RC-CU-LOT-000001')).toBeTruthy(), { timeout: 8000 });
    expect(screen.getAllByText('Pending — awaiting verification').length).toBeGreaterThan(0);
    expect(screen.getByText('fe17d073 3d955431 d7ecf698 eebec77f 0bb2265e a83be3e7 bf90cd28 56f5af76')).toBeTruthy();
    expect(screen.getAllByText('Fingerprint pending — document not yet provided').length).toBeGreaterThan(0);
  });

  it('shows wallet, purchase, proof of reserves and redemption as locked', async () => {
    seedSignedIn();
    const screen = renderRouter('./app', { initialUrl: '/assets' });
    await waitFor(() => expect(screen.getByText('Proof of Reserves')).toBeTruthy(), { timeout: 8000 });
    expect(screen.getAllByText('Not yet available — subject to authorization').length).toBeGreaterThanOrEqual(6);
  });
});
