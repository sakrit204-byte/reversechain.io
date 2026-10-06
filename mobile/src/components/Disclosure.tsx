import { useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { DISCLOSURE_EN, EU_NOTICE_EN } from '@/config/compliance';
import { alpha, colors, space } from '@/theme/tokens';
import { Icon } from './Icon';
import { Body, Eyebrow, Heading, Muted } from './Typography';
import { Card } from './ui';

/**
 * Mandatory disclosure + EU/EEA notice (SPEC rules 3–4).
 * English is shown verbatim; for ES/IT the faithful translation is shown with an
 * "English is authoritative" note and a toggle that reveals the authoritative English text.
 * `serverDisclosure` (from /config) overrides the bundled English when provided.
 */
export function DisclosureBlock({
  serverDisclosure,
  serverEuNotice,
}: {
  serverDisclosure?: string | null;
  serverEuNotice?: string | null;
}) {
  const { t, i18n } = useTranslation();
  const [showEn, setShowEn] = useState(false);
  const isEn = i18n.language === 'en';
  const enDisclosure = serverDisclosure || DISCLOSURE_EN;
  const enEu = serverEuNotice || EU_NOTICE_EN;

  return (
    <View>
      <Card accent={colors.copper}>
        <View style={st.head}>
          <Icon name="shield" size={18} color={colors.copperLight} />
          <Heading>{t('disclosure.title')}</Heading>
        </View>
        <Body style={st.text}>{isEn ? enDisclosure : t('disclosure.body')}</Body>
        <Muted style={{ marginTop: space.md }}>{t('disclosure.authoritativeNote')}</Muted>
        {!isEn ? (
          <>
            <Pressable
              accessibilityRole="button"
              accessibilityState={{ expanded: showEn }}
              onPress={() => setShowEn((v) => !v)}
              style={st.toggle}
              hitSlop={6}
            >
              <Icon name="globe" size={16} color={colors.nickel} />
              <Body style={{ color: colors.nickel, fontSize: 14 }}>
                {showEn ? t('disclosure.hideEnglish') : t('disclosure.showEnglish')}
              </Body>
            </Pressable>
            {showEn ? (
              <View style={st.enBox} accessibilityLanguage="en">
                <Eyebrow style={{ marginBottom: space.xs }}>English</Eyebrow>
                <Body style={st.text}>{enDisclosure}</Body>
                <Body style={[st.text, { marginTop: space.sm }]}>{enEu}</Body>
              </View>
            ) : null}
          </>
        ) : null}
      </Card>
      <Card accent={colors.nickel} style={{ marginTop: space.md }}>
        <View style={st.head}>
          <Icon name="globe" size={18} color={colors.nickelLight} />
          <Heading>{t('disclosure.euTitle')}</Heading>
        </View>
        <Body style={st.text}>{isEn ? enEu : t('disclosure.euBody')}</Body>
      </Card>
    </View>
  );
}

/** One-line persistent reminder used at the top of key screens. */
export function DisclosureStrip({ onPress }: { onPress?: () => void }) {
  const { t } = useTranslation();
  return (
    <Pressable
      accessibilityRole={onPress ? 'link' : 'text'}
      accessibilityLabel={`${t('disclosure.title')}: ${t('disclosure.short')}`}
      onPress={onPress}
      style={st.strip}
    >
      <Icon name="info" size={14} color={colors.copperLight} />
      <Muted style={{ flex: 1, fontSize: 12, lineHeight: 16 }}>{t('disclosure.short')}</Muted>
      {onPress ? <Icon name="chevron" size={14} color={colors.muted} /> : null}
    </Pressable>
  );
}

const st = StyleSheet.create({
  head: { flexDirection: 'row', alignItems: 'center', gap: space.sm, marginBottom: space.md },
  text: { fontSize: 14, lineHeight: 21 },
  toggle: { flexDirection: 'row', alignItems: 'center', gap: space.sm, marginTop: space.md, minHeight: 32 },
  enBox: {
    marginTop: space.sm,
    padding: space.md,
    borderRadius: 4,
    backgroundColor: colors.ink,
    borderWidth: 1,
    borderColor: colors.line,
  },
  strip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.sm,
    paddingVertical: space.sm,
    paddingHorizontal: space.md,
    borderRadius: 4,
    backgroundColor: alpha(colors.copper, 0.08),
    borderWidth: 1,
    borderColor: alpha(colors.copper, 0.25),
  },
});
