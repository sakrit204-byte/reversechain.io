/**
 * ReserveChain brand tokens (SPEC.md — "Brand / design tokens").
 * Shared with the web theme; do not introduce colours outside this palette.
 */
export const colors = {
  ink: '#050C14', // = splash / adaptive-icon background (assets/brand), so splash → app has no colour step
  graphite: '#141A22',
  panel: '#1B232D',
  line: '#2A3542',
  paper: '#F5F2EC',
  muted: '#8A96A3',
  copper: '#C46A3A',
  copperLight: '#E39A6B',
  nickel: '#9FB3C2',
  nickelLight: '#C9D6DF',
  green: '#3FB37F',
  amber: '#E0A43A',
  red: '#D9574A',
} as const;

export const fonts = {
  display: 'Fraunces_600SemiBold',
  displayRegular: 'Fraunces_400Regular',
  ui: 'Inter_400Regular',
  uiMedium: 'Inter_500Medium',
  uiSemiBold: 'Inter_600SemiBold',
  mono: 'IBMPlexMono_400Regular',
  monoMedium: 'IBMPlexMono_500Medium',
} as const;

export const space = { xs: 4, sm: 8, md: 12, lg: 16, xl: 24, xxl: 32 } as const;
export const radius = { sm: 4, md: 8, lg: 12, pill: 999 } as const;

/** Translucent helpers built from the palette (hex + alpha). */
export const alpha = (hex: string, a: number): string => {
  const v = Math.round(Math.max(0, Math.min(1, a)) * 255)
    .toString(16)
    .padStart(2, '0');
  return `${hex}${v}`;
};
