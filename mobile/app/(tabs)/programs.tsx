import { useTranslation } from 'react-i18next';
import { api } from '@/api';
import { ProgramCard } from '@/components/ProgramCard';
import { Screen } from '@/components/Screen';
import { Display, Muted } from '@/components/Typography';
import { EmptyState, ErrorState, LoadingState } from '@/components/ui';
import { useAsync } from '@/hooks/useAsync';
import { space } from '@/theme/tokens';

export default function Programs() {
  const { t } = useTranslation();
  const q = useAsync(() => api.getPrograms(), []);
  return (
    <Screen refreshing={q.refreshing} onRefresh={q.refresh}>
      <Display>{t('programs.title')}</Display>
      <Muted style={{ marginTop: space.sm, marginBottom: space.xl }}>{t('programs.subtitle')}</Muted>
      {q.loading ? (
        <LoadingState />
      ) : q.error ? (
        <ErrorState error={q.error} onRetry={q.reload} />
      ) : !q.data?.length ? (
        <EmptyState message={t('common.notProvided')} />
      ) : (
        q.data.map((p) => <ProgramCard key={p.slug} program={p} />)
      )}
    </Screen>
  );
}
