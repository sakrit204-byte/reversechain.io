import { Pressable, StyleSheet, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { usePreferences } from '@/config/PreferencesProvider';
import { SUPPORTED_LANGUAGES } from '@/i18n';
import { colors, fonts, radius, space } from '@/theme/tokens';
import { Body } from './Typography';

/** Segmented EN / ES / IT control. */
export function LanguagePicker() {
  const { t } = useTranslation();
  const { language, setLanguage } = usePreferences();
  return (
    <View style={st.row} accessibilityRole="radiogroup" accessibilityLabel={t('account.language')}>
      {SUPPORTED_LANGUAGES.map((l) => {
        const on = l === language;
        return (
          <Pressable
            key={l}
            accessibilityRole="radio"
            accessibilityState={{ selected: on, checked: on }}
            accessibilityLabel={t(`language.${l}`)}
            onPress={() => void setLanguage(l)}
            style={[st.seg, on && st.segOn]}
          >
            <Body style={[st.code, on && { color: colors.ink }]}>{l.toUpperCase()}</Body>
            <Body style={[st.name, on && { color: colors.ink }]}>{t(`language.${l}`)}</Body>
          </Pressable>
        );
      })}
    </View>
  );
}

const st = StyleSheet.create({
  row: {
    flexDirection: 'row',
    borderWidth: 1,
    borderColor: colors.line,
    borderRadius: radius.sm,
    overflow: 'hidden',
  },
  seg: { flex: 1, paddingVertical: space.sm, alignItems: 'center', minHeight: 48, justifyContent: 'center' },
  segOn: { backgroundColor: colors.nickelLight },
  code: { fontFamily: fonts.monoMedium, fontSize: 13, lineHeight: 16, color: colors.paper },
  name: { fontSize: 11, lineHeight: 14, color: colors.muted },
});
