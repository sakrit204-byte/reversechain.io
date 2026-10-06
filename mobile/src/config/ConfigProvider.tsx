import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { api } from '@/api';
import type { AppConfig } from '@/api/types';
import { DISCLOSURE_EN, EU_NOTICE_EN } from './compliance';
import { isFeatureActive, type GatedFeature } from './featureFlags';

/**
 * Fail-closed default used until /config loads (or when it cannot be loaded):
 * every gated module is OFF.
 */
export const FAIL_CLOSED_CONFIG: AppConfig = {
  site_mode: 'unknown',
  modules: {},
  languages: ['en', 'es', 'it'],
  disclosure: DISCLOSURE_EN,
  eu_notice: EU_NOTICE_EN,
  network: null,
};

interface ConfigState {
  config: AppConfig;
  loaded: boolean;
  error: unknown;
  reload: () => Promise<void>;
  isActive: (feature: GatedFeature) => boolean;
}

const Ctx = createContext<ConfigState | null>(null);

export function ConfigProvider({ children }: { children: ReactNode }) {
  const [config, setConfig] = useState<AppConfig>(FAIL_CLOSED_CONFIG);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState<unknown>(null);

  const apply = useCallback((c: AppConfig | null, e: unknown) => {
    // Any failure keeps (or resets to) the fail-closed configuration.
    setConfig(c ? { ...FAIL_CLOSED_CONFIG, ...c, modules: c.modules ?? {} } : FAIL_CLOSED_CONFIG);
    setError(e);
    setLoaded(true);
  }, []);

  const reload = useCallback(
    () =>
      api.getConfig().then(
        (c) => apply(c, null),
        (e: unknown) => apply(null, e),
      ),
    [apply],
  );

  useEffect(() => {
    let alive = true;
    api.getConfig().then(
      (c) => alive && apply(c, null),
      (e: unknown) => alive && apply(null, e),
    );
    return () => {
      alive = false;
    };
  }, [apply]);

  const value = useMemo<ConfigState>(
    () => ({ config, loaded, error, reload, isActive: (f) => isFeatureActive(config, f) }),
    [config, loaded, error, reload],
  );
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useAppConfig(): ConfigState {
  const v = useContext(Ctx);
  if (!v) throw new Error('useAppConfig must be used inside <ConfigProvider>');
  return v;
}
