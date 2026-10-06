import { Pressable, StyleSheet, View } from 'react-native';
import * as WebBrowser from 'expo-web-browser';
import { useTranslation } from 'react-i18next';
import { api } from '@/api';
import { Hash } from '@/components/Hash';
import { Icon } from '@/components/Icon';
import { Screen } from '@/components/Screen';
import { StatusPill } from '@/components/StatusPill';
import { Heading, Muted } from '@/components/Typography';
import { Card, EmptyState, ErrorState, LoadingState } from '@/components/ui';
import { useAsync } from '@/hooks/useAsync';
import { colors, fonts, space } from '@/theme/tokens';

/** Public document library. PDFs open in the system browser sheet (expo-web-browser). */
export default function Documents() {
  const { t } = useTranslation();
  const q = useAsync(() => api.getDocuments(), []);

  return (
    <Screen edges={['bottom']} refreshing={q.refreshing} onRefresh={q.refresh}>
      <Muted style={{ marginBottom: space.lg }}>{t('documents.subtitle')}</Muted>
      {q.loading ? (
        <LoadingState />
      ) : q.error ? (
        <ErrorState error={q.error} onRetry={q.reload} />
      ) : !q.data?.length ? (
        <EmptyState message={t('documents.empty')} />
      ) : (
        q.data.map((d) => {
          const url = d.url;
          return (
            <Pressable
              key={String(d.id)}
              accessibilityRole={url ? 'link' : 'text'}
              accessibilityLabel={url ? t('documents.openA11y', { title: d.title }) : `${d.title}. ${t('documents.notPublished')}`}
              disabled={!url}
              onPress={() => url && void WebBrowser.openBrowserAsync(url, { controlsColor: colors.copper })}
              style={({ pressed }) => [{ marginBottom: space.md }, pressed && { opacity: 0.85 }]}
            >
              <Card>
                <View style={st.head}>
                  <Icon name="document" size={22} color={colors.nickel} />
                  <View style={{ flex: 1 }}>
                    <Heading>{d.title}</Heading>
                    <Muted style={{ marginTop: 2 }}>
                      {[d.type, d.language?.toUpperCase(), d.updated_at].filter(Boolean).join(' · ')}
                    </Muted>
                  </View>
                  {url ? <Icon name="external" size={18} color={colors.copperLight} /> : null}
                </View>
                <View style={st.foot}>
                  <StatusPill status={d.status} />
                  {!url ? <Muted style={st.np}>{t('documents.notPublished')}</Muted> : null}
                </View>
                {d.sha256 ? (
                  <View style={{ marginTop: space.md }}>
                    <Hash value={d.sha256} a11yLabel={t('passports.hashA11y', { hash: d.sha256 })} emptyLabel="" />
                  </View>
                ) : null}
              </Card>
            </Pressable>
          );
        })
      )}
    </Screen>
  );
}

const st = StyleSheet.create({
  head: { flexDirection: 'row', gap: space.md, alignItems: 'flex-start' },
  foot: { flexDirection: 'row', alignItems: 'center', gap: space.md, marginTop: space.md },
  np: { fontFamily: fonts.ui, fontStyle: 'italic' },
});
