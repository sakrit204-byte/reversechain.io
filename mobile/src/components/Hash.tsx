import { Pressable, StyleSheet, View } from 'react-native';
import * as Clipboard from 'expo-clipboard';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { colors, radius, space } from '@/theme/tokens';
import { Icon } from './Icon';
import { Mono, Muted } from './Typography';

/** Full-length SHA-256 / Merkle root in IBM Plex Mono, grouped in 8-char blocks, with copy. */
export function Hash({
  value,
  a11yLabel,
  emptyLabel,
}: {
  value: string | null | undefined;
  a11yLabel: string;
  emptyLabel: string;
}) {
  const { t } = useTranslation();
  const [copied, setCopied] = useState(false);
  if (!value) {
    return <Muted style={{ fontStyle: 'italic' }}>{emptyLabel}</Muted>;
  }
  const grouped = value.match(/.{1,8}/g)?.join(' ') ?? value;
  return (
    <View style={st.box}>
      <Mono style={{ flex: 1 }} accessibilityLabel={a11yLabel}>
        {grouped}
      </Mono>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={copied ? t('common.copied') : t('common.copy')}
        hitSlop={8}
        onPress={async () => {
          await Clipboard.setStringAsync(value);
          setCopied(true);
          setTimeout(() => setCopied(false), 1500);
        }}
      >
        <Icon name={copied ? 'check' : 'copy'} size={18} color={copied ? colors.green : colors.muted} />
      </Pressable>
    </View>
  );
}

const st = StyleSheet.create({
  box: {
    flexDirection: 'row',
    gap: space.md,
    alignItems: 'flex-start',
    backgroundColor: colors.ink,
    borderRadius: radius.sm,
    borderWidth: 1,
    borderColor: colors.line,
    padding: space.md,
  },
});
