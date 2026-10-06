import { Platform } from 'react-native';
import * as LocalAuthentication from 'expo-local-authentication';

/** True when the device has biometric hardware AND at least one enrolled biometric. */
export async function isBiometricAvailable(): Promise<boolean> {
  if (Platform.OS === 'web') return false;
  try {
    const [hw, enrolled] = await Promise.all([
      LocalAuthentication.hasHardwareAsync(),
      LocalAuthentication.isEnrolledAsync(),
    ]);
    return hw && enrolled;
  } catch {
    return false;
  }
}

export async function authenticateBiometric(prompt: string, cancelLabel: string): Promise<boolean> {
  try {
    const r = await LocalAuthentication.authenticateAsync({
      promptMessage: prompt,
      cancelLabel,
      // Device passcode fallback keeps users from being locked out; the session itself is still
      // protected by server-side token expiry.
      disableDeviceFallback: false,
    });
    return r.success;
  } catch {
    return false;
  }
}
