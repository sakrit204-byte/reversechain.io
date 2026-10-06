import { StyleSheet, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { toClaimStatus } from '@/api/normalize';
import type { ClaimStatus, EligibilityValue } from '@/api/types';
import { claimStatusColor, eligibilityColor } from '@/theme/status';
import { alpha, colors, fonts, radius, space } from '@/theme/tokens';
import { Body } from './Typography';

function Pill({ label, color, a11y }: { label: string; color: string; a11y: string }) {
  return (
    <View
      accessible
      accessibilityRole="text"
      accessibilityLabel={a11y}
      style={[st.pill, { borderColor: alpha(color, 0.45), backgroundColor: alpha(color, 0.1) }]}
    >
      <View style={[st.dot, { backgroundColor: color }]} />
      <Body style={[st.label, { color }]} numberOfLines={1}>
        {label}
      </Body>
    </View>
  );
}


/** Claim Status System pill. Unknown values degrade to "proposed" — never upgrade a claim. */
export function StatusPill({ status }: { status: ClaimStatus | string }) {
  const { t } = useTranslation();
  const st0 = toClaimStatus(status);
  const label = t(`status.${st0}`);
  return <Pill label={label} color={claimStatusColor[st0]} a11y={t('status.a11y', { status: label })} />;
}

export function EligibilityPill({ label, value }: { label: string; value: EligibilityValue }) {
  const { t } = useTranslation();
  const v = t(`eligibility.values.${value}`, { defaultValue: value });
  return (
    <View style={st.eligRow}>
      <Body style={st.eligLabel}>{label}</Body>
      <Pill label={v} color={eligibilityColor(value)} a11y={`${label}: ${v}`} />
    </View>
  );
}

const st = StyleSheet.create({
  pill: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: 6,
    borderWidth: 1,
    borderRadius: radius.pill,
    paddingHorizontal: space.sm + 2,
    paddingVertical: 3,
  },
  dot: { width: 6, height: 6, borderRadius: 3 },
  label: { fontFamily: fonts.uiMedium, fontSize: 12, lineHeight: 16 },
  eligRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: space.sm + 2,
    borderBottomWidth: StyleSheet.hairlineWidth * 2,
    borderBottomColor: colors.line,
  },
  eligLabel: { fontFamily: fonts.mono, fontSize: 13, color: colors.nickelLight, letterSpacing: 0.5 },
});
