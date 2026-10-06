import { ActivityIndicator, View } from 'react-native';
import { Redirect } from 'expo-router';
import { useSession } from '@/auth/SessionProvider';
import { usePreferences } from '@/config/PreferencesProvider';
import { colors } from '@/theme/tokens';

/** Entry gate: disclosure → lock → auth → tabs. */
export default function Index() {
  const { loaded, disclosureAccepted } = usePreferences();
  const { status, guest } = useSession();

  if (!loaded || status === 'loading') {
    return (
      <View style={{ flex: 1, backgroundColor: colors.ink, alignItems: 'center', justifyContent: 'center' }}>
        <ActivityIndicator color={colors.copper} />
      </View>
    );
  }
  if (!disclosureAccepted) return <Redirect href="/onboarding" />;
  if (status === 'locked') return <Redirect href="/lock" />;
  if (status === 'signedIn' || guest) return <Redirect href="/overview" />;
  return <Redirect href="/login" />;
}
