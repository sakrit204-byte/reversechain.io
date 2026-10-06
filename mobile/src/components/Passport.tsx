import { StyleSheet, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { DataField, TimelineEvent } from '@/api/types';
import { claimStatusColor } from '@/theme/status';
import { colors, fonts, space } from '@/theme/tokens';
import { StatusPill } from './StatusPill';
import { Body, Muted } from './Typography';

/**
 * A labelled data value. Null values render an explicit pending state — never a guess.
 * The claim pill is only shown when the backend supplies a claim status.
 */
export function FieldRow({ field, label }: { field: DataField; label?: string }) {
  const { t } = useTranslation();
  const name = label ?? field.label;
  const pendingText =
    field.pendingText ??
    (field.status === 'not_applicable' ? t('status.not_applicable') : t('common.notProvided'));
  const shown = field.value !== null ? [field.value, field.unit].filter(Boolean).join(' ') : null;
  const statusA11y = field.status ? ` ${t('status.a11y', { status: t(`status.${field.status}`) })}` : '';
  return (
    <View style={st.row} accessible accessibilityLabel={`${name}: ${shown ?? pendingText}.${statusA11y}`}>
      <View style={{ flex: 1, paddingRight: space.md }}>
        <Muted>{name}</Muted>
        {shown !== null ? (
          <Body style={{ fontFamily: fonts.mono, fontSize: 14, marginTop: 2 }} selectable>
            {shown}
          </Body>
        ) : (
          <Body style={st.pending}>{pendingText}</Body>
        )}
      </View>
      {field.status ? <StatusPill status={field.status} /> : null}
    </View>
  );
}

export function Timeline({ events }: { events: TimelineEvent[] }) {
  const { t } = useTranslation();
  return (
    <View>
      {events.map((e, i) => {
        const c = claimStatusColor[e.status] ?? colors.muted;
        const last = i === events.length - 1;
        return (
          <View key={`${e.key}-${i}`} style={st.tlRow}>
            <View style={st.tlRail}>
              <View style={[st.tlNode, { borderColor: c, backgroundColor: e.status === 'verified' ? c : colors.ink }]} />
              {!last ? <View style={st.tlLine} /> : null}
            </View>
            <View style={{ flex: 1, paddingBottom: last ? 0 : space.lg }}>
              <Body style={{ fontFamily: fonts.mono, fontSize: 12, color: colors.muted }}>
                {e.date ?? t('common.dateTbd')}
              </Body>
              <Body style={{ marginTop: 2, marginBottom: e.note ? 2 : space.sm }}>{e.event}</Body>
              {e.note ? <Muted style={{ marginBottom: space.sm }}>{e.note}</Muted> : null}
              <StatusPill status={e.status} />
            </View>
          </View>
        );
      })}
    </View>
  );
}

const st = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: space.md,
    borderBottomWidth: StyleSheet.hairlineWidth * 2,
    borderBottomColor: colors.line,
  },
  pending: { fontStyle: 'italic', color: colors.muted, fontSize: 14, marginTop: 2 },
  tlRow: { flexDirection: 'row', gap: space.md },
  tlRail: { width: 14, alignItems: 'center' },
  tlNode: { width: 12, height: 12, borderRadius: 6, borderWidth: 2, marginTop: 3 },
  tlLine: { flex: 1, width: 1, backgroundColor: colors.line, marginTop: 2 },
});
