import { useState } from 'react';
import { KeyboardAvoidingView, Platform, View } from 'react-native';
import { useRouter } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/api';
import { useSession } from '@/auth/SessionProvider';
import { Screen } from '@/components/Screen';
import { Body, Heading, Muted } from '@/components/Typography';
import { Button, Card, TextField } from '@/components/ui';
import { colors, fonts, space } from '@/theme/tokens';

const CONFIRM_WORD = 'DELETE';

/**
 * Account deletion (App Store guideline 5.1.1(v), Google Play account-deletion policy).
 * Step 1: explain what is erased vs kept, collect password + typed confirmation.
 * Step 2: explicit final confirmation, then POST /me/delete, local sign-out and secure-storage wipe.
 */
export default function DeleteAccount() {
  const { t } = useTranslation();
  const router = useRouter();
  const { deleteAccount } = useSession();
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [errors, setErrors] = useState<{ password?: string; confirm?: string; form?: string }>({});
  const [finalStep, setFinalStep] = useState(false);
  const [busy, setBusy] = useState(false);

  const review = () => {
    const next: typeof errors = {};
    if (!password) next.password = t('deleteAccount.passwordRequired');
    if (confirm.trim() !== CONFIRM_WORD) next.confirm = t('deleteAccount.confirmMismatch');
    setErrors(next);
    if (!next.password && !next.confirm) setFinalStep(true);
  };

  const submit = async () => {
    setBusy(true);
    setErrors({});
    try {
      await deleteAccount(password);
      router.replace('/login');
    } catch (e) {
      const msg =
        e instanceof ApiError && e.code === 'rc_delete_confirm'
          ? t('deleteAccount.wrongCredentials')
          : e instanceof ApiError && e.code === 'rc_delete_staff'
            ? t('deleteAccount.staff')
            : e instanceof ApiError && e.status === 0
              ? t('common.networkError')
              : t('common.errorGeneric');
      setErrors({ form: msg });
      setFinalStep(false);
    } finally {
      setBusy(false);
    }
  };

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Screen edges={['bottom']}>
        <Card accent={colors.red}>
          <Heading>{t('deleteAccount.intro')}</Heading>
          <Body style={{ marginTop: space.md, fontFamily: fonts.uiSemiBold }}>{t('deleteAccount.erasedTitle')}</Body>
          <Muted style={{ marginTop: space.xs }}>{t('deleteAccount.erased')}</Muted>
          <Body style={{ marginTop: space.md, fontFamily: fonts.uiSemiBold }}>{t('deleteAccount.retainedTitle')}</Body>
          <Muted style={{ marginTop: space.xs }}>{t('deleteAccount.retained')}</Muted>
          <Muted style={{ marginTop: space.md }}>{t('deleteAccount.deviceNote')}</Muted>
        </Card>

        <View style={{ marginTop: space.xl }}>
          <TextField
            label={t('deleteAccount.password')}
            value={password}
            onChangeText={(v) => {
              setPassword(v);
              setFinalStep(false);
            }}
            secureTextEntry
            autoComplete="current-password"
            textContentType="password"
            error={errors.password}
            editable={!busy}
          />
          <TextField
            label={t('deleteAccount.confirmLabel')}
            value={confirm}
            onChangeText={(v) => {
              setConfirm(v);
              setFinalStep(false);
            }}
            autoCapitalize="characters"
            autoCorrect={false}
            placeholder={CONFIRM_WORD}
            error={errors.confirm}
            editable={!busy}
          />
        </View>

        {errors.form ? (
          <Muted style={{ color: colors.red, marginBottom: space.md }} accessibilityLiveRegion="assertive">
            {errors.form}
          </Muted>
        ) : null}

        {finalStep ? (
          <Card accent={colors.red} accessibilityLiveRegion="polite">
            <Heading>{t('deleteAccount.finalTitle')}</Heading>
            <Muted style={{ marginTop: space.xs }}>{t('deleteAccount.finalBody')}</Muted>
            <Button
              label={t('deleteAccount.finalConfirm')}
              variant="danger"
              onPress={() => void submit()}
              loading={busy}
              style={{ marginTop: space.lg }}
            />
            <Button
              label={t('common.cancel')}
              variant="ghost"
              onPress={() => setFinalStep(false)}
              disabled={busy}
              style={{ marginTop: space.sm }}
            />
          </Card>
        ) : (
          <Button label={t('deleteAccount.continue')} variant="danger" onPress={review} />
        )}

        <Muted style={{ marginTop: space.xl, fontSize: 12 }}>{t('deleteAccount.webAlternative')}</Muted>
      </Screen>
    </KeyboardAvoidingView>
  );
}
