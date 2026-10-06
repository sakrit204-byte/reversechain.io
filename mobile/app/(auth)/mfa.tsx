import { useState } from 'react';
import { KeyboardAvoidingView, Platform } from 'react-native';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/api';
import { useSession } from '@/auth/SessionProvider';
import { isMfaCode } from '@/auth/validation';
import { Icon } from '@/components/Icon';
import { Screen } from '@/components/Screen';
import { Display, Muted } from '@/components/Typography';
import { Button, TextField } from '@/components/ui';
import { colors, space } from '@/theme/tokens';

/** TOTP code entry after password login (also accepts a 10-character recovery code). */
export default function Mfa() {
  const { t } = useTranslation();
  const router = useRouter();
  const { token } = useLocalSearchParams<{ token?: string }>();
  const { verifyMfa } = useSession();
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    const c = code.replace(/\s+/g, '');
    if (!isMfaCode(c)) return setError(t('mfa.invalidCode'));
    if (!token) return router.replace('/login');
    setError(null);
    setBusy(true);
    try {
      await verifyMfa(token, c);
      // Session guard navigates once signed in.
    } catch (e) {
      setError(
        e instanceof ApiError && e.status === 0
          ? t('common.networkError')
          : e instanceof ApiError && e.status === 401 && e.code !== 'rc_invalid_code'
            ? t('common.sessionExpired')
            : t('mfa.invalidCode'),
      );
      setCode('');
    } finally {
      setBusy(false);
    }
  };

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Screen edges={['top', 'bottom']}>
        <Icon name="shield" size={36} color={colors.copperLight} />
        <Display style={{ marginTop: space.lg }}>{t('mfa.title')}</Display>
        <Muted style={{ marginTop: space.sm, marginBottom: space.xl }}>{t('mfa.subtitle')}</Muted>
        <TextField
          label={t('mfa.code')}
          accessibilityLabel={t('mfa.codeA11y')}
          value={code}
          onChangeText={(v) => setCode(v.replace(/[^A-Za-z0-9]/g, '').slice(0, 10))}
          keyboardType={Platform.OS === 'ios' ? 'number-pad' : 'visible-password'}
          autoComplete="one-time-code"
          textContentType="oneTimeCode"
          autoCapitalize="characters"
          autoFocus
          mono
          maxLength={10}
          onSubmitEditing={submit}
          error={error}
          hint={t('mfa.recoveryHint')}
          style={{ fontSize: 24, textAlign: 'center' }}
        />
        <Button label={t('mfa.verify')} onPress={submit} loading={busy} />
        <Button label={t('common.cancel')} variant="ghost" onPress={() => router.replace('/login')} style={{ marginTop: space.sm }} />
      </Screen>
    </KeyboardAvoidingView>
  );
}
