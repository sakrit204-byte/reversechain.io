import { Platform } from 'react-native';
import { env } from '@/config/env';
import { secureTokenStore } from '@/storage/secure';
import i18n from '@/i18n';
import { createHttpApi, type ReserveChainApi } from './client';
import { createMockApi } from './mock';

type AuthFailureListener = () => void;
const listeners = new Set<AuthFailureListener>();

/** Subscribe to "refresh failed — session is gone" events (used by the session provider). */
export function onAuthFailure(fn: AuthFailureListener): () => void {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

/** Singleton API used by the app. MOCK mode is selected at build time via EXPO_PUBLIC_API_MOCK. */
export const api: ReserveChainApi = env.mock
  ? createMockApi({ tokenStore: secureTokenStore })
  : createHttpApi({
      baseUrl: env.apiUrl,
      tokenStore: secureTokenStore,
      client: Platform.OS === 'ios' ? 'ios' : Platform.OS === 'android' ? 'android' : 'web',
      getLanguage: () => i18n.language,
      onAuthFailure: () => listeners.forEach((l) => l()),
    });

export { ApiError } from './client';
export type { ReserveChainApi } from './client';
