import { useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { api } from '@/api';
import type { AppNotification } from '@/api/types';
import { Screen } from '@/components/Screen';
import { Body, Heading, Mono, Muted } from '@/components/Typography';
import { Button, Card, EmptyState, ErrorState, LoadingState } from '@/components/ui';
import { useAsync } from '@/hooks/useAsync';
import { registerForPush, type PushResult } from '@/notifications/push';
import { colors, space } from '@/theme/tokens';

export default function Notifications() {
  const { t } = useTranslation();
  const q = useAsync(() => api.getNotifications(), []);
  const [read, setRead] = useState<Set<string>>(new Set());
  const [push, setPush] = useState<PushResult | null>(null);
  const [pushBusy, setPushBusy] = useState(false);

  const markRead = (n: AppNotification) => {
    if (n.read || read.has(String(n.id))) return;
    setRead((s) => new Set(s).add(String(n.id)));
    api.markNotificationRead(n.id).catch(() => undefined);
  };

  const pushMessage: Record<PushResult, string> = {
    registered: t('notifications.pushEnabled'),
    local_only: t('notifications.pushNotConfigured'),
    denied: t('notifications.pushDenied'),
    unavailable: t('notifications.pushUnavailable'),
  };

  return (
    <Screen edges={['bottom']} refreshing={q.refreshing} onRefresh={q.refresh}>
      <Card style={{ marginBottom: space.lg }}>
        <Button
          label={t('notifications.enablePush')}
          icon="bell"
          variant="secondary"
          loading={pushBusy}
          onPress={async () => {
            setPushBusy(true);
            setPush(await registerForPush().catch(() => 'unavailable' as const));
            setPushBusy(false);
          }}
        />
        {push ? (
          <Muted style={{ marginTop: space.sm }} accessibilityLiveRegion="polite">
            {pushMessage[push]}
          </Muted>
        ) : null}
      </Card>

      {q.loading ? (
        <LoadingState />
      ) : q.error ? (
        <ErrorState error={q.error} onRetry={q.reload} />
      ) : !q.data?.length ? (
        <EmptyState icon="bell" message={t('notifications.empty')} />
      ) : (
        q.data.map((n) => {
          const unread = !n.read && !read.has(String(n.id));
          return (
            <Pressable
              key={String(n.id)}
              accessibilityRole="button"
              accessibilityLabel={`${unread ? `${t('notifications.unread')}. ` : ''}${n.title}. ${n.body ?? ''}`}
              accessibilityHint={unread ? t('notifications.markRead') : undefined}
              onPress={() => markRead(n)}
              style={{ marginBottom: space.md }}
            >
              <Card accent={unread ? colors.copper : undefined}>
                <View style={st.head}>
                  {unread ? <View style={st.dot} /> : null}
                  <Heading style={{ flex: 1 }}>{n.title}</Heading>
                  {n.category ? <Mono style={st.cat}>{n.category.toUpperCase()}</Mono> : null}
                </View>
                {n.body ? <Body style={{ marginTop: space.xs, fontSize: 14 }}>{n.body}</Body> : null}
                <Muted style={{ marginTop: space.sm, fontSize: 12 }}>{n.created_at ?? t('common.dateTbd')}</Muted>
              </Card>
            </Pressable>
          );
        })
      )}
    </Screen>
  );
}

const st = StyleSheet.create({
  head: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
  dot: { width: 8, height: 8, borderRadius: 4, backgroundColor: colors.copper },
  cat: { fontSize: 10, color: colors.muted, letterSpacing: 1 },
});
