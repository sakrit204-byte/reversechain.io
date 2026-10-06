import { Text, type TextProps, type TextStyle } from 'react-native';
import { colors, fonts } from '@/theme/tokens';

type P = TextProps & { color?: string; style?: TextProps['style'] };

function make(base: TextStyle, defaults: Partial<TextProps> = {}) {
  function Typography({ color, style, ...rest }: P) {
    return <Text {...defaults} {...rest} style={[base, color ? { color } : null, style]} />;
  }
  return Typography;
}

/** Fraunces display heading. */
export const Display = make(
  { fontFamily: fonts.display, fontSize: 32, lineHeight: 38, color: colors.paper, letterSpacing: -0.4 },
  { accessibilityRole: 'header' },
);
export const Title = make(
  { fontFamily: fonts.display, fontSize: 22, lineHeight: 28, color: colors.paper, letterSpacing: -0.2 },
  { accessibilityRole: 'header' },
);
export const Heading = make(
  { fontFamily: fonts.uiSemiBold, fontSize: 16, lineHeight: 22, color: colors.paper },
  { accessibilityRole: 'header' },
);
export const Body = make({ fontFamily: fonts.ui, fontSize: 15, lineHeight: 22, color: colors.paper });
export const Muted = make({ fontFamily: fonts.ui, fontSize: 13, lineHeight: 19, color: colors.muted });
/** Small uppercase label with tracking — used as section eyebrows. */
export const Eyebrow = make({
  fontFamily: fonts.uiMedium,
  fontSize: 11,
  lineHeight: 14,
  color: colors.muted,
  letterSpacing: 1.4,
  textTransform: 'uppercase',
});
/** IBM Plex Mono for hashes, IDs and data. Selectable so users can copy and compare. */
export const Mono = make(
  { fontFamily: fonts.mono, fontSize: 12, lineHeight: 18, color: colors.nickelLight, letterSpacing: 0.2 },
  { selectable: true },
);
