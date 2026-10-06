import { useCallback, useEffect, useState } from 'react';
import { View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { authenticateBiometric } from '@/auth/biometrics';
import { useSession } from '@/auth/SessionProvider';
import { Icon } from '@/components/Icon';
import { Screen } from '@/components/Screen';
import { Display, Muted } from '@/components/Typography';
import { Button } from '@/components/ui';
import { colors, space } from '@/theme/tokens';

/** Biometric gate shown on cold start / resume when biometric unlock is enabled. */
export default function Lock() {
  const { t } = useTranslation();
  const { unlock, logout } = useSession();
  const [failed, setFailed] = useState(false);
  const [busy, setBusy] = useState(false);

  const attempt = useCallback(async () => {
    setBusy(true);
    const ok = await authenticateBiometric(t('lock.prompt'), t('common.cancel'));
    if (ok) await unlock();
    else setFailed(true);
    setBusy(false);
  }, [t, unlock]);

  // Prompt automatically once on mount.
  useEffect(() => {
    let alive = true;
    authenticateBiometric(t('lock.prompt'), t('common.cancel')).then(async (ok) => {
      if (!alive) return;
      if (ok) await unlock();
      else setFailed(true);
    });
    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <Screen edges={['top', 'bottom']} scroll={false} contentStyle={{ justifyContent: 'center' }}>
      <View style={{ alignItems: 'center' }}>
        <Icon name="lock" size={48} color={colors.copperLight} />
        <Display style={{ marginTop: space.lg, textAlign: 'center' }}>{t('lock.title')}</Display>
        <Muted style={{ marginTop: space.sm, textAlign: 'center' }}>{t('lock.subtitle')}</Muted>
        {failed ? (
          <Muted style={{ marginTop: space.md, color: colors.amber }} accessibilityLiveRegion="polite">
            {t('lock.failed')}
          </Muted>
        ) : null}
      </View>
      <Button label={t('lock.unlock')} onPress={attempt} loading={busy} style={{ marginTop: space.xxl }} />
      <Button label={t('common.signOut')} variant="ghost" onPress={() => void logout('user')} style={{ marginTop: space.sm }} />
    </Screen>
  );
}
