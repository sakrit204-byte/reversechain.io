import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import { getLocales } from 'expo-localization';
import type { LanguageCode } from '@/api/types';
import en from './locales/en';
import es from './locales/es';
import it from './locales/it';

export const SUPPORTED_LANGUAGES: readonly LanguageCode[] = ['en', 'es', 'it'];
export const resources = { en, es, it } as const;

export const isSupportedLanguage = (v: unknown): v is LanguageCode =>
  typeof v === 'string' && (SUPPORTED_LANGUAGES as readonly string[]).includes(v);

export function detectDeviceLanguage(): LanguageCode {
  try {
    for (const l of getLocales()) {
      const code = l.languageCode?.toLowerCase();
      if (isSupportedLanguage(code)) return code;
    }
  } catch {
    /* expo-localization unavailable (tests) */
  }
  return 'en';
}

if (!i18n.isInitialized) {
  // eslint-disable-next-line import/no-named-as-default-member
  void i18n.use(initReactI18next).init({
    resources: {
      en: { translation: en },
      es: { translation: es },
      it: { translation: it },
    },
    lng: detectDeviceLanguage(),
    fallbackLng: 'en',
    supportedLngs: [...SUPPORTED_LANGUAGES],
    interpolation: { escapeValue: false },
    returnNull: false,
    initAsync: false,
  });
}

export default i18n;
