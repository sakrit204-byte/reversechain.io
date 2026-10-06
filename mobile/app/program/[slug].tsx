import { StyleSheet, View } from 'react-native';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { api } from '@/api';
import { DisclosureStrip } from '@/components/Disclosure';
import { ElementTile } from '@/components/ElementTile';
import { FieldRow } from '@/components/Passport';
import { Screen } from '@/components/Screen';
import { StatusPill } from '@/components/StatusPill';
import { Body, Display, Mono, Muted } from '@/components/Typography';
import { Button, Card, ErrorState, LoadingState, Section } from '@/components/ui';
import { useAsync } from '@/hooks/useAsync';
import { colors, space } from '@/theme/tokens';

export default function ProgramDetailScreen() {
  const { t, i18n } = useTranslation();
  const router = useRouter();
  const { slug } = useLocalSearchParams<{ slug: string }>();
  const q = useAsync(() => api.getProgram(slug ?? ''), [slug]);
  const p = q.data;

  /** Token parameters: translated label when known; value is always the configured value or pending. */
  const paramLabel = (key: string, fallback: string) =>
    i18n.exists(`programs.params.${key}`) ? t(`programs.params.${key}` as 'programs.params.price') : fallback;

  return (
    <Screen edges={['bottom']} refreshing={q.refreshing} onRefresh={q.refresh}>
      {q.loading ? (
        <LoadingState />
      ) : q.error || !p ? (
        <ErrorState error={q.error} onRetry={q.reload} />
      ) : (
        <>
          <View style={st.head}>
            <ElementTile symbol={p.symbol} atomicNumber={p.atomic_number} name={p.name} size={112} />
            <View style={{ flex: 1 }}>
              <Display style={{ fontSize: 28, lineHeight: 34 }}>{p.name}</Display>
              <View style={{ marginTop: space.sm }}>
                <StatusPill status={p.status} />
              </View>
            </View>
          </View>
          {p.summary ? <Body style={{ marginTop: space.lg, color: colors.nickelLight }}>{p.summary}</Body> : null}
          <View style={{ marginTop: space.lg }}>
            <DisclosureStrip onPress={() => router.push('/disclosure')} />
          </View>

          <Section title={t('programs.claims')}>
            <Card>
              {p.claims.map((c, i) => (
                <View key={`${c.label}-${i}`} style={[st.claim, i === p.claims.length - 1 && { borderBottomWidth: 0 }]}>
                  <View style={{ flex: 1, paddingRight: space.md }}>
                    <Body>{c.label}</Body>
                    {c.note ? <Muted style={{ marginTop: 2 }}>{c.note}</Muted> : null}
                  </View>
                  <StatusPill status={c.status} />
                </View>
              ))}
            </Card>
          </Section>

          {p.fields.length ? (
            <Section title={t('programs.fields')}>
              <Card style={{ paddingVertical: 0 }}>
                {p.fields.map((f) => (
                  <FieldRow key={f.key} field={f} />
                ))}
              </Card>
            </Section>
          ) : null}

          <Section title={t('programs.tokenProgram')}>
            <Card>
              <Muted style={{ marginBottom: space.sm }}>{t('programs.tokenProgramNote')}</Muted>
              {p.token_program ? (
                <>
                  <View style={st.tpHead}>
                    <Mono>{p.token_program.record_no ?? '—'}</Mono>
                    <StatusPill status={p.token_program.status} />
                  </View>
                  {p.token_program.fields.map((f) => (
                    <FieldRow key={f.key} field={f} label={paramLabel(f.key, f.label)} />
                  ))}
                </>
              ) : (
                <Body style={{ fontStyle: 'italic', color: colors.muted }}>{t('common.subjectToApproval')}</Body>
              )}
            </Card>
          </Section>

          <Button
            label={t('programs.viewPassports')}
            variant="secondary"
            icon="passport"
            onPress={() => router.push({ pathname: '/passports', params: { program: p.slug } })}
            style={{ marginTop: space.xl }}
          />
        </>
      )}
    </Screen>
  );
}

const st = StyleSheet.create({
  head: { flexDirection: 'row', alignItems: 'center', gap: space.lg },
  claim: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: space.md,
    borderBottomWidth: StyleSheet.hairlineWidth * 2,
    borderBottomColor: colors.line,
  },
  tpHead: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: space.sm },
});
