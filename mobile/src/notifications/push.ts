import { Platform } from 'react-native';
import Constants, { ExecutionEnvironment } from 'expo-constants';
import * as Device from 'expo-device';
import { api, ApiError } from '@/api';

export type PushResult = 'registered' | 'local_only' | 'denied' | 'unavailable';

/**
 * Expo Go (SDK 53+) no longer ships remote-notification support on Android and throws as soon as
 * expo-notifications is imported. Push is therefore loaded lazily and only in real (EAS/dev) builds.
 */
const isExpoGo = Constants.executionEnvironment === ExecutionEnvironment.StoreClient;

type NotificationsModule = typeof import('expo-notifications');
function loadNotifications(): NotificationsModule | null {
  if (Platform.OS === 'web' || isExpoGo) return null;
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    return require('expo-notifications') as NotificationsModule;
  } catch {
    return null;
  }
}

/**
 * Push registration scaffold.
 * 1. Requests permission, 2. obtains an Expo push token (requires an EAS projectId and, for
 * Android, a ReserveChain-owned Firebase project / google-services.json), 3. posts it to
 * POST /me/devices {push_token}. The backend call is guarded: a missing endpoint (404/405) or
 * offline backend leaves the token registered locally only.
 */
export async function registerForPush(): Promise<PushResult> {
  const Notifications = loadNotifications();
  if (!Notifications || !Device.isDevice) return 'unavailable';

  if (Platform.OS === 'android') {
    await Notifications.setNotificationChannelAsync('default', {
      name: 'ReserveChain',
      importance: Notifications.AndroidImportance.DEFAULT,
      lightColor: '#C46A3A',
    });
  }

  const existing = await Notifications.getPermissionsAsync();
  let granted = existing.granted;
  if (!granted) {
    const req = await Notifications.requestPermissionsAsync();
    granted = req.granted;
  }
  if (!granted) return 'denied';

  const projectId =
    (Constants.expoConfig?.extra as { eas?: { projectId?: string } } | undefined)?.eas?.projectId ??
    Constants.easConfig?.projectId;
  if (!projectId || projectId.startsWith('REPLACE_')) return 'local_only';

  let token: string;
  try {
    token = (await Notifications.getExpoPushTokenAsync({ projectId })).data;
  } catch {
    return 'local_only';
  }

  try {
    await api.registerDevice(token);
    return 'registered';
  } catch (e) {
    // Endpoint missing (404/405), offline (0) or rejected: keep the token local, never crash.
    if (e instanceof ApiError && __DEV__) console.warn(`Push registration skipped: ${e.status}`);
    return 'local_only';
  }
}

/** Foreground presentation: show banners for notifications received while the app is open. */
export function configureNotificationHandler(): void {
  const Notifications = loadNotifications();
  if (!Notifications) return;
  Notifications.setNotificationHandler({
    handleNotification: async () => ({
      shouldShowBanner: true,
      shouldShowList: true,
      shouldPlaySound: false,
      shouldSetBadge: false,
    }),
  });
}
