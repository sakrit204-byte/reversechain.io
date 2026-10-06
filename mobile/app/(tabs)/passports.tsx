import { useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { api } from '@/api';
import type { PassportSummary } from '@/api/types';
import { elementColor } from '@/components/ElementTile';
import { Icon } from '@/components/Icon';
import { Screen } from '@/components/Screen';
import { StatusPill } from '@/components/StatusPill';
import { Body, Display, Eyebrow, Heading, Mono, Muted } from '@/components/Typography';
import { Card, EmptyState, ErrorState, LoadingState } from '@/components/ui';
import { useAsync } from '@/hooks/useAsync';
import { colors, fonts, radius, space } from '@/theme/tokens';

const FILTERS = [
  { slug: '', label: 'all' as const, symbol: null },
  { slug: 'copper-powder', label: 'Cu 29', symbol: 'Cu' },
  { slug: 'nickel-wire', label: 'Ni 28', symbol: 'Ni' },
];

function PassportRow({ p }: { p: PassportSummary }) {
  const { t } = useTranslation();
  const router = useRouter();
  const sym = p.program === 'nickel-wire' ? 'Ni' : 'Cu';
  const c = elementColor(sym);
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${p.passport_no}, ${p.title}`}
      onPress={() => router.push({ pathname: '/passport/[passportNo]', params: { passportNo: p.passport_no } })}
      style={({ pressed }) => [{ marginBottom: space.md }, pressed && { opacity: 0.85 }]}
    >
      <Card accent={c.base}>
        <View style={st.rowHead}>
          <Mono style={{ color: c.light, fontSize: 13 }}>{p.passport_no}</Mono>
          <Icon name="chevron" size={14} color={colors.muted} />
        </View>
        <Heading style={{ marginTop: space.xs }}>{p.title}</Heading>
        <View style={st.meta}>
          <StatusPill status={p.status} />
          <Body style={st.entity}>{(p.entity_label ?? p.entity_type).toUpperCase()}</Body>
        </View>
        <Eyebrow style={{ marginTop: space.md }}>{t('passports.merkleRoot')}</Eyebrow>
        <Mono numberOfLines={1} ellipsizeMode="middle" style={{ marginTop: 2 }}>
          {p.merkle_root ?? t('common.pending')}
        </Mono>
      </Card>
    </Pressable>
  );
}

export default function Passports() {
  const { t } = useTranslation();
  const params = useLocalSearchParams<{ program?: string }>();
  // A user selection applies to the route param it was made under; navigating here again from a
  // program page (?program=slug) therefore re-applies that program's filter.
  const [selection, setSelection] = useState<{ param: string | undefined; value: string } | null>(null);
  const program = selection && selection.param === params.program ? selection.value : (params.program ?? '');
  const setProgram = (value: string) => setSelection({ param: params.program, value });
  const q = useAsync(() => api.getPassports(program || undefined), [program]);

  return (
    <Screen refreshing={q.refreshing} onRefresh={q.refresh}>
      <Display>{t('passports.title')}</Display>
      <Muted style={{ marginTop: space.sm, marginBottom: space.lg }}>{t('passports.subtitle')}</Muted>
      <View style={st.filters} accessibilityRole="tablist">
        {FILTERS.map((f) => {
          const on = program === f.slug;
          const tint = f.symbol ? elementColor(f.symbol).light : colors.paper;
          return (
            <Pressable
              key={f.slug || 'all'}
              accessibilityRole="tab"
              accessibilityState={{ selected: on }}
              onPress={() => setProgram(f.slug)}
              style={[st.filter, on && { borderColor: tint, backgroundColor: colors.panel }]}
            >
              <Body style={[st.filterText, { color: on ? tint : colors.muted }]}>
                {f.label === 'all' ? t('passports.all') : f.label}
              </Body>
            </Pressable>
          );
        })}
      </View>
      {q.loading ? (
        <LoadingState />
      ) : q.error ? (
        <ErrorState error={q.error} onRetry={q.reload} />
      ) : !q.data?.length ? (
        <EmptyState icon="passport" message={t('passports.empty')} />
      ) : (
        q.data.map((p) => <PassportRow key={p.passport_no} p={p} />)
      )}
    </Screen>
  );
}

const st = StyleSheet.create({
  rowHead: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  meta: { flexDirection: 'row', alignItems: 'center', gap: space.md, marginTop: space.sm },
  entity: { fontFamily: fonts.mono, fontSize: 11, color: colors.muted, letterSpacing: 1 },
  filters: { flexDirection: 'row', gap: space.sm, marginBottom: space.lg },
  filter: {
    paddingHorizontal: space.md,
    minHeight: 36,
    justifyContent: 'center',
    borderRadius: radius.pill,
    borderWidth: 1,
    borderColor: colors.line,
  },
  filterText: { fontFamily: fonts.monoMedium, fontSize: 13 },
});
