import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import i18n, { detectDeviceLanguage, isSupportedLanguage } from '@/i18n';
import type { LanguageCode } from '@/api/types';
import { PREF_KEYS, prefs } from '@/storage/secure';
import { DISCLOSURE_VERSION } from './compliance';

interface Preferences {
  loaded: boolean;
  disclosureAccepted: boolean;
  acceptDisclosure: () => Promise<void>;
  language: LanguageCode;
  setLanguage: (l: LanguageCode) => Promise<void>;
  biometricEnabled: boolean;
  setBiometricEnabled: (v: boolean) => Promise<void>;
}

const Ctx = createContext<Preferences | null>(null);

export function PreferencesProvider({ children }: { children: ReactNode }) {
  const [loaded, setLoaded] = useState(false);
  const [ackVersion, setAckVersion] = useState<string | null>(null);
  const [language, setLang] = useState<LanguageCode>(detectDeviceLanguage());
  const [biometricEnabled, setBio] = useState(false);

  useEffect(() => {
    let alive = true;
    (async () => {
      const [ack, lang, bio] = await Promise.all([
        prefs.get(PREF_KEYS.disclosureAck),
        prefs.get(PREF_KEYS.language),
        prefs.get(PREF_KEYS.biometric),
      ]);
      if (!alive) return;
      setAckVersion(ack);
      if (isSupportedLanguage(lang)) {
        setLang(lang);
        await i18n.changeLanguage(lang);
      }
      setBio(bio === '1');
      setLoaded(true);
    })();
    return () => {
      alive = false;
    };
  }, []);

  const acceptDisclosure = useCallback(async () => {
    await prefs.set(PREF_KEYS.disclosureAck, DISCLOSURE_VERSION);
    setAckVersion(DISCLOSURE_VERSION);
  }, []);

  const setLanguage = useCallback(async (l: LanguageCode) => {
    setLang(l);
    await i18n.changeLanguage(l);
    await prefs.set(PREF_KEYS.language, l);
  }, []);

  const setBiometricEnabled = useCallback(async (v: boolean) => {
    setBio(v);
    await prefs.set(PREF_KEYS.biometric, v ? '1' : '0');
  }, []);

  const value = useMemo<Preferences>(
    () => ({
      loaded,
      // A changed disclosure (new version) must be acknowledged again.
      disclosureAccepted: ackVersion === DISCLOSURE_VERSION,
      acceptDisclosure,
      language,
      setLanguage,
      biometricEnabled,
      setBiometricEnabled,
    }),
    [loaded, ackVersion, acceptDisclosure, language, setLanguage, biometricEnabled, setBiometricEnabled],
  );
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function usePreferences(): Preferences {
  const v = useContext(Ctx);
  if (!v) throw new Error('usePreferences must be used inside <PreferencesProvider>');
  return v;
}
