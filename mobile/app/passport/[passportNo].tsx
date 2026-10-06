import { Share, StyleSheet, View } from 'react-native';
import { useLocalSearchParams } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import QRCode from 'react-native-qrcode-svg';
import { useTranslation } from 'react-i18next';
import { api } from '@/api';
import { elementColor } from '@/components/ElementTile';
import { Hash } from '@/components/Hash';
import { FieldRow, Timeline } from '@/components/Passport';
import { Screen } from '@/components/Screen';
import { StatusPill } from '@/components/StatusPill';
import { Body, Display, Eyebrow, Heading, Mono, Muted } from '@/components/Typography';
import { Button, Card, ErrorState, LoadingState, Section } from '@/components/ui';
import { useAsync } from '@/hooks/useAsync';
import { colors, radius, space } from '@/theme/tokens';

/** Digital Asset Passport: identity, field-level pending states, timeline, evidence ledger, Merkle root, QR. */
export default function PassportDetail() {
  const { t } = useTranslation();
  const { passportNo } = useLocalSearchParams<{ passportNo: string }>();
  const q = useAsync(() => api.getPassport(passportNo ?? ''), [passportNo]);
  const p = q.data;

  const share = async () => {
    if (!p) return;
    const message = t('passports.shareMessage', { no: p.passport_no, title: p.title });
    await Share.share(p.url ? { message: `${message}\n${p.url}`, url: p.url } : { message }).catch(
      () => undefined,
    );
  };

  return (
    <Screen edges={['bottom']} refreshing={q.refreshing} onRefresh={q.refresh}>
      {q.loading ? (
        <LoadingState />
      ) : q.error || !p ? (
        <ErrorState error={q.error} onRetry={q.reload} />
      ) : (
        (() => {
          const c = elementColor(p.program_symbol ?? (p.program === 'nickel-wire' ? 'Ni' : 'Cu'));
          return (
            <>
              <Card accent={c.base}>
                <Eyebrow>{t('passports.passportNo')}</Eyebrow>
                <Mono style={{ fontSize: 16, color: c.light, marginTop: 2 }}>{p.passport_no}</Mono>
                <Display style={{ fontSize: 24, lineHeight: 30, marginTop: space.md }}>{p.title}</Display>
                {p.description ? <Muted style={{ marginTop: space.sm }}>{p.description}</Muted> : null}
                <View style={st.meta}>
                  <StatusPill status={p.status} />
                  <Muted>
                    {t('passports.entityType')}: {p.entity_label ?? p.entity_type}
                  </Muted>
                </View>
                {p.completeness ? (
                  <View style={{ marginTop: space.md }}>
                    <Muted>
                      {t('passports.completeness')} ·{' '}
                      {t('passports.completenessValue', { present: p.completeness.present, total: p.completeness.total })}
                    </Muted>
                    <View
                      style={st.bar}
                      accessibilityRole="progressbar"
                      accessibilityValue={{ min: 0, max: 100, now: p.completeness.percent }}
                    >
                      <View style={[st.barFill, { width: `${Math.max(0, Math.min(100, p.completeness.percent))}%`, backgroundColor: c.base }]} />
                    </View>
                  </View>
                ) : null}
                <Muted style={{ marginTop: space.md }}>
                  {t('passports.updated')}: {p.updated_at ?? t('common.dateTbd')}
                </Muted>
              </Card>

              <Section title={t('passports.merkleRoot')}>
                <Hash
                  value={p.merkle_root}
                  a11yLabel={t('passports.rootA11y', { hash: p.merkle_root ?? '' })}
                  emptyLabel={t('common.pendingAwaiting')}
                />
              </Section>

              <Section title={t('passports.fields')}>
                <Card style={{ paddingVertical: 0 }}>
                  {p.fields.length ? (
                    p.fields.map((f) => <FieldRow key={f.key} field={f} />)
                  ) : (
                    <Body style={st.pending}>{t('common.notProvided')}</Body>
                  )}
                </Card>
              </Section>

              <Section title={t('passports.timeline')}>
                <Card>
                  {p.timeline.length ? <Timeline events={p.timeline} /> : <Body style={st.pending}>{t('common.notProvided')}</Body>}
                </Card>
              </Section>

              <Section title={t('passports.documents')}>
                {p.documents.length ? (
                  p.documents.map((d) => (
                    <Card key={String(d.id)} style={{ marginBottom: space.md }}>
                      <View style={st.docHead}>
                        <View style={{ flex: 1, paddingRight: space.md }}>
                          <Heading>{d.title}</Heading>
                          <Muted style={{ marginTop: 2 }}>
                            {d.type}
                            {d.issue_date ? ` · ${d.issue_date}` : ''}
                          </Muted>
                        </View>
                        <StatusPill status={d.status} />
                      </View>
                      <Muted style={{ marginTop: space.sm }}>
                        {t('passports.issuedBy')}: {d.issued_by ?? t('common.notProvided')}
                      </Muted>
                      <Eyebrow style={{ marginTop: space.md, marginBottom: space.xs }}>{t('passports.sha256')}</Eyebrow>
                      <Hash
                        value={d.sha256}
                        a11yLabel={t('passports.hashA11y', { hash: d.sha256 ?? '' })}
                        emptyLabel={t('passports.noHash')}
                      />
                      {d.url ? (
                        <Button
                          label={t('common.open')}
                          variant="secondary"
                          icon="external"
                          accessibilityHint={t('documents.openA11y', { title: d.title })}
                          onPress={() => void WebBrowser.openBrowserAsync(d.url as string)}
                          style={{ marginTop: space.md }}
                        />
                      ) : null}
                    </Card>
                  ))
                ) : (
                  <Body style={st.pending}>{t('common.notProvided')}</Body>
                )}
              </Section>

              <Section title={t('passports.custody')}>
                <Card>
                  {p.custody.length ? (
                    p.custody.map((entry, i) => (
                      <Mono key={i} style={{ marginBottom: space.xs }}>
                        {Object.entries(entry)
                          .filter(([, v]) => v !== null && v !== undefined && typeof v !== 'object')
                          .map(([k, v]) => `${k}: ${String(v)}`)
                          .join(' · ')}
                      </Mono>
                    ))
                  ) : (
                    <Body style={st.pending}>{t('passports.custodyEmpty')}</Body>
                  )}
                </Card>
              </Section>

              <Section title={t('passports.qr')}>
                <Card style={{ alignItems: 'center' }}>
                  {p.url ? (
                    <View
                      style={st.qr}
                      accessible
                      accessibilityRole="image"
                      accessibilityLabel={t('passports.qrA11y', { no: p.passport_no })}
                    >
                      <QRCode value={p.url} size={180} color={colors.ink} backgroundColor={colors.paper} />
                    </View>
                  ) : (
                    <Body style={st.pending}>{t('passports.qrUnavailable')}</Body>
                  )}
                </Card>
              </Section>

              <Button label={t('common.share')} icon="share" variant="secondary" onPress={share} style={{ marginTop: space.xl }} />
            </>
          );
        })()
      )}
    </Screen>
  );
}

const st = StyleSheet.create({
  meta: { flexDirection: 'row', alignItems: 'center', gap: space.md, marginTop: space.md, flexWrap: 'wrap' },
  pending: { fontStyle: 'italic', color: colors.muted, fontSize: 14, paddingVertical: space.md },
  docHead: { flexDirection: 'row', alignItems: 'flex-start' },
  qr: { padding: space.md, backgroundColor: colors.paper, borderRadius: radius.sm },
  bar: { height: 4, backgroundColor: colors.line, borderRadius: 2, marginTop: space.xs, overflow: 'hidden' },
  barFill: { height: 4 },
});
