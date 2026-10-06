import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { AppState, type AppStateStatus } from 'react-native';
import { api, ApiError, onAuthFailure } from '@/api';
import type { DeleteAccountResult, Me, RegisterInput } from '@/api/types';
import { env } from '@/config/env';
import { usePreferences } from '@/config/PreferencesProvider';
import { clearAccountData, secureTokenStore } from '@/storage/secure';

export type SessionStatus = 'loading' | 'signedOut' | 'locked' | 'signedIn';
export type LogoutReason = 'user' | 'inactivity' | 'expired' | 'deleted' | null;

/** Re-lock (biometric) when the app returns from background after this long. */
const RELOCK_AFTER_MS = 30_000;

interface SessionState {
  status: SessionStatus;
  me: Me | null;
  guest: boolean;
  logoutReason: LogoutReason;
  setGuest: (v: boolean) => void;
  login: (email: string, password: string) => Promise<{ mfaToken: string | null }>;
  verifyMfa: (mfaToken: string, code: string) => Promise<void>;
  register: (input: RegisterInput) => Promise<void>;
  logout: (reason?: LogoutReason) => Promise<void>;
  unlock: () => Promise<void>;
  refreshMe: () => Promise<void>;
  /** Deletes the account on the server, then signs out and wipes account data from this device. */
  deleteAccount: (password: string) => Promise<DeleteAccountResult>;
  /** Call on any user interaction to reset the inactivity timer. */
  touch: () => void;
}

const Ctx = createContext<SessionState | null>(null);

export function SessionProvider({ children }: { children: ReactNode }) {
  const { loaded: prefsLoaded, biometricEnabled, setBiometricEnabled, language } = usePreferences();
  const [status, setStatus] = useState<SessionStatus>('loading');
  const [me, setMe] = useState<Me | null>(null);
  const [guest, setGuest] = useState(false);
  const [logoutReason, setLogoutReason] = useState<LogoutReason>(null);
  // Updated on sign-in and on every touch; 0 until a session exists.
  const lastActivity = useRef(0);
  const backgroundedAt = useRef<number | null>(null);
  const statusRef = useRef<SessionStatus>('loading');
  const syncedLanguage = useRef<string | null>(null);
  useEffect(() => {
    statusRef.current = status;
  }, [status]);

  const timeoutMs = env.inactivityMinutes * 60_000;

  const logout = useCallback(async (reason: LogoutReason = 'user') => {
    // Revokes server sessions (best effort) and always clears the secure token store.
    await api.logout();
    setMe(null);
    setGuest(false);
    setLogoutReason(reason);
    setStatus('signedOut');
  }, []);

  const loadMe = useCallback(async () => {
    try {
      const m = await api.getMe();
      setMe(m);
      lastActivity.current = Date.now();
      setLogoutReason(null);
      setStatus('signedIn');
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) {
        await logout('expired');
      } else {
        throw e;
      }
    }
  }, [logout]);

  // Boot: restore session from secure storage once preferences are known.
  useEffect(() => {
    if (!prefsLoaded) return;
    let alive = true;
    (async () => {
      const tokens = await secureTokenStore.get();
      if (!alive) return;
      if (!tokens) {
        setStatus('signedOut');
        return;
      }
      if (biometricEnabled) {
        setStatus('locked');
        return;
      }
      try {
        await loadMe();
      } catch {
        // Offline at boot: keep the stored session but show signed-out UI until retry.
        if (alive) setStatus('signedOut');
      }
    })();
    return () => {
      alive = false;
    };
    // biometricEnabled intentionally read only at boot
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [prefsLoaded]);

  // Refresh failure anywhere in the API client ⇒ sign out.
  useEffect(() => onAuthFailure(() => void logout('expired')), [logout]);

  // Inactivity timer (foreground).
  useEffect(() => {
    const id = setInterval(() => {
      if (statusRef.current === 'signedIn' && Date.now() - lastActivity.current > timeoutMs) {
        void logout('inactivity');
      }
    }, 15_000);
    return () => clearInterval(id);
  }, [logout, timeoutMs]);

  // Background/foreground: enforce inactivity and biometric re-lock.
  useEffect(() => {
    const sub = AppState.addEventListener('change', (next: AppStateStatus) => {
      if (next === 'background' || next === 'inactive') {
        backgroundedAt.current ??= Date.now();
        return;
      }
      if (next === 'active' && backgroundedAt.current !== null) {
        const away = Date.now() - backgroundedAt.current;
        backgroundedAt.current = null;
        if (statusRef.current !== 'signedIn') return;
        if (Date.now() - lastActivity.current > timeoutMs) {
          void logout('inactivity');
        } else if (biometricEnabled && away > RELOCK_AFTER_MS) {
          setStatus('locked');
        }
      }
    });
    return () => sub.remove();
  }, [biometricEnabled, logout, timeoutMs]);

  // Keep the server-side language preference in sync (best effort).
  useEffect(() => {
    if (status !== 'signedIn' || !me) return;
    if (me.language === language || syncedLanguage.current === language) return;
    syncedLanguage.current = language;
    api.updateMe({ language }).catch(() => undefined);
  }, [status, me, language]);

  const login = useCallback(
    async (email: string, password: string) => {
      const r = await api.login(email.trim(), password);
      if (r.mfa_required) return { mfaToken: r.mfa_token };
      await loadMe();
      return { mfaToken: null };
    },
    [loadMe],
  );

  const verifyMfa = useCallback(
    async (mfaToken: string, code: string) => {
      await api.verifyMfa(mfaToken, code);
      await loadMe();
    },
    [loadMe],
  );

  const register = useCallback(async (input: RegisterInput) => {
    await api.register(input);
  }, []);

  const deleteAccount = useCallback(
    async (password: string) => {
      const r = await api.deleteAccount({ password, confirm: 'DELETE' });
      await clearAccountData();
      await setBiometricEnabled(false);
      setMe(null);
      setGuest(false);
      setLogoutReason('deleted');
      setStatus('signedOut');
      return r;
    },
    [setBiometricEnabled],
  );

  const unlock = useCallback(async () => {
    try {
      await loadMe();
    } catch {
      setStatus('signedOut');
    }
  }, [loadMe]);

  const value = useMemo<SessionState>(
    () => ({
      status,
      me,
      guest,
      logoutReason,
      setGuest,
      login,
      verifyMfa,
      register,
      logout,
      unlock,
      refreshMe: loadMe,
      deleteAccount,
      touch: () => {
        lastActivity.current = Date.now();
      },
    }),
    [status, me, guest, logoutReason, login, verifyMfa, register, logout, unlock, loadMe, deleteAccount],
  );

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useSession(): SessionState {
  const v = useContext(Ctx);
  if (!v) throw new Error('useSession must be used inside <SessionProvider>');
  return v;
}
