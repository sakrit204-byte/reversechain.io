import type { ColorValue } from 'react-native';
import { Tabs } from 'expo-router/js-tabs';
import { useTranslation } from 'react-i18next';
import { Icon, type IconName } from '@/components/Icon';
import { colors, fonts } from '@/theme/tokens';

function tabIcon(name: IconName) {
  function TabIcon({ color }: { color: ColorValue }) {
    return <Icon name={name} size={22} color={typeof color === 'string' ? color : colors.muted} />;
  }
  return TabIcon;
}

export default function TabsLayout() {
  const { t } = useTranslation();
  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: colors.copperLight,
        tabBarInactiveTintColor: colors.muted,
        tabBarStyle: { backgroundColor: colors.graphite, borderTopColor: colors.line },
        tabBarLabelStyle: { fontFamily: fonts.uiMedium, fontSize: 11 },
        sceneStyle: { backgroundColor: colors.ink },
      }}
    >
      <Tabs.Screen name="overview" options={{ title: t('tabs.overview'), tabBarIcon: tabIcon('overview') }} />
      <Tabs.Screen name="programs" options={{ title: t('tabs.programs'), tabBarIcon: tabIcon('programs') }} />
      <Tabs.Screen name="passports" options={{ title: t('tabs.passports'), tabBarIcon: tabIcon('passport') }} />
      <Tabs.Screen name="assets" options={{ title: t('tabs.assets'), tabBarIcon: tabIcon('assets') }} />
      <Tabs.Screen name="account" options={{ title: t('tabs.account'), tabBarIcon: tabIcon('account') }} />
    </Tabs>
  );
}
