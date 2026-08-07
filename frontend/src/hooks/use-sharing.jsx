import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { useAuth } from './use-auth';
import { api } from '@/lib/api';
import { logger } from '@/lib/logger';

const SharingContext = createContext(undefined);

const EMPTY_SUMMARY = { pendingInvitations: 0, deadShares: 0 };

/**
 * Holds the sharing counts the header badge and the dashboard alert both read.
 *
 * Shared rather than fetched twice: two components asking the same question on
 * every navigation is two requests for one answer, and they would be free to
 * disagree while one of them was in flight.
 *
 * The Sharing page fetches its own detail through {@link useSharingLists} and
 * calls `refreshSummary` after anything that changes a count.
 */
export function SharingProvider({ children }) {
  const [summary, setSummary] = useState(EMPTY_SUMMARY);
  const { isAuthenticated } = useAuth();

  // Depends on authentication and nothing else, so its identity is stable while
  // a session lasts. Callers put it in their own dependency lists; if it changed
  // whenever the counts did, every one of them would refetch in a loop.
  const refreshSummary = useCallback(async () => {
    if (!isAuthenticated) {
      setSummary(EMPTY_SUMMARY);
      return;
    }

    try {
      const data = await api.get('/api/shares/summary');
      setSummary({
        pendingInvitations: data.pendingInvitations || 0,
        deadShares: data.deadShares || 0,
      });
    } catch (error) {
      // A badge is not worth a toast. Keeping the last known counts is a better
      // answer than replacing them with zeros the user would read as "nothing
      // pending".
      logger.error('Failed to load sharing summary:', error);
    }
  }, [isAuthenticated]);

  useEffect(() => {
    refreshSummary();
  }, [refreshSummary]);

  const value = useMemo(() => ({ summary, refreshSummary }), [summary, refreshSummary]);

  return <SharingContext.Provider value={value}>{children}</SharingContext.Provider>;
}

export function useSharing() {
  const context = useContext(SharingContext);
  if (context === undefined) {
    throw new Error('useSharing must be used within a SharingProvider');
  }
  return context;
}

/**
 * Both halves of the Sharing page, loaded together.
 *
 * One reload after every action rather than patching individual rows: an action
 * on one side routinely changes the other (revoking moves a recipient's comic
 * to a dead entry), and the lists are small enough that refetching is cheaper
 * than keeping two views of the same records in step.
 */
export function useSharingLists() {
  const [sharedByMe, setSharedByMe] = useState([]);
  const [sharedWithMe, setSharedWithMe] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);
  const { isAuthenticated } = useAuth();
  const { refreshSummary } = useSharing();

  const reload = useCallback(async () => {
    if (!isAuthenticated) {
      setSharedByMe([]);
      setSharedWithMe([]);
      setIsLoading(false);
      return;
    }

    setError(null);
    try {
      const [byMe, withMe] = await Promise.all([
        api.get('/api/shares/shared-by-me'),
        api.get('/api/shares/shared-with-me'),
      ]);
      setSharedByMe(byMe.sharedByMe || []);
      setSharedWithMe(withMe.sharedWithMe || []);
    } catch (err) {
      logger.error('Failed to load sharing lists:', err);
      setError(err.message || 'Could not load your shared comics.');
    } finally {
      setIsLoading(false);
      // The counts come from the same records, so refreshing them here keeps
      // the badge honest without another round of coordination.
      refreshSummary();
    }
  }, [isAuthenticated, refreshSummary]);

  useEffect(() => {
    reload();
  }, [reload]);

  return { sharedByMe, sharedWithMe, isLoading, error, reload };
}
