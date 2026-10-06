import '@/i18n';
import { useEffect } from 'react';
import { View } from 'react-native';
import { Stack, useRouter, useSegments, SplashScreen } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { useFonts } from 'expo-font';
import { Fraunces_400Regular } from '@expo-google-fonts/fraunces/400Regular';
import { Fraunces_600SemiBold } from '@expo-google-fonts/fraunces/600SemiBold';
import { Inter_400Regular } from '@expo-google-fonts/inter/400Regular';
import { Inter_500Medium } from '@expo-google-fonts/inter/500Medium';
import { Inter_600SemiBold } from '@expo-google-fonts/inter/600SemiBold';
import { IBMPlexMono_400Regular } from '@expo-google-fonts/ibm-plex-mono/400Regular';
import { IBMPlexMono_500Medium } from '@expo-google-fonts/ibm-plex-mono/500Medium';
import { useTranslation } from 'react-i18next';
import { SessionProvider, useSession } from '@/auth/SessionProvider';
import { ConfigProvider } from '@/config/ConfigProvider';
import { PreferencesProvider, usePreferences } from '@/config/PreferencesProvider';
import { configureNotificationHandler } from '@/notifications/push';
import { colors, fonts } from '@/theme/tokens';

void SplashScreen.preventAutoHideAsync().catch(() => undefined);
configureNotificationHandler();

/** Routes that require a signed-in session. */
const PROTECTED = new Set(['notifications', 'support', 'mfa-setup', 'delete-account']);
/** Routes reachable while signed out, even without choosing guest browsing. */
const ALWAYS_PUBLIC = new Set(['(auth)', 'onboarding', 'disclosure']);

/** Central navigation guard: disclosure first, then lock / auth state. */
function Guard() {
  const router = useRouter();
  const segments = useSegments();
  const { loaded, disclosureAccepted } = usePreferences();
  const { status, guest } = useSession();

  useEffect(() => {
    if (!loaded || status === 'loading') return;
    const first = segments[0] as string | undefined;
    // The index route performs its own redirect.
    if (first === undefined) return;
    if (!disclosureAccepted) {
      if (first !== 'onboarding') router.replace('/onboarding');
      return;
    }
    if (status === 'locked') {
      if (first !== 'lock') router.replace('/lock');
      return;
    }
    if (first === 'lock') {
      router.replace(status === 'signedIn' ? '/' : '/login');
      return;
    }
    if (status === 'signedOut') {
      const allowed = ALWAYS_PUBLIC.has(first) || (guest && !PROTECTED.has(first));
      if (!allowed) router.replace('/login');
      return;
    }
    if (status === 'signedIn' && (first === '(auth)' || first === 'onboarding')) {
      router.replace('/');
    }
  }, [loaded, disclosureAccepted, status, guest, segments, router]);

  return null;
}

function RootNavigator() {
  const { t } = useTranslation();
  const { touch } = useSession();
  return (
    // Any touch resets the inactivity timer.
    <View style={{ flex: 1, backgroundColor: colors.ink }} onTouchStart={touch} onPointerDown={touch}>
      <Guard />
      <Stack
        screenOptions={{
          headerStyle: { backgroundColor: colors.ink },
          headerTintColor: colors.paper,
          headerTitleStyle: { fontFamily: fonts.uiSemiBold, fontSize: 16 },
          headerShadowVisible: false,
          contentStyle: { backgroundColor: colors.ink },
          headerBackButtonDisplayMode: 'minimal',
        }}
      >
        <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
        <Stack.Screen name="(auth)" options={{ headerShown: false }} />
        <Stack.Screen name="onboarding" options={{ headerShown: false, gestureEnabled: false }} />
        <Stack.Screen name="lock" options={{ headerShown: false, gestureEnabled: false }} />
        <Stack.Screen name="program/[slug]" options={{ title: t('tabs.programs') }} />
        <Stack.Screen name="passport/[passportNo]" options={{ title: t('passports.title') }} />
        <Stack.Screen name="documents" options={{ title: t('documents.title') }} />
        <Stack.Screen name="notifications" options={{ title: t('notifications.title') }} />
        <Stack.Screen name="support" options={{ title: t('support.title') }} />
        <Stack.Screen name="mfa-setup" options={{ title: t('mfa.setupTitle') }} />
        <Stack.Screen name="delete-account" options={{ title: t('deleteAccount.title') }} />
        <Stack.Screen name="disclosure" options={{ title: t('disclosure.title'), presentation: 'modal' }} />
      </Stack>
    </View>
  );
}

export default function RootLayout() {
  const [fontsLoaded, fontError] = useFonts({
    Fraunces_400Regular,
    Fraunces_600SemiBold,
    Inter_400Regular,
    Inter_500Medium,
    Inter_600SemiBold,
    IBMPlexMono_400Regular,
    IBMPlexMono_500Medium,
  });
  const ready = fontsLoaded || !!fontError;

  useEffect(() => {
    if (ready) void SplashScreen.hideAsync().catch(() => undefined);
  }, [ready]);

  if (!ready) return null;

  return (
    <SafeAreaProvider>
      <StatusBar style="light" />
      <PreferencesProvider>
        <ConfigProvider>
          <SessionProvider>
            <RootNavigator />
          </SessionProvider>
        </ConfigProvider>
      </PreferencesProvider>
    </SafeAreaProvider>
  );
}
