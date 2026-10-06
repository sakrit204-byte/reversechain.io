import { useCallback, useEffect, useRef, useState } from 'react';

export interface AsyncState<T> {
  data: T | null;
  error: unknown;
  loading: boolean;
  refreshing: boolean;
  reload: () => void;
  refresh: () => Promise<void>;
}

interface Result<T> {
  key: string | null;
  data: T | null;
  error: unknown;
}

/**
 * Minimal data-loading hook: load on mount / when deps change, retry, pull-to-refresh.
 * `deps` must be serialisable primitives (slugs, ids, booleans).
 */
export function useAsync<T>(fn: () => Promise<T>, deps: readonly unknown[]): AsyncState<T> {
  const [nonce, setNonce] = useState(0);
  const [result, setResult] = useState<Result<T>>({ key: null, data: null, error: null });
  const [refreshing, setRefreshing] = useState(false);
  const fnRef = useRef(fn);
  const key = `${JSON.stringify(deps)}#${nonce}`;

  useEffect(() => {
    fnRef.current = fn;
  });

  useEffect(() => {
    let alive = true;
    fnRef.current().then(
      (data) => alive && setResult({ key, data, error: null }),
      (error: unknown) => alive && setResult({ key, data: null, error }),
    );
    return () => {
      alive = false;
    };
  }, [key]);

  const refresh = useCallback(async () => {
    setRefreshing(true);
    try {
      const data = await fnRef.current();
      setResult((r) => ({ key: r.key, data, error: null }));
    } catch (error) {
      setResult((r) => ({ key: r.key, data: r.data, error }));
    } finally {
      setRefreshing(false);
    }
  }, []);

  const loading = result.key !== key;
  return {
    data: loading ? null : result.data,
    error: loading ? null : result.error,
    loading,
    refreshing,
    reload: () => setNonce((n) => n + 1),
    refresh,
  };
}
