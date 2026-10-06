import { Pressable, StyleSheet, View } from 'react-native';
import { useRouter } from 'expo-router';
import type { Program } from '@/api/types';
import { colors, space } from '@/theme/tokens';
import { ElementTile } from './ElementTile';
import { Icon } from './Icon';
import { StatusPill } from './StatusPill';
import { Muted, Title } from './Typography';
import { Card } from './ui';

export function ProgramCard({ program }: { program: Program }) {
  const router = useRouter();
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={program.name}
      onPress={() => router.push({ pathname: '/program/[slug]', params: { slug: program.slug } })}
      style={({ pressed }) => [{ marginBottom: space.md }, pressed && { opacity: 0.85 }]}
    >
      <Card style={st.card}>
        <ElementTile symbol={program.symbol} atomicNumber={program.atomic_number} name={program.name} size={76} />
        <View style={{ flex: 1 }}>
          <Title style={{ fontSize: 20 }}>{program.name}</Title>
          <View style={{ marginTop: space.sm }}>
            <StatusPill status={program.status} />
          </View>
          {program.summary ? (
            <Muted numberOfLines={3} style={{ marginTop: space.sm }}>
              {program.summary}
            </Muted>
          ) : null}
        </View>
        <Icon name="chevron" size={16} color={colors.muted} />
      </Card>
    </Pressable>
  );
}

const st = StyleSheet.create({
  card: { flexDirection: 'row', gap: space.lg, alignItems: 'center' },
});
