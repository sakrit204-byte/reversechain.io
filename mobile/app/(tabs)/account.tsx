import { useEffect, useState } from 'react';
import { StyleSheet, Switch, View } from 'react-native';
import Constants from 'expo-constants';
import { useRouter } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { authenticateBiometric, isBiometricAvailable } from '@/auth/biometrics';
import { useSession } from '@/auth/SessionProvider';
import { LanguagePicker } from '@/components/LanguagePicker';
import { Screen } from '@/components/Screen';
import { EligibilityPill } from '@/components/StatusPill';
import { Body, Display, Heading, Mono, Muted } from '@/components/Typography';
import { Button, Card, Divider, ListRow, Section } from '@/components/ui';
import { isEuEea } from '@/config/compliance';
import { env } from '@/config/env';
import { usePreferences } from '@/config/PreferencesProvider';
import { eligibilityColor } from '@/theme/status';
import { colors, fonts, space } from '@/theme/tokens';

export default function Account() {
  const { t } = useTranslation();
  const router = useRouter();
  const { status, me, logout, refreshMe } = useSession();
  const { biometricEnabled, setBiometricEnabled } = usePreferences();
  const [bioAvailable, setBioAvailable] = useState(false);
  const [refreshing, setRefreshing] = useState(false);

  useEffect(() => {
    void isBiometricAvailable().then(setBioAvailable);
  }, []);

  const toggleBiometric = async (v: boolean) => {
    // Confirm with a biometric prompt before enabling, so users can't lock themselves out.
    if (v && !(await authenticateBiometric(t('lock.prompt'), t('common.cancel')))) return;
    await setBiometricEnabled(v);
  };

  const signedIn = status === 'signedIn' && me;
  const e = me?.eligibility;
  const overallColor =
    e?.overall === 'eligible_subject_to_final_approval'
      ? eligibilityColor('approved')
      : e?.overall === 'restricted'
        ? eligibilityColor('restricted')
        : eligibilityColor('pending');

  return (
    <Screen
      refreshing={refreshing}
      onRefresh={
        signedIn
          ? async () => {
              setRefreshing(true);
              await refreshMe().catch(() => undefined);
              setRefreshing(false);
            }
          : undefined
      }
    >
      <Display>{t('account.title')}</Display>

      {signedIn && me && e ? (
        <>
          <Section title={t('account.profile')}>
            <Card>
              <Heading>{me.name}</Heading>
              <Mono style={{ marginTop: space.xs }}>{me.email}</Mono>
              <Divider />
              <View style={st.kv}>
                <Muted>{t('account.country')}</Muted>
                <Mono>{me.country ?? t('common.notProvided')}</Mono>
              </View>
              {me.entity_type ? (
                <View style={st.kv}>
                  <Muted>{t('auth.entityType')}</Muted>
                  <Body style={{ fontSize: 14 }}>
                    {me.entity_type === 'institution' ? t('auth.institution') : t('auth.individual')}
                  </Body>
                </View>
              ) : null}
              {me.country && isEuEea(me.country) ? (
                <Muted style={{ marginTop: space.sm, color: colors.amber }}>
                  {t('account.euResident')} {t('disclosure.euBody')}
                </Muted>
              ) : null}
            </Card>
          </Section>

          <Section title={t('eligibility.title')}>
            <Card>
              {e.overall ? (
                <View style={[st.overall, { borderColor: overallColor }]}>
                  <Muted>{t('eligibility.overall')}</Muted>
                  <Body style={{ color: overallColor, fontFamily: fonts.uiSemiBold }}>
                    {t(`eligibility.overallValues.${e.overall}`)}
                  </Body>
                </View>
              ) : null}
              <EligibilityPill label={t('eligibility.kyc')} value={e.kyc} />
              <EligibilityPill label={t('eligibility.kyb')} value={e.kyb} />
              <EligibilityPill label={t('eligibility.aml')} value={e.aml} />
              <EligibilityPill label={t('eligibility.sanctions')} value={e.sanctions} />
              <EligibilityPill label={t('eligibility.jurisdiction')} value={e.jurisdiction} />
              {e.note ? <Muted style={{ marginTop: space.md }}>{e.note}</Muted> : null}
              <Muted style={{ marginTop: space.md }}>{t('eligibility.note')}</Muted>
            </Card>
          </Section>

          <Section title={t('security.title')}>
            <Card style={{ padding: 0 }}>
              <ListRow
                icon="shield"
                label={t('security.mfa')}
                value={me.mfa_enabled ? t('security.mfaOn') : t('security.mfaOff')}
                onPress={me.mfa_enabled ? undefined : () => router.push('/mfa-setup')}
              />
              <View style={st.switchRow}>
                <View style={{ flex: 1 }}>
                  <Body>{t('security.biometric')}</Body>
                  <Muted>{bioAvailable ? t('security.biometricHint') : t('security.biometricUnavailable')}</Muted>
                </View>
                <Switch
                  accessibilityLabel={t('security.biometric')}
                  value={biometricEnabled}
                  disabled={!bioAvailable}
                  onValueChange={(v) => void toggleBiometric(v)}
                  trackColor={{ true: colors.copper, false: colors.line }}
                  thumbColor={colors.paper}
                />
              </View>
              <Muted style={{ paddingHorizontal: space.lg, paddingBottom: space.md }}>
                {t('security.autoLogout', { minutes: env.inactivityMinutes })}
              </Muted>
            </Card>
          </Section>
        </>
      ) : (
        <Card style={{ marginTop: space.xl }}>
          <Heading>{t('account.guestTitle')}</Heading>
          <Muted style={{ marginTop: space.xs }}>{t('account.guestBody')}</Muted>
          <Button label={t('common.signIn')} onPress={() => router.push('/login')} style={{ marginTop: space.lg }} />
        </Card>
      )}

      <Section title={t('account.language')}>
        <LanguagePicker />
      </Section>

      <Section title={t('account.library')}>
        <Card style={{ padding: 0 }}>
          <ListRow icon="document" label={t('account.documents')} onPress={() => router.push('/documents')} />
          {signedIn ? (
            <>
              <ListRow icon="bell" label={t('account.notifications')} onPress={() => router.push('/notifications')} />
              <ListRow icon="support" label={t('account.support')} onPress={() => router.push('/support')} />
            </>
          ) : null}
          <ListRow icon="shield" label={t('account.disclosure')} onPress={() => router.push('/disclosure')} />
        </Card>
      </Section>

      {signedIn ? (
        <>
          <Button label={t('common.signOut')} variant="danger" onPress={() => void logout('user')} style={{ marginTop: space.xl }} />
          <Section title={t('deleteAccount.dangerZone')}>
            <Card accent={colors.red} style={{ padding: 0 }}>
              <ListRow icon="shield" label={t('deleteAccount.title')} onPress={() => router.push('/delete-account')} />
              <Muted style={{ paddingHorizontal: space.lg, paddingBottom: space.md }}>{t('deleteAccount.entryHint')}</Muted>
            </Card>
          </Section>
        </>
      ) : null}

      <View style={{ marginTop: space.xl, alignItems: 'center' }}>
        <Muted style={{ fontSize: 11 }}>{t('account.version', { version: Constants.expoConfig?.version ?? '—' })}</Muted>
        <Mono style={{ fontSize: 10, color: colors.muted }}>
          {env.mock ? t('common.mockBadge') : t('account.environment', { url: env.apiUrl })}
        </Mono>
      </View>
    </Screen>
  );
}

const st = StyleSheet.create({
  kv: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: space.xs, gap: space.md },
  switchRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.md,
    paddingHorizontal: space.lg,
    paddingVertical: space.md,
    borderTopWidth: StyleSheet.hairlineWidth * 2,
    borderTopColor: colors.line,
  },
  overall: { borderLeftWidth: 2, paddingLeft: space.md, marginBottom: space.sm },
});
