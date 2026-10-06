import Svg, { Circle, Path, Rect } from 'react-native-svg';
import { colors } from '@/theme/tokens';

export type IconName =
  | 'overview'
  | 'programs'
  | 'passport'
  | 'assets'
  | 'account'
  | 'lock'
  | 'document'
  | 'bell'
  | 'support'
  | 'shield'
  | 'chevron'
  | 'copy'
  | 'share'
  | 'external'
  | 'info'
  | 'check'
  | 'globe';

interface Props {
  name: IconName;
  size?: number;
  color?: string;
  strokeWidth?: number;
}

/** Hairline, assay-style icon set (1.5px strokes, square caps). Decorative: hidden from a11y. */
export function Icon({ name, size = 20, color = colors.paper, strokeWidth = 1.5 }: Props) {
  const p = { stroke: color, strokeWidth, fill: 'none', strokeLinecap: 'square' as const, strokeLinejoin: 'miter' as const };
  return (
    <Svg width={size} height={size} viewBox="0 0 24 24" accessible={false} importantForAccessibility="no-hide-descendants">
      {name === 'overview' && (
        <>
          <Rect x={3.5} y={3.5} width={7} height={7} {...p} />
          <Rect x={13.5} y={3.5} width={7} height={7} {...p} />
          <Rect x={3.5} y={13.5} width={7} height={7} {...p} />
          <Path d="M13.5 17h7M17 13.5v7" {...p} />
        </>
      )}
      {name === 'programs' && (
        <>
          <Rect x={3.5} y={3.5} width={17} height={17} {...p} />
          <Path d="M7 7.5h3M8 15.5l2.2-6 2.2 6M8.8 13.5h2.8" {...p} />
        </>
      )}
      {name === 'passport' && (
        <>
          <Rect x={5} y={2.5} width={14} height={19} {...p} />
          <Circle cx={12} cy={10} r={3} {...p} />
          <Path d="M8.5 16.5h7M8.5 19h4" {...p} />
        </>
      )}
      {name === 'assets' && (
        <>
          <Path d="M3.5 8.5h17v12h-17z" {...p} />
          <Path d="M7 8.5V5.5h10v3" {...p} />
          <Path d="M12 12.5v4" {...p} />
        </>
      )}
      {name === 'account' && (
        <>
          <Circle cx={12} cy={8.5} r={4} {...p} />
          <Path d="M4.5 20.5c1.2-4 4-5.5 7.5-5.5s6.3 1.5 7.5 5.5" {...p} />
        </>
      )}
      {name === 'lock' && (
        <>
          <Rect x={5} y={10.5} width={14} height={10} {...p} />
          <Path d="M8 10.5V7.5a4 4 0 0 1 8 0v3M12 14.5v2.5" {...p} />
        </>
      )}
      {name === 'document' && (
        <>
          <Path d="M6 2.5h8.5L18.5 6.5v15H6z" {...p} />
          <Path d="M14 2.5v4.5h4.5M9 12h6M9 15h6M9 18h4" {...p} />
        </>
      )}
      {name === 'bell' && (
        <>
          <Path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 2h-15z" {...p} />
          <Path d="M10 21h4" {...p} />
        </>
      )}
      {name === 'support' && (
        <>
          <Path d="M3.5 5.5h17v11h-9l-4.5 3.5v-3.5h-3.5z" {...p} />
          <Path d="M8 9.5h8M8 12.5h5" {...p} />
        </>
      )}
      {name === 'shield' && (
        <>
          <Path d="M12 2.5l7.5 3v6c0 4.6-3.2 8.3-7.5 10-4.3-1.7-7.5-5.4-7.5-10v-6z" {...p} />
          <Path d="M8.8 12l2.2 2.2 4.2-4.4" {...p} />
        </>
      )}
      {name === 'chevron' && <Path d="M9 5.5l6.5 6.5L9 18.5" {...p} />}
      {name === 'copy' && (
        <>
          <Rect x={8.5} y={8.5} width={12} height={12} {...p} />
          <Path d="M15.5 8.5V3.5h-12v12h5" {...p} />
        </>
      )}
      {name === 'share' && (
        <>
          <Path d="M12 3v12M7.5 7.5L12 3l4.5 4.5" {...p} />
          <Path d="M5 12.5v8h14v-8" {...p} />
        </>
      )}
      {name === 'external' && (
        <>
          <Path d="M13.5 3.5h7v7M20.5 3.5l-9 9" {...p} />
          <Path d="M18 14v6.5H3.5V6H10" {...p} />
        </>
      )}
      {name === 'info' && (
        <>
          <Circle cx={12} cy={12} r={9} {...p} />
          <Path d="M12 10.5v6M12 7v1" {...p} />
        </>
      )}
      {name === 'check' && <Path d="M5 12.5l4.5 4.5L19 7.5" {...p} />}
      {name === 'globe' && (
        <>
          <Circle cx={12} cy={12} r={9} {...p} />
          <Path d="M3 12h18M12 3c2.6 2.6 3.8 5.6 3.8 9s-1.2 6.4-3.8 9c-2.6-2.6-3.8-5.6-3.8-9S9.4 5.6 12 3z" {...p} />
        </>
      )}
    </Svg>
  );
}
