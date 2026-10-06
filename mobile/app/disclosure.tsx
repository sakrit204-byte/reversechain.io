import { useTranslation } from 'react-i18next';
import { DisclosureBlock } from '@/components/Disclosure';
import { Screen } from '@/components/Screen';
import { Muted } from '@/components/Typography';
import { useAppConfig } from '@/config/ConfigProvider';
import { DISCLOSURE_VERSION } from '@/config/compliance';
import { space } from '@/theme/tokens';

/** Disclosure available at any time (account menu, login, footer strips). */
export default function DisclosureScreen() {
  const { config } = useAppConfig();
  const { t } = useTranslation();
  return (
    <Screen edges={['bottom']}>
      <DisclosureBlock serverDisclosure={config.disclosure} serverEuNotice={config.eu_notice} />
      <Muted style={{ marginTop: space.lg, fontSize: 11 }} accessibilityLabel={`${t('disclosure.title')} ${DISCLOSURE_VERSION}`}>
        v{DISCLOSURE_VERSION}
      </Muted>
    </Screen>
  );
}
