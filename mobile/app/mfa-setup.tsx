import { useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { useRouter } from 'expo-router';
import * as Clipboard from 'expo-clipboard';
import QRCode from 'react-native-qrcode-svg';
import { useTranslation } from 'react-i18next';
import { api, ApiError } from '@/api';
import { useSession } from '@/auth/SessionProvider';
import { isTotp } from '@/auth/validation';
import { Hash } from '@/components/Hash';
import { Screen } from '@/components/Screen';
import { Body, Heading, Mono, Muted } from '@/components/Typography';
import { Button, Card, Checkbox, ErrorState, LoadingState, TextField } from '@/components/ui';
import { useAsync } from '@/hooks/useAsync';
import { colors, radius, space } from '@/theme/tokens';

/** TOTP enrolment: otpauth QR (react-native-qrcode-svg) + manual secret + confirm code + recovery codes. */
export default function MfaSetup() {
  const { t } = useTranslation();
  const router = useRouter();
  const { refreshMe } = useSession();
  const setup = useAsync(() => api.setupMfa(), []);
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [recovery, setRecovery] = useState<string[] | null>(null);
  const [saved, setSaved] = useState(false);

  const enable = async () => {
    if (!isTotp(code)) return setError(t('mfa.invalidCode'));
    setError(null);
    setBusy(true);
    try {
      const r = await api.enableMfa(code);
      setRecovery(r.recovery_codes);
      await refreshMe().catch(() => undefined);
    } catch (e) {
      setError(e instanceof ApiError && e.status === 0 ? t('common.networkError') : t('mfa.invalidCode'));
    } finally {
      setBusy(false);
    }
  };

  if (recovery) {
    return (
      <Screen edges={['bottom']}>
        <Card accent={colors.green}>
          <Heading>{t('mfa.enabled')}</Heading>
        </Card>
        {recovery.length > 0 ? (
          <Card style={{ marginTop: space.lg }}>
            <Heading>{t('mfa.recoveryTitle')}</Heading>
            <Muted style={{ marginTop: space.xs, marginBottom: space.md }}>{t('mfa.recoveryBody')}</Muted>
            <View style={st.codes}>
              {recovery.map((c) => (
                <Mono key={c} style={st.code}>
                  {c}
                </Mono>
              ))}
            </View>
            <Button
              label={t('mfa.copyCodes')}
              variant="secondary"
              icon="copy"
              onPress={() => void Clipboard.setStringAsync(recovery.join('\n'))}
              style={{ marginTop: space.md }}
            />
            <Checkbox checked={saved} onChange={setSaved} label={t('mfa.recoverySaved')} />
          </Card>
        ) : null}
        <Button
          label={t('common.close')}
          onPress={() => router.back()}
          disabled={recovery.length > 0 && !saved}
          style={{ marginTop: space.lg }}
        />
      </Screen>
    );
  }

  return (
    <Screen edges={['bottom']}>
      <Body style={{ color: colors.nickelLight }}>{t('mfa.setupIntro')}</Body>
      {setup.loading ? (
        <LoadingState />
      ) : setup.error || !setup.data ? (
        <ErrorState error={setup.error} onRetry={setup.reload} />
      ) : (
        <>
          <Card style={{ marginTop: space.lg, alignItems: 'center' }}>
            <Body style={{ alignSelf: 'stretch', marginBottom: space.md }}>{t('mfa.step1')}</Body>
            <View style={st.qr} accessible accessibilityRole="image" accessibilityLabel={t('mfa.qrA11y')}>
              <QRCode value={setup.data.otpauth_url} size={200} color={colors.ink} backgroundColor={colors.paper} />
            </View>
          </Card>
          <Card style={{ marginTop: space.md }}>
            <Body style={{ marginBottom: space.md }}>{t('mfa.step2')}</Body>
            <Hash value={setup.data.secret} a11yLabel={t('mfa.secret')} emptyLabel={t('common.pending')} />
          </Card>
          <Card style={{ marginTop: space.md }}>
            <Body style={{ marginBottom: space.md }}>{t('mfa.step3')}</Body>
            <TextField
              label={t('mfa.code')}
              accessibilityLabel={t('mfa.codeA11y')}
              value={code}
              onChangeText={(v) => setCode(v.replace(/\D/g, '').slice(0, 6))}
              keyboardType="number-pad"
              textContentType="oneTimeCode"
              mono
              maxLength={6}
              error={error}
              style={{ fontSize: 22, textAlign: 'center' }}
            />
            <Button label={t('mfa.enable')} onPress={enable} loading={busy} />
          </Card>
        </>
      )}
    </Screen>
  );
}

const st = StyleSheet.create({
  qr: { padding: space.md, backgroundColor: colors.paper, borderRadius: radius.sm },
  codes: { flexDirection: 'row', flexWrap: 'wrap', gap: space.sm },
  code: {
    width: '47%',
    fontSize: 15,
    textAlign: 'center',
    paddingVertical: space.sm,
    backgroundColor: colors.ink,
    borderWidth: 1,
    borderColor: colors.line,
    borderRadius: radius.sm,
    color: colors.paper,
  },
});
