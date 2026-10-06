import { Pressable, StyleSheet, View } from 'react-native';
import { useRouter } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { api } from '@/api';
import { DisclosureStrip } from '@/components/Disclosure';
import { Icon } from '@/components/Icon';
import { ProgramCard } from '@/components/ProgramCard';
import { Screen } from '@/components/Screen';
import { Body, Display, Eyebrow, Heading, Mono, Muted } from '@/components/Typography';
import { Card, ErrorState, LoadingState, Section } from '@/components/ui';
import { useSession } from '@/auth/SessionProvider';
import { useAppConfig } from '@/config/ConfigProvider';
import { GATED_FEATURES } from '@/config/featureFlags';
import { useAsync } from '@/hooks/useAsync';
import { alpha, colors, space } from '@/theme/tokens';

export default function Overview() {
  const { t } = useTranslation();
  const router = useRouter();
  const { me } = useSession();
  const { config, isActive, reload: reloadConfig } = useAppConfig();
  const programs = useAsync(() => api.getPrograms(), []);

  const onRefresh = () => {
    void reloadConfig();
    void programs.refresh();
  };

  return (
    <Screen refreshing={programs.refreshing} onRefresh={onRefresh}>
      <Eyebrow style={{ color: colors.copperLight }}>{t('overview.eyebrow')}</Eyebrow>
      <Display style={{ marginTop: space.xs }}>{me?.name ? me.name : t('overview.title')}</Display>
      <Muted style={{ marginTop: space.sm, marginBottom: space.lg }}>{t('overview.subtitle')}</Muted>
      <DisclosureStrip onPress={() => router.push('/disclosure')} />

      <Section title={t('overview.programs')}>
        {programs.loading ? (
          <LoadingState />
        ) : programs.error ? (
          <ErrorState error={programs.error} onRetry={programs.reload} />
        ) : (
          programs.data?.map((p) => <ProgramCard key={p.slug} program={p} />)
        )}
      </Section>

      <Section title={t('overview.registry')}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('overview.registry')}
          onPress={() => router.push('/passports')}
        >
          <Card style={st.registry}>
            <Icon name="passport" size={28} color={colors.nickelLight} />
            <View style={{ flex: 1 }}>
              <Heading>{t('passports.title')}</Heading>
              <Muted style={{ marginTop: 2 }}>{t('overview.registryBody')}</Muted>
            </View>
            <Icon name="chevron" size={16} color={colors.muted} />
          </Card>
        </Pressable>
      </Section>

      <Section title={t('overview.modules')}>
        <Muted style={{ marginBottom: space.md }}>{t('overview.modulesNote')}</Muted>
        <View style={st.grid}>
          {GATED_FEATURES.map((f) => {
            const active = isActive(f);
            const label = t(`assets.features.${f}`);
            return (
              <Pressable
                key={f}
                accessibilityRole="button"
                accessibilityLabel={active ? label : t('assets.lockedA11y', { feature: label })}
                onPress={() => router.push('/assets')}
                style={[st.module, !active && st.moduleLocked]}
              >
                <Icon name={active ? 'check' : 'lock'} size={16} color={active ? colors.green : colors.amber} />
                <Body style={{ fontSize: 13, flex: 1 }} numberOfLines={2}>
                  {label}
                </Body>
              </Pressable>
            );
          })}
        </View>
      </Section>

      <Section title={t('overview.network')}>
        <Card>
          <View style={st.kv}>
            <Muted>{t('overview.network')}</Muted>
            <Mono>{config.network?.name ?? t('common.pending')}</Mono>
          </View>
          <View style={st.kv}>
            <Muted>{t('overview.tokenAddress')}</Muted>
            <Mono>{config.network?.token_address ?? t('common.notProvided')}</Mono>
          </View>
          <View style={st.kv}>
            <Muted>{t('overview.siteMode')}</Muted>
            <Mono>{config.site_mode}</Mono>
          </View>
          <Muted style={{ marginTop: space.sm, color: colors.amber }}>{t('overview.testnetOnly')}</Muted>
        </Card>
      </Section>
    </Screen>
  );
}

const st = StyleSheet.create({
  registry: { flexDirection: 'row', alignItems: 'center', gap: space.lg },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: space.sm },
  module: {
    width: '48.5%',
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.sm,
    padding: space.md,
    borderRadius: 6,
    borderWidth: 1,
    borderColor: colors.line,
    backgroundColor: colors.graphite,
    minHeight: 52,
  },
  moduleLocked: { borderStyle: 'dashed', backgroundColor: alpha(colors.amber, 0.04) },
  kv: { flexDirection: 'row', justifyContent: 'space-between', gap: space.md, paddingVertical: space.xs },
});
