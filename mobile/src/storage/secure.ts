import { Platform } from 'react-native';
import * as SecureStore from 'expo-secure-store';
import type { TokenStore } from '@/api/client';
import type { Tokens } from '@/api/types';

/**
 * Secure key/value storage.
 * - iOS: Keychain (kSecAttrAccessibleWhenUnlockedThisDeviceOnly — not synced to iCloud / not in backups).
 * - Android: EncryptedSharedPreferences backed by the Android Keystore.
 * - Web (demo preview only): tokens are kept in memory and never persisted; non-secret prefs use localStorage.
 */
const isWeb = Platform.OS === 'web';
const memory = new Map<string, string>();

const SECURE_OPTS: SecureStore.SecureStoreOptions = {
  keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
};

export const secureStorage = {
  async get(key: string): Promise<string | null> {
    if (isWeb) return memory.get(key) ?? null;
    try {
      return await SecureStore.getItemAsync(key, SECURE_OPTS);
    } catch {
      return null;
    }
  },
  async set(key: string, value: string): Promise<void> {
    if (isWeb) {
      memory.set(key, value);
      return;
    }
    await SecureStore.setItemAsync(key, value, SECURE_OPTS);
  },
  async remove(key: string): Promise<void> {
    if (isWeb) {
      memory.delete(key);
      return;
    }
    await SecureStore.deleteItemAsync(key, SECURE_OPTS);
  },
};

/** Non-secret preferences (language, disclosure acknowledgement, biometric opt-in). */
export const prefs = {
  async get(key: string): Promise<string | null> {
    if (isWeb) {
      try {
        return globalThis.localStorage?.getItem(`rc.${key}`) ?? null;
      } catch {
        return null;
      }
    }
    return secureStorage.get(`rc.pref.${key}`);
  },
  async set(key: string, value: string): Promise<void> {
    if (isWeb) {
      try {
        globalThis.localStorage?.setItem(`rc.${key}`, value);
      } catch {
        /* storage unavailable (private mode) — ignore */
      }
      return;
    }
    await secureStorage.set(`rc.pref.${key}`, value);
  },
};

const TOKENS_KEY = 'rc.session.tokens';

export const secureTokenStore: TokenStore = {
  async get(): Promise<Tokens | null> {
    const raw = await secureStorage.get(TOKENS_KEY);
    if (!raw) return null;
    try {
      const t = JSON.parse(raw) as Partial<Tokens>;
      return typeof t.access_token === 'string' && typeof t.refresh_token === 'string'
        ? { access_token: t.access_token, refresh_token: t.refresh_token }
        : null;
    } catch {
      return null;
    }
  },
  async set(tokens: Tokens): Promise<void> {
    await secureStorage.set(TOKENS_KEY, JSON.stringify(tokens));
  },
  async clear(): Promise<void> {
    await secureStorage.remove(TOKENS_KEY);
  },
};

export const PREF_KEYS = {
  disclosureAck: 'disclosure_ack_version',
  language: 'language',
  biometric: 'biometric_enabled',
} as const;

/**
 * Wipes everything on this device that belongs to the signed-in account (used after account deletion):
 * session tokens and the biometric-unlock opt-in. Device-level preferences (language, disclosure
 * acknowledgement) are not personal data and are kept.
 */
export async function clearAccountData(): Promise<void> {
  await secureTokenStore.clear();
  await prefs.set(PREF_KEYS.biometric, '0');
}
