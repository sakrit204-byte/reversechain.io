/** The MOCK DATA banner is on in every mock build unless the screenshot-only flag is set. */
import { render } from '@testing-library/react-native';
import '@/i18n';
import { MockBanner } from '@/components/Screen';

// Mutable stand-in for the build-time env (read by MockBanner at render time).
jest.mock('@/config/env', () => ({
  env: { apiUrl: 'http://localhost', mock: true, inactivityMinutes: 5, hideMockBanner: false },
}));
const env = (jest.requireMock('@/config/env') as { env: { mock: boolean; hideMockBanner: boolean } }).env;

describe('MockBanner', () => {
  it('shows in a normal mock build (flag defaults off)', () => {
    Object.assign(env, { mock: true, hideMockBanner: false });
    expect(render(<MockBanner />).queryByText('MOCK DATA')).toBeTruthy();
  });

  it('is hidden only when EXPO_PUBLIC_HIDE_MOCK_BANNER=1 (store screenshot builds)', () => {
    Object.assign(env, { mock: true, hideMockBanner: true });
    expect(render(<MockBanner />).queryByText('MOCK DATA')).toBeNull();
  });

  it('never renders in a live build', () => {
    Object.assign(env, { mock: false, hideMockBanner: false });
    expect(render(<MockBanner />).queryByText('MOCK DATA')).toBeNull();
  });

  it('the real env flag defaults to off', () => {
    const prev = process.env.EXPO_PUBLIC_HIDE_MOCK_BANNER;
    delete process.env.EXPO_PUBLIC_HIDE_MOCK_BANNER;
    jest.isolateModules(() => {
      const real = jest.requireActual('@/config/env') as { env: { hideMockBanner: boolean } };
      expect(real.env.hideMockBanner).toBe(false);
    });
    if (prev !== undefined) process.env.EXPO_PUBLIC_HIDE_MOCK_BANNER = prev;
  });
});
