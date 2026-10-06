import { forwardRef, type ReactNode } from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  TextInput,
  View,
  type TextInputProps,
  type ViewProps,
  type ViewStyle,
} from 'react-native';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/api/client';
import { alpha, colors, fonts, radius, space } from '@/theme/tokens';
import { Icon, type IconName } from './Icon';
import { Body, Eyebrow, Heading, Muted } from './Typography';

export function Card({ style, accent, ...rest }: ViewProps & { accent?: string }) {
  return (
    <View
      {...rest}
      style={[s.card, accent ? { borderLeftColor: accent, borderLeftWidth: 2 } : null, style]}
    />
  );
}

export function Section({
  title,
  action,
  children,
  style,
}: {
  title: string;
  action?: ReactNode;
  children: ReactNode;
  style?: ViewStyle;
}) {
  return (
    <View style={[{ marginTop: space.xl }, style]}>
      <View style={s.sectionHead}>
        <Eyebrow accessibilityRole="header">{title}</Eyebrow>
        {action}
      </View>
      {children}
    </View>
  );
}

export function Divider() {
  return <View style={s.divider} />;
}

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger';

export function Button({
  label,
  onPress,
  variant = 'primary',
  loading,
  disabled,
  icon,
  accessibilityHint,
  style,
}: {
  label: string;
  onPress: () => void;
  variant?: Variant;
  loading?: boolean;
  disabled?: boolean;
  icon?: IconName;
  accessibilityHint?: string;
  style?: ViewStyle;
}) {
  const inactive = disabled || loading;
  const fg = variant === 'primary' ? colors.ink : variant === 'danger' ? colors.red : colors.paper;
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityHint={accessibilityHint}
      accessibilityState={{ disabled: !!inactive, busy: !!loading }}
      disabled={inactive}
      onPress={onPress}
      style={({ pressed }) => [
        s.btn,
        variant === 'primary' && s.btnPrimary,
        variant === 'secondary' && s.btnSecondary,
        variant === 'danger' && s.btnDanger,
        variant === 'ghost' && s.btnGhost,
        pressed && { opacity: 0.8 },
        inactive && { opacity: 0.5 },
        style,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={fg} />
      ) : (
        <View style={s.btnRow}>
          {icon ? <Icon name={icon} size={18} color={fg} /> : null}
          <Body style={[s.btnLabel, { color: fg }]}>{label}</Body>
        </View>
      )}
    </Pressable>
  );
}

export const TextField = forwardRef<
  TextInput,
  TextInputProps & { label: string; error?: string | null; hint?: string; mono?: boolean }
>(function TextField({ label, error, hint, mono, style, ...rest }, ref) {
  return (
    <View style={{ marginBottom: space.lg }}>
      <Eyebrow style={{ marginBottom: space.sm }}>{label}</Eyebrow>
      <TextInput
        ref={ref}
        accessibilityLabel={label}
        accessibilityHint={error ?? hint}
        placeholderTextColor={colors.muted}
        selectionColor={colors.copper}
        {...rest}
        style={[s.input, mono && { fontFamily: fonts.mono, letterSpacing: 4 }, error ? { borderColor: colors.red } : null, style]}
      />
      {error ? (
        <Muted style={{ color: colors.red, marginTop: space.xs }} accessibilityLiveRegion="polite">
          {error}
        </Muted>
      ) : hint ? (
        <Muted style={{ marginTop: space.xs }}>{hint}</Muted>
      ) : null}
    </View>
  );
});

export function Checkbox({
  checked,
  onChange,
  label,
}: {
  checked: boolean;
  onChange: (v: boolean) => void;
  label: string;
}) {
  return (
    <Pressable
      accessibilityRole="checkbox"
      accessibilityState={{ checked }}
      accessibilityLabel={label}
      onPress={() => onChange(!checked)}
      style={s.checkRow}
      hitSlop={6}
    >
      <View style={[s.checkBox, checked && { backgroundColor: colors.copper, borderColor: colors.copper }]}>
        {checked ? <Icon name="check" size={16} color={colors.ink} strokeWidth={2} /> : null}
      </View>
      <Body style={{ flex: 1 }}>{label}</Body>
    </Pressable>
  );
}

/** Tappable row with leading icon and chevron (settings / navigation lists). */
export function ListRow({
  icon,
  label,
  value,
  onPress,
  accessibilityHint,
}: {
  icon?: IconName;
  label: string;
  value?: string;
  onPress?: () => void;
  accessibilityHint?: string;
}) {
  return (
    <Pressable
      accessibilityRole={onPress ? 'button' : undefined}
      accessibilityLabel={value ? `${label}, ${value}` : label}
      accessibilityHint={accessibilityHint}
      onPress={onPress}
      disabled={!onPress}
      style={({ pressed }) => [s.listRow, pressed && { backgroundColor: colors.panel }]}
    >
      {icon ? <Icon name={icon} size={20} color={colors.nickel} /> : null}
      <Body style={{ flex: 1 }}>{label}</Body>
      {value ? <Muted>{value}</Muted> : null}
      {onPress ? <Icon name="chevron" size={16} color={colors.muted} /> : null}
    </Pressable>
  );
}

export function LoadingState() {
  const { t } = useTranslation();
  return (
    <View style={s.state} accessibilityLabel={t('common.loading')} accessibilityRole="progressbar">
      <ActivityIndicator color={colors.copper} />
      <Muted style={{ marginTop: space.md }}>{t('common.loading')}</Muted>
    </View>
  );
}

export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const { t } = useTranslation();
  const msg =
    error instanceof ApiError
      ? error.status === 0
        ? t('common.networkError')
        : error.status === 404
          ? t('common.notFound')
          : t('common.errorGeneric')
      : t('common.errorGeneric');
  return (
    <View style={s.state} accessibilityLiveRegion="polite">
      <Icon name="info" size={28} color={colors.red} />
      <Heading style={{ marginTop: space.md }}>{t('common.errorTitle')}</Heading>
      <Muted style={{ marginTop: space.xs, textAlign: 'center' }}>{msg}</Muted>
      {onRetry ? (
        <Button label={t('common.retry')} variant="secondary" onPress={onRetry} style={{ marginTop: space.lg }} />
      ) : null}
    </View>
  );
}

export function EmptyState({ message, icon = 'document' }: { message: string; icon?: IconName }) {
  return (
    <View style={s.state}>
      <Icon name={icon} size={28} color={colors.muted} />
      <Muted style={{ marginTop: space.md, textAlign: 'center' }}>{message}</Muted>
    </View>
  );
}

export const s = StyleSheet.create({
  card: {
    backgroundColor: colors.graphite,
    borderColor: colors.line,
    borderWidth: StyleSheet.hairlineWidth * 2,
    borderRadius: radius.md,
    padding: space.lg,
  },
  sectionHead: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: space.md,
  },
  divider: { height: StyleSheet.hairlineWidth * 2, backgroundColor: colors.line, marginVertical: space.md },
  btn: {
    minHeight: 48,
    borderRadius: radius.sm,
    paddingHorizontal: space.lg,
    alignItems: 'center',
    justifyContent: 'center',
  },
  btnRow: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
  btnPrimary: { backgroundColor: colors.copper },
  btnSecondary: { borderWidth: 1, borderColor: colors.line, backgroundColor: colors.graphite },
  btnDanger: { borderWidth: 1, borderColor: alpha(colors.red, 0.5) },
  btnGhost: { backgroundColor: 'transparent' },
  btnLabel: { fontFamily: fonts.uiSemiBold, fontSize: 15 },
  input: {
    minHeight: 48,
    borderWidth: 1,
    borderColor: colors.line,
    backgroundColor: colors.ink,
    borderRadius: radius.sm,
    paddingHorizontal: space.md,
    color: colors.paper,
    fontFamily: fonts.ui,
    fontSize: 15,
  },
  checkRow: { flexDirection: 'row', alignItems: 'flex-start', gap: space.md, paddingVertical: space.sm },
  checkBox: {
    width: 22,
    height: 22,
    borderWidth: 1.5,
    borderColor: colors.nickel,
    borderRadius: 3,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 1,
  },
  listRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.md,
    paddingVertical: space.md + 2,
    paddingHorizontal: space.lg,
    minHeight: 48,
  },
  state: { alignItems: 'center', justifyContent: 'center', padding: space.xxl },
});
