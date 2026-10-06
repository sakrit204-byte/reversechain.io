import { StyleSheet, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { GatedFeature } from '@/config/featureFlags';
import { alpha, colors, space } from '@/theme/tokens';
import { Icon } from './Icon';
import { Body, Heading, Muted } from './Typography';
import { Card } from './ui';

/**
 * Locked state for modules that exist but are inactive until authorised (SPEC rule 7).
 * Rendered whenever /config does not explicitly enable the module.
 */
export function LockedFeature({ feature, compact }: { feature: GatedFeature; compact?: boolean }) {
  const { t } = useTranslation();
  const title = t(`assets.features.${feature}`);
  return (
    <Card
      accessible
      accessibilityLabel={`${t('assets.lockedA11y', { feature: title })}. ${t(`assets.descriptions.${feature}`)}`}
      style={st.card}
    >
      <View style={st.head}>
        <View style={st.lockBadge}>
          <Icon name="lock" size={16} color={colors.amber} />
        </View>
        <View style={{ flex: 1 }}>
          <Heading>{title}</Heading>
          <Body style={st.state}>{t('common.notAvailable')}</Body>
        </View>
      </View>
      <Muted style={{ marginTop: space.md }}>{t(`assets.descriptions.${feature}`)}</Muted>
      {!compact ? <Muted style={{ marginTop: space.sm }}>{t('assets.lockedBody')}</Muted> : null}
    </Card>
  );
}

const st = StyleSheet.create({
  card: { borderStyle: 'dashed', marginBottom: space.md },
  head: { flexDirection: 'row', gap: space.md, alignItems: 'center' },
  lockBadge: {
    width: 32,
    height: 32,
    borderRadius: 4,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: alpha(colors.amber, 0.12),
    borderWidth: 1,
    borderColor: alpha(colors.amber, 0.4),
  },
  state: { color: colors.amber, fontSize: 13, lineHeight: 18, marginTop: 2 },
});
