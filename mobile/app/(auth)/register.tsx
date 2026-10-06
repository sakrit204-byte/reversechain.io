import { useState } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, StyleSheet, View } from 'react-native';
import { useRouter } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/api';
import type { EntityType } from '@/api/types';
import { useSession } from '@/auth/SessionProvider';
import { isCountryCode, isValidEmail, meetsPasswordPolicy } from '@/auth/validation';
import { Screen } from '@/components/Screen';
import { Body, Display, Eyebrow, Muted } from '@/components/Typography';
import { Button, Card, Checkbox, TextField } from '@/components/ui';
import { isEuEea } from '@/config/compliance';
import { colors, radius, space } from '@/theme/tokens';

type Errors = Partial<Record<'name' | 'email' | 'password' | 'country' | 'form', string>>;

export default function Register() {
  const { t } = useTranslation();
  const router = useRouter();
  const { register } = useSession();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [country, setCountry] = useState('');
  const [entityType, setEntityType] = useState<EntityType>('individual');
  const [acceptDisclosure, setAcceptDisclosure] = useState(false);
  const [acceptTerms, setAcceptTerms] = useState(false);
  const [errors, setErrors] = useState<Errors>({});
  const [busy, setBusy] = useState(false);

  const euResident = isCountryCode(country) && isEuEea(country);

  const submit = async () => {
    const e: Errors = {};
    if (!name.trim()) e.name = t('auth.required');
    if (!isValidEmail(email)) e.email = t('auth.invalidEmail');
    if (!meetsPasswordPolicy(password)) e.password = t('auth.passwordPolicy');
    if (!isCountryCode(country)) e.country = t('auth.countryInvalid');
    if (!acceptDisclosure || !acceptTerms) e.form = t('disclosure.acknowledgeRequired');
    setErrors(e);
    if (Object.keys(e).length) return;
    setBusy(true);
    try {
      await register({
        name: name.trim(),
        email: email.trim(),
        password,
        country: country.trim().toUpperCase(),
        entity_type: entityType,
        accept_disclosure: true,
        accept_terms: true,
      });
      router.replace({ pathname: '/login', params: { registered: '1' } });
    } catch (err) {
      const f = err instanceof ApiError ? err.fields : null;
      setErrors({
        email: f?.email,
        password: f?.password,
        name: f?.name,
        country: f?.country,
        form: err instanceof ApiError && err.status === 0 ? t('common.networkError') : t('common.errorGeneric'),
      });
    } finally {
      setBusy(false);
    }
  };

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Screen edges={['top', 'bottom']}>
        <Button label={t('common.back')} variant="ghost" onPress={() => router.back()} style={st.back} />
        <Display>{t('auth.registerTitle')}</Display>
        <Muted style={{ marginTop: space.sm, marginBottom: space.xl }}>{t('auth.registerSubtitle')}</Muted>

        <Eyebrow style={{ marginBottom: space.sm }}>{t('auth.entityType')}</Eyebrow>
        <View style={st.seg} accessibilityRole="radiogroup" accessibilityLabel={t('auth.entityType')}>
          {(['individual', 'institution'] as const).map((v) => {
            const on = entityType === v;
            return (
              <Pressable
                key={v}
                accessibilityRole="radio"
                accessibilityState={{ selected: on, checked: on }}
                onPress={() => setEntityType(v)}
                style={[st.segItem, on && { backgroundColor: colors.nickelLight }]}
              >
                <Body style={{ color: on ? colors.ink : colors.paper, fontSize: 14 }}>{t(`auth.${v}`)}</Body>
              </Pressable>
            );
          })}
        </View>

        <TextField label={t('auth.name')} value={name} onChangeText={setName} autoComplete="name" error={errors.name} />
        <TextField
          label={t('auth.email')}
          value={email}
          onChangeText={setEmail}
          autoCapitalize="none"
          keyboardType="email-address"
          autoComplete="email"
          error={errors.email}
        />
        <TextField
          label={t('auth.password')}
          value={password}
          onChangeText={setPassword}
          secureTextEntry
          autoComplete="new-password"
          textContentType="newPassword"
          hint={t('auth.passwordHint')}
          error={errors.password}
        />
        <TextField
          label={t('auth.country')}
          value={country}
          onChangeText={(v) => setCountry(v.toUpperCase().slice(0, 2))}
          autoCapitalize="characters"
          maxLength={2}
          error={errors.country}
        />

        {euResident ? (
          <Card accent={colors.amber} style={{ marginBottom: space.lg }}>
            <Body style={{ fontSize: 14 }} accessibilityLiveRegion="polite">
              {t('account.euResident')} {t('disclosure.euBody')}
            </Body>
          </Card>
        ) : null}

        <Checkbox checked={acceptDisclosure} onChange={setAcceptDisclosure} label={t('auth.consent')} />
        <Checkbox checked={acceptTerms} onChange={setAcceptTerms} label={t('auth.acceptTerms')} />
        <Button
          label={t('disclosure.readAgain')}
          variant="ghost"
          icon="shield"
          onPress={() => router.push('/disclosure')}
          style={{ alignSelf: 'flex-start', paddingHorizontal: 0 }}
        />
        {errors.form ? (
          <Muted style={{ color: colors.red, marginTop: space.sm }} accessibilityLiveRegion="assertive">
            {errors.form}
          </Muted>
        ) : null}
        <Button label={t('auth.register')} onPress={submit} loading={busy} style={{ marginTop: space.lg }} />
        <Button
          label={t('auth.haveAccount')}
          variant="ghost"
          onPress={() => router.replace('/login')}
          style={{ marginTop: space.sm }}
        />
      </Screen>
    </KeyboardAvoidingView>
  );
}

const st = StyleSheet.create({
  back: { alignSelf: 'flex-start', paddingHorizontal: 0, marginBottom: space.md },
  seg: {
    flexDirection: 'row',
    borderWidth: 1,
    borderColor: colors.line,
    borderRadius: radius.sm,
    overflow: 'hidden',
    marginBottom: space.lg,
  },
  segItem: { flex: 1, alignItems: 'center', justifyContent: 'center', minHeight: 44 },
});
