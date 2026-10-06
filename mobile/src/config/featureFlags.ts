import type { AppConfig, GatedList, ModuleKey } from '@/api/types';

/**
 * Gated features shown in the app. Each is INACTIVE unless /config explicitly enables it
 * (SPEC rule 7). Gating is fail-closed: missing config, missing key, non-boolean or any error
 * ⇒ inactive.
 */
export type GatedFeature =
  | 'wallet'
  | 'purchase'
  | 'proof_of_reserves'
  | 'redemption'
  | 'holdings'
  | 'transactions';

export const GATED_FEATURES: readonly GatedFeature[] = [
  'holdings',
  'transactions',
  'wallet',
  'purchase',
  'proof_of_reserves',
  'redemption',
];

/**
 * Holdings/transactions have no dedicated module flag in SPEC v1 — they ride on `wallet`
 * unless the backend provides a specific `holdings` / `transactions` flag.
 */
const MODULE_FOR: Record<GatedFeature, ModuleKey[]> = {
  wallet: ['wallet'],
  purchase: ['purchase'],
  proof_of_reserves: ['proof_of_reserves'],
  redemption: ['redemption'],
  holdings: ['holdings', 'wallet'],
  transactions: ['transactions', 'wallet'],
};

export function isModuleEnabled(config: AppConfig | null | undefined, key: ModuleKey): boolean {
  return config?.modules?.[key] === true;
}

export function isFeatureActive(config: AppConfig | null | undefined, feature: GatedFeature): boolean {
  if (!config) return false;
  const keys = MODULE_FOR[feature];
  const [specific, fallback] = keys;
  // A specific flag, when present, wins (lets the backend disable holdings while wallet is on).
  if (specific && config.modules && typeof config.modules[specific] === 'boolean') {
    return config.modules[specific] === true;
  }
  return fallback ? isModuleEnabled(config, fallback) : false;
}

/** Data-level gate: both the config flag AND the endpoint's own `enabled` must be true. */
export function isListActive<T>(
  config: AppConfig | null | undefined,
  feature: 'holdings' | 'transactions',
  list: GatedList<T> | null | undefined,
): boolean {
  return isFeatureActive(config, feature) && list?.enabled === true;
}
