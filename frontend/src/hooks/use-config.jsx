import { useState, useEffect } from 'react';
import { useAuth } from '@/hooks/use-auth';
import { api } from '@/lib/api';
import { logger } from '@/lib/logger';

export function useConfig() {
  const [config, setConfig] = useState({
    upload: {
      maxConcurrentUploads: 5 // Default value - matches backend default
    }
  });
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);
  const { user } = useAuth();

  useEffect(() => {
    // Only fetch config if user is authenticated
    if (!user) {
      setIsLoading(false);
      return;
    }

    const fetchConfig = async () => {
      try {
        setIsLoading(true);
        const data = await api.get('/api/config');
        logger.log('Config received from server:', data);
        setConfig(data);
        setError(null);
      } catch (err) {
        logger.error('Error fetching configuration:', err);
        setError(err.message);
      } finally {
        setIsLoading(false);
      }
    };

    fetchConfig();
  }, [user]);

  return { config, isLoading, error };
}
