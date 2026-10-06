/**
 * Build-time environment. Expo inlines EXPO_PUBLIC_* variables at bundle time, so they must be
 * referenced with static `process.env.EXPO_PUBLIC_X` expressions (no dynamic lookups).
 */
const DEFAULT_API_URL = 'http://localhost:8080/wp-json/rc/v1';

const flag = (v: string | undefined): boolean => v === '1' || v?.toLowerCase() === 'true';

const intOr = (v: string | undefined, fallback: number): number => {
  const n = Number.parseInt(v ?? '', 10);
  return Number.isFinite(n) && n > 0 ? n : fallback;
};

export const env = {
  apiUrl: (process.env.EXPO_PUBLIC_API_URL || DEFAULT_API_URL).replace(/\/+$/, ''),
  mock: flag(process.env.EXPO_PUBLIC_API_MOCK),
  inactivityMinutes: intOr(process.env.EXPO_PUBLIC_INACTIVITY_MINUTES, 5),
  /**
   * Store-screenshot builds ONLY: hides the amber MOCK DATA banner. Default off, so every normal mock
   * build still shows it. Has no effect unless `mock` is also on (never hides anything in a live build).
   */
  hideMockBanner: flag(process.env.EXPO_PUBLIC_HIDE_MOCK_BANNER),
} as const;
