import { useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { useRouter } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { DisclosureBlock } from '@/components/Disclosure';
import { ElementTile } from '@/components/ElementTile';
import { LanguagePicker } from '@/components/LanguagePicker';
import { Screen } from '@/components/Screen';
import { StatusPill } from '@/components/StatusPill';
import { Body, Display, Eyebrow, Heading, Muted } from '@/components/Typography';
import { Button, Card, Checkbox } from '@/components/ui';
import { useAppConfig } from '@/config/ConfigProvider';
import { usePreferences } from '@/config/PreferencesProvider';
import { CLAIM_STATUSES } from '@/api/types';
import { colors, space } from '@/theme/tokens';

/** First-run: language, identity, mandatory disclosure + EU/EEA notice (must be acknowledged). */
export default function Onboarding() {
  const { t } = useTranslation();
  const router = useRouter();
  const { config } = useAppConfig();
  const { acceptDisclosure } = usePreferences();
  const [ack, setAck] = useState(false);
  const [showError, setShowError] = useState(false);
  const [busy, setBusy] = useState(false);

  const onContinue = async () => {
    if (!ack) {
      setShowError(true);
      return;
    }
    setBusy(true);
    await acceptDisclosure();
    setBusy(false);
    router.replace('/');
  };

  return (
    <Screen edges={['top', 'bottom']}>
      <View style={st.langRow}>
        <Eyebrow>{t('onboarding.language')}</Eyebrow>
      </View>
      <LanguagePicker />

      <View style={st.hero}>
        <View style={st.tiles}>
          <ElementTile symbol="Cu" atomicNumber={29} name="Copper Powder" size={84} />
          <ElementTile symbol="Ni" atomicNumber={28} name="Nickel Wire" size={84} />
        </View>
        <Eyebrow style={{ color: colors.copperLight, marginTop: space.xl }}>{t('onboarding.eyebrow')}</Eyebrow>
        <Display style={{ marginTop: space.sm }}>{t('onboarding.title')}</Display>
        <Body style={{ marginTop: space.md, color: colors.nickelLight }}>{t('onboarding.subtitle')}</Body>
      </View>

      <Card style={{ marginBottom: space.lg }}>
        <Heading>{t('onboarding.claimTitle')}</Heading>
        <Muted style={{ marginTop: space.xs, marginBottom: space.md }}>{t('onboarding.claimBody')}</Muted>
        <View style={st.pills}>
          {CLAIM_STATUSES.map((s) => (
            <StatusPill key={s} status={s} />
          ))}
        </View>
      </Card>

      <DisclosureBlock serverDisclosure={config.disclosure} serverEuNotice={config.eu_notice} />

      <View style={{ marginTop: space.xl }}>
        <Checkbox
          checked={ack}
          onChange={(v) => {
            setAck(v);
            if (v) setShowError(false);
          }}
          label={t('disclosure.acknowledge')}
        />
        {showError ? (
          <Muted style={{ color: colors.red, marginTop: space.xs }} accessibilityLiveRegion="assertive">
            {t('disclosure.acknowledgeRequired')}
          </Muted>
        ) : null}
        <Button
          label={t('common.continue')}
          onPress={onContinue}
          loading={busy}
          disabled={!ack}
          style={{ marginTop: space.lg }}
        />
      </View>
    </Screen>
  );
}

const st = StyleSheet.create({
  langRow: { marginBottom: space.sm },
  hero: { paddingVertical: space.xl },
  tiles: { flexDirection: 'row', gap: space.md },
  pills: { flexDirection: 'row', flexWrap: 'wrap', gap: space.sm },
});
