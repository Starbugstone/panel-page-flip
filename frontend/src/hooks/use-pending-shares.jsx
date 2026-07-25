import { useCallback, useState, useEffect } from 'react';
import { useAuth } from './use-auth';
import { api } from '@/lib/api';
import { logger } from '@/lib/logger';

export function usePendingShares() {
  const [pendingShares, setPendingShares] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const { isAuthenticated } = useAuth();

  const fetchPendingShares = useCallback(async () => {
    if (!isAuthenticated) {
      setPendingShares([]);
      setLoading(false);
      return;
    }

    setLoading(true);
    setError(null);

    try {
      const data = await api.get('/api/share/pending');
      setPendingShares(data.pendingShares || []);
    } catch (err) {
      logger.error('Error fetching pending shares:', err);
      setError(err.message || 'Failed to fetch pending shares');
    } finally {
      setLoading(false);
    }
  }, [isAuthenticated]);

  useEffect(() => {
    if (isAuthenticated) {
      fetchPendingShares();
    }
  }, [fetchPendingShares, isAuthenticated]);

  return {
    pendingShares,
    loading,
    error,
    refetch: fetchPendingShares
  };
}
