import { useState, useEffect } from 'react';
import { useAuth } from './use-auth';

export function usePendingShares() {
  const [pendingShares, setPendingShares] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const { isAuthenticated } = useAuth();

  const fetchPendingShares = async () => {
    if (!isAuthenticated) {
      setPendingShares([]);
      setLoading(false);
      return;
    }

    setLoading(true);
    setError(null);

    try {
      const response = await fetch('/api/share/pending', {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
        },
      });
      
      if (!response.ok) {
        const errorData = await response.json().catch(() => ({ message: 'Failed to fetch pending shares' }));
        throw new Error(errorData.message || 'Failed to fetch pending shares');
      }

      const data = await response.json();
      setPendingShares(data.pendingShares || []);
    } catch (err) {
      console.error('Error fetching pending shares:', err);
      setError(err.message || 'Failed to fetch pending shares');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (isAuthenticated) {
      fetchPendingShares();
    }
  }, [isAuthenticated]);

  return {
    pendingShares,
    loading,
    error,
    refetch: fetchPendingShares
  };
}
