import { useState } from 'react';
import { KeyboardAvoidingView, Platform, StyleSheet, View } from 'react-native';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/api';
import { useSession } from '@/auth/SessionProvider';
import { isValidEmail } from '@/auth/validation';
import { DisclosureStrip } from '@/components/Disclosure';
import { ElementTile } from '@/components/ElementTile';
import { Screen } from '@/components/Screen';
import { Body, Display, Muted } from '@/components/Typography';
import { Button, Card, TextField } from '@/components/ui';
import { env } from '@/config/env';
import { colors, fonts, space } from '@/theme/tokens';

export default function Login() {
  const { t } = useTranslation();
  const router = useRouter();
  const params = useLocalSearchParams<{ registered?: string }>();
  const { login, setGuest, logoutReason } = useSession();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const notice =
    params.registered === '1'
      ? t('auth.registered')
      : logoutReason === 'inactivity'
        ? t('common.loggedOutInactivity')
        : logoutReason === 'expired'
          ? t('common.sessionExpired')
          : logoutReason === 'deleted'
            ? t('deleteAccount.done')
            : null;

  const submit = async () => {
    setError(null);
    if (!isValidEmail(email)) return setError(t('auth.invalidEmail'));
    if (!password) return setError(t('auth.required'));
    setBusy(true);
    try {
      const { mfaToken } = await login(email, password);
      if (mfaToken) router.push({ pathname: '/mfa', params: { token: mfaToken } });
      // Without MFA the session guard routes to the app.
    } catch (e) {
      setError(
        e instanceof ApiError && (e.status === 401 || e.status === 403)
          ? t('auth.invalidCredentials')
          : e instanceof ApiError && e.status === 0
            ? t('common.networkError')
            : t('common.errorGeneric'),
      );
    } finally {
      setBusy(false);
    }
  };

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Screen edges={['top', 'bottom']}>
        <View style={st.brand}>
          <View style={st.tiles}>
            <ElementTile symbol="Cu" atomicNumber={29} name="Copper Powder" size={44} />
            <ElementTile symbol="Ni" atomicNumber={28} name="Nickel Wire" size={44} />
          </View>
          <Body style={st.wordmark}>{t('common.appName')}</Body>
        </View>
        <Display>{t('auth.loginTitle')}</Display>
        <Muted style={{ marginTop: space.sm, marginBottom: space.xl }}>{t('auth.loginSubtitle')}</Muted>

        {notice ? (
          <Card accent={colors.nickel} style={{ marginBottom: space.lg }}>
            <Body accessibilityLiveRegion="polite">{notice}</Body>
          </Card>
        ) : null}

        <TextField
          label={t('auth.email')}
          value={email}
          onChangeText={setEmail}
          autoCapitalize="none"
          autoComplete="email"
          keyboardType="email-address"
          textContentType="username"
          returnKeyType="next"
        />
        <TextField
          label={t('auth.password')}
          value={password}
          onChangeText={setPassword}
          secureTextEntry
          autoComplete="current-password"
          textContentType="password"
          onSubmitEditing={submit}
          error={error}
        />
        {env.mock ? <Muted style={{ marginBottom: space.md, color: colors.amber }}>{t('auth.mockHint')}</Muted> : null}
        <Button label={t('auth.login')} onPress={submit} loading={busy} />

        <Button label={t('auth.noAccount')} variant="ghost" onPress={() => router.push('/register')} style={{ marginTop: space.sm }} />

        <View style={st.guest}>
          <Button
            label={t('auth.continueAsGuest')}
            variant="secondary"
            icon="passport"
            onPress={() => {
              setGuest(true);
              router.replace('/overview');
            }}
          />
          <Muted style={{ marginTop: space.sm, textAlign: 'center' }}>{t('auth.guestNote')}</Muted>
        </View>

        <View style={{ marginTop: space.xl }}>
          <DisclosureStrip onPress={() => router.push('/disclosure')} />
        </View>
      </Screen>
    </KeyboardAvoidingView>
  );
}

const st = StyleSheet.create({
  brand: { flexDirection: 'row', alignItems: 'center', gap: space.md, marginTop: space.lg, marginBottom: space.xxl },
  tiles: { flexDirection: 'row', gap: 6 },
  wordmark: { fontFamily: fonts.display, fontSize: 20, letterSpacing: 0.2 },
  guest: { marginTop: space.xl, paddingTop: space.xl, borderTopWidth: 1, borderTopColor: colors.line },
});
