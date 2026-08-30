import { useState, useEffect } from 'react';
import { useAuth } from '@/hooks/use-auth';
import { api } from '@/lib/api';
import { logger } from '@/lib/logger';

const DEFAULT_CONFIG = {
  upload: {
    maxConcurrentUploads: 4,
    maxParallelFileUploads: 2,
    comicFormats: ["cbz"],
  },
  // Empty until the server says otherwise, so a lookup is never offered against
  // a provider nobody configured.
  metadataProviders: [],
};

export function useConfig() {
  const { user } = useAuth();
  // One piece of state for the whole outcome, tagged with the user it belongs
  // to. Loading is then something to derive rather than a third flag to keep in
  // step, and a response that arrives after the account changed is ignored
  // instead of being shown as the new user's configuration.
  const [result, setResult] = useState(null);

  useEffect(() => {
    if (!user) return undefined;

    let ignore = false;
    api.get('/api/config')
      .then((data) => {
        logger.log('Config received from server:', data);
        if (!ignore) setResult({ forUser: user, config: data, error: null });
      })
      .catch((err) => {
        logger.error('Error fetching configuration:', err);
        if (!ignore) setResult({ forUser: user, config: null, error: err.message });
      });

    return () => { ignore = true; };
  }, [user]);

  const current = result?.forUser === user ? result : null;

  return {
    config: current?.config ?? DEFAULT_CONFIG,
    isLoading: Boolean(user) && current === null,
    error: current?.error ?? null
  };
}
