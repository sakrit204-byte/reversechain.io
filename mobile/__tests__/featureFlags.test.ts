import type { TokenStore } from '@/api/client';
import { createMockApi } from '@/api/mock';
import type { AppConfig } from '@/api/types';
import { GATED_FEATURES, isFeatureActive, isListActive } from '@/config/featureFlags';

const cfg = (modules: AppConfig['modules']): AppConfig => ({
  site_mode: 'pre_launch',
  modules,
  languages: ['en', 'es', 'it'],
  disclosure: null,
  eu_notice: null,
  network: null,
});

describe('feature-flag gating (SPEC rule 7)', () => {
  it('is fail-closed without config', () => {
    for (const f of GATED_FEATURES) {
      expect(isFeatureActive(null, f)).toBe(false);
      expect(isFeatureActive(undefined, f)).toBe(false);
      expect(isFeatureActive(cfg({}), f)).toBe(false);
    }
  });

  it('requires an explicit boolean true', () => {
    const c = cfg({
      wallet: 'yes' as unknown as boolean,
      purchase: 1 as unknown as boolean,
      redemption: true,
    });
    expect(isFeatureActive(c, 'wallet')).toBe(false);
    expect(isFeatureActive(c, 'purchase')).toBe(false);
    expect(isFeatureActive(c, 'redemption')).toBe(true);
  });

  it('holdings/transactions follow wallet unless a specific flag exists', () => {
    expect(isFeatureActive(cfg({ wallet: true }), 'holdings')).toBe(true);
    expect(isFeatureActive(cfg({ wallet: true, holdings: false }), 'holdings')).toBe(false);
    expect(isFeatureActive(cfg({ wallet: false, transactions: true }), 'transactions')).toBe(true);
  });

  it('lists need both the config flag and the endpoint enabled', () => {
    const on = cfg({ holdings: true });
    expect(isListActive(on, 'holdings', { enabled: false, reason: null, items: [] })).toBe(false);
    expect(isListActive(on, 'holdings', null)).toBe(false);
    expect(isListActive(cfg({}), 'holdings', { enabled: true, reason: null, items: [] })).toBe(false);
    expect(isListActive(on, 'holdings', { enabled: true, reason: null, items: [] })).toBe(true);
  });
});

describe('mock mode never invents data (SPEC rule 1)', () => {
  const store: TokenStore = {
    get: async () => ({ access_token: 'a', refresh_token: 'r' }),
    set: async () => undefined,
    clear: async () => undefined,
  };
  const api = createMockApi({ tokenStore: store, delayMs: 0 });

  it('keeps every gated module off', async () => {
    const c = await api.getConfig();
    for (const f of GATED_FEATURES) expect(isFeatureActive(c, f)).toBe(false);
    expect((await api.getHoldings()).enabled).toBe(false);
    expect((await api.getTransactions()).enabled).toBe(false);
  });

  it('leaves token parameters and asset facts unset', async () => {
    for (const p of await api.getPrograms()) {
      const d = await api.getProgram(p.slug);
      expect(d.token_program?.fields.every((f) => f.value === null)).toBe(true);
      expect(d.fields.every((f) => f.value === null)).toBe(true);
    }
    for (const s of await api.getPassports()) {
      const p = await api.getPassport(s.passport_no);
      expect(p.fields.every((f) => f.value === null)).toBe(true);
      expect(p.documents.every((d) => d.issued_by === null && d.issue_date === null)).toBe(true);
      expect(p.updated_at).toBeNull();
    }
  });
});
