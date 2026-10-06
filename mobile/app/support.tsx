import { useState } from 'react';
import { KeyboardAvoidingView, Platform } from 'react-native';
import { useRouter } from 'expo-router';
import { useTranslation } from 'react-i18next';
import { api, ApiError } from '@/api';
import { Screen } from '@/components/Screen';
import { Heading, Mono, Muted } from '@/components/Typography';
import { Button, Card, TextField } from '@/components/ui';
import { colors, space } from '@/theme/tokens';

export default function Support() {
  const { t } = useTranslation();
  const router = useRouter();
  const [subject, setSubject] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [ticket, setTicket] = useState<string | null | undefined>(undefined);

  const submit = async () => {
    if (!subject.trim() || !message.trim()) return setError(t('support.required'));
    setError(null);
    setBusy(true);
    try {
      const r = await api.submitSupport({ subject: subject.trim(), message: message.trim(), topic: 'general' });
      setTicket(r.ticket);
    } catch (e) {
      setError(e instanceof ApiError && e.status === 0 ? t('common.networkError') : t('common.errorGeneric'));
    } finally {
      setBusy(false);
    }
  };

  if (ticket !== undefined) {
    return (
      <Screen edges={['bottom']}>
        <Card accent={colors.green}>
          <Heading accessibilityLiveRegion="polite">{t('support.sent')}</Heading>
          <Muted style={{ marginTop: space.xs }}>{t('support.sentBody')}</Muted>
          {ticket ? <Mono style={{ marginTop: space.md }}>{t('support.ticket', { ticket })}</Mono> : null}
        </Card>
        <Button label={t('common.close')} onPress={() => router.back()} style={{ marginTop: space.lg }} />
      </Screen>
    );
  }

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Screen edges={['bottom']}>
        <Muted style={{ marginBottom: space.lg }}>{t('support.subtitle')}</Muted>
        <TextField label={t('support.subject')} value={subject} onChangeText={setSubject} maxLength={150} />
        <TextField
          label={t('support.message')}
          value={message}
          onChangeText={setMessage}
          multiline
          maxLength={5000}
          textAlignVertical="top"
          style={{ minHeight: 160, paddingTop: space.md }}
          error={error}
        />
        <Button label={t('support.send')} onPress={submit} loading={busy} />
      </Screen>
    </KeyboardAvoidingView>
  );
}
