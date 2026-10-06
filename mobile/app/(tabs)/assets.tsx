import { View } from 'react-native';
import { useRouter } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { api } from '@/api';
import type { GatedList } from '@/api/types';
import { useSession } from '@/auth/SessionProvider';
import { DisclosureStrip } from '@/components/Disclosure';
import { Icon } from '@/components/Icon';
import { LockedFeature } from '@/components/LockedFeature';
import { Screen } from '@/components/Screen';
import { Display, Heading, Mono, Muted } from '@/components/Typography';
import { Button, Card, Section } from '@/components/ui';
import { useAppConfig } from '@/config/ConfigProvider';
import { isListActive, type GatedFeature } from '@/config/featureFlags';
import { useAsync } from '@/hooks/useAsync';
import { colors, space } from '@/theme/tokens';

/** Account data list (holdings/transactions) — rendered only when BOTH config and endpoint enable it. */
function GatedListCard({
  feature,
  list,
}: {
  feature: 'holdings' | 'transactions';
  list: GatedList<Record<string, unknown>> | null;
}) {
  const { t } = useTranslation();
  const { config } = useAppConfig();
  if (!isListActive(config, feature, list)) return <LockedFeature feature={feature} compact />;
  return (
    <Card style={{ marginBottom: space.md }}>
      <Heading>{t(`assets.features.${feature}`)}</Heading>
      {list?.items.length ? (
        list.items.map((item, i) => (
          <Mono key={i} style={{ marginTop: space.sm }}>
            {Object.entries(item)
              .filter(([, v]) => v !== null && typeof v !== 'object')
              .map(([k, v]) => `${k}: ${String(v)}`)
              .join(' · ')}
          </Mono>
        ))
      ) : (
        <Muted style={{ marginTop: space.sm }}>{t('assets.enabledEmpty')}</Muted>
      )}
    </Card>
  );
}

/** Service module: locked unless /config enables it; even then the flow is not shipped in this build. */
function ServiceCard({ feature }: { feature: GatedFeature }) {
  const { t } = useTranslation();
  const { isActive } = useAppConfig();
  if (!isActive(feature)) return <LockedFeature feature={feature} />;
  return (
    <Card style={{ marginBottom: space.md }}>
      <Heading>{t(`assets.features.${feature}`)}</Heading>
      <Muted style={{ marginTop: space.sm }}>{t('assets.enabledPendingIntegration')}</Muted>
    </Card>
  );
}

export default function Assets() {
  const { t } = useTranslation();
  const router = useRouter();
  const { status } = useSession();
  const signedIn = status === 'signedIn';
  const lists = useAsync(async () => {
    if (!signedIn) return null;
    const [holdings, transactions] = await Promise.all([
      api.getHoldings().catch(() => null),
      api.getTransactions().catch(() => null),
    ]);
    return { holdings, transactions };
  }, [signedIn]);

  return (
    <Screen refreshing={lists.refreshing} onRefresh={lists.refresh}>
      <Display>{t('assets.title')}</Display>
      <Muted style={{ marginTop: space.sm, marginBottom: space.lg }}>{t('assets.subtitle')}</Muted>
      <DisclosureStrip onPress={() => router.push('/disclosure')} />

      <Section title={`${t('assets.features.holdings')} · ${t('assets.features.transactions')}`}>
        {signedIn ? (
          <>
            <GatedListCard feature="holdings" list={lists.data?.holdings ?? null} />
            <GatedListCard feature="transactions" list={lists.data?.transactions ?? null} />
          </>
        ) : (
          <>
            <LockedFeature feature="holdings" compact />
            <LockedFeature feature="transactions" compact />
            <Card>
              <View style={{ flexDirection: 'row', gap: space.sm, alignItems: 'center' }}>
                <Icon name="account" size={18} color={colors.nickel} />
                <Muted style={{ flex: 1 }}>{t('assets.signInRequired')}</Muted>
              </View>
              <Button label={t('common.signIn')} variant="secondary" onPress={() => router.push('/login')} style={{ marginTop: space.md }} />
            </Card>
          </>
        )}
      </Section>

      <Section title={t('overview.modules')}>
        <ServiceCard feature="wallet" />
        <ServiceCard feature="purchase" />
        <ServiceCard feature="proof_of_reserves" />
        <ServiceCard feature="redemption" />
      </Section>
    </Screen>
  );
}
