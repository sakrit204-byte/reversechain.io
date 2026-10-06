import { StyleSheet, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { alpha, colors, fonts, radius } from '@/theme/tokens';
import { Body } from './Typography';

export const elementColor = (symbol: string) =>
  symbol === 'Cu'
    ? { base: colors.copper, light: colors.copperLight }
    : { base: colors.nickel, light: colors.nickelLight };

/**
 * Periodic-table element tile — the program identity (Cu 29 / Ni 28).
 * Atomic number top-left in mono, symbol in Fraunces, program name along the bottom edge.
 */
export function ElementTile({
  symbol,
  atomicNumber,
  name,
  size = 96,
}: {
  symbol: string;
  atomicNumber: number;
  name: string;
  size?: number;
}) {
  const { t } = useTranslation();
  const c = elementColor(symbol);
  const k = size / 96;
  return (
    <View
      accessible
      accessibilityRole="image"
      accessibilityLabel={t('programs.elementA11y', { name, symbol, number: atomicNumber })}
      style={[
        st.tile,
        {
          width: size,
          height: size,
          borderColor: alpha(c.base, 0.75),
          backgroundColor: alpha(c.base, 0.08),
          padding: 8 * k,
        },
      ]}
    >
      <Body style={{ fontFamily: fonts.mono, fontSize: 11 * k, lineHeight: 13 * k, color: c.light }}>
        {atomicNumber}
      </Body>
      <Body
        style={{
          fontFamily: fonts.display,
          fontSize: 40 * k,
          lineHeight: 46 * k,
          color: c.light,
          textAlign: 'center',
          marginTop: -2 * k,
        }}
      >
        {symbol}
      </Body>
      <Body
        numberOfLines={1}
        adjustsFontSizeToFit
        style={{
          fontFamily: fonts.uiMedium,
          fontSize: 9 * k,
          lineHeight: 11 * k,
          color: colors.muted,
          textAlign: 'center',
          letterSpacing: 0.6,
          textTransform: 'uppercase',
        }}
      >
        {name}
      </Body>
      {/* Corner notch: a nod to assay stamps. */}
      <View style={[st.notch, { borderColor: alpha(c.base, 0.75), width: 10 * k, height: 10 * k }]} />
    </View>
  );
}

const st = StyleSheet.create({
  tile: { borderWidth: 1.5, borderRadius: radius.sm, justifyContent: 'space-between' },
  notch: { position: 'absolute', top: 4, right: 4, borderTopWidth: 1, borderRightWidth: 1 },
});
