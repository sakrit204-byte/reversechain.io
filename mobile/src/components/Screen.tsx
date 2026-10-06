import type { ReactNode } from 'react';
import { RefreshControl, ScrollView, StyleSheet, View, type ViewStyle } from 'react-native';
import { SafeAreaView, type Edge } from 'react-native-safe-area-context';
import { useTranslation } from 'react-i18next';
import { env } from '@/config/env';
import { alpha, colors, fonts, space } from '@/theme/tokens';
import { Body } from './Typography';

export function MockBanner() {
  const { t } = useTranslation();
  if (!env.mock) return null;
  return (
    <View style={st.mock} accessibilityRole="alert" accessibilityLabel={t('common.mockBanner')}>
      <Body style={st.mockBadge}>{t('common.mockBadge')}</Body>
      <Body style={st.mockText} numberOfLines={2}>
        {t('common.mockBanner')}
      </Body>
    </View>
  );
}

/** Standard scrolling screen: ink background, safe areas, mock banner, pull-to-refresh. */
export function Screen({
  children,
  refreshing,
  onRefresh,
  edges = ['top'],
  scroll = true,
  contentStyle,
}: {
  children: ReactNode;
  refreshing?: boolean;
  onRefresh?: () => void;
  edges?: Edge[];
  scroll?: boolean;
  contentStyle?: ViewStyle;
}) {
  return (
    <SafeAreaView style={st.root} edges={edges}>
      <MockBanner />
      {scroll ? (
        <ScrollView
          contentContainerStyle={[st.content, contentStyle]}
          keyboardShouldPersistTaps="handled"
          refreshControl={
            onRefresh ? (
              <RefreshControl refreshing={!!refreshing} onRefresh={onRefresh} tintColor={colors.copper} />
            ) : undefined
          }
        >
          <View style={st.inner}>{children}</View>
        </ScrollView>
      ) : (
        <View style={[st.content, st.inner, { flex: 1 }, contentStyle]}>{children}</View>
      )}
    </SafeAreaView>
  );
}

const st = StyleSheet.create({
  root: { flex: 1, backgroundColor: colors.ink },
  content: { padding: space.lg, paddingBottom: space.xxl * 2 },
  // Constrain width on tablets / web preview for an institutional, document-like measure.
  inner: { width: '100%', maxWidth: 720, alignSelf: 'center' },
  mock: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.sm,
    paddingHorizontal: space.lg,
    paddingVertical: 6,
    backgroundColor: alpha(colors.amber, 0.12),
    borderBottomWidth: 1,
    borderBottomColor: alpha(colors.amber, 0.35),
  },
  mockBadge: { fontFamily: fonts.monoMedium, fontSize: 10, letterSpacing: 1, color: colors.amber },
  mockText: { flex: 1, fontSize: 11, lineHeight: 14, color: colors.amber },
});
