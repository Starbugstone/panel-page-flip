import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { useAuth } from './use-auth';
import { api } from '@/lib/api';
import { logger } from '@/lib/logger';

const SharingContext = createContext(undefined);

const EMPTY_SUMMARY = { pendingInvitations: 0, deadShares: 0 };
// Stable identity so a signed-out render does not hand consumers a new array.
const EMPTY_LIST = [];

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
  // Every read of the counts takes a number, and only the newest is allowed to
  // write. Without it a slow request started before an invitation was accepted
  // — or before logging out — could land afterwards and restore counts that are
  // no longer true.
  const requestIdRef = useRef(0);

  // Depends on authentication and nothing else, so its identity is stable while
  // a session lasts. Callers put it in their own dependency lists; if it changed
  // whenever the counts did, every one of them would refetch in a loop.
  const refreshSummary = useCallback(async () => {
    const requestId = requestIdRef.current + 1;
    requestIdRef.current = requestId;

    if (!isAuthenticated) {
      setSummary(EMPTY_SUMMARY);
      return;
    }

    try {
      const data = await api.get('/api/shares/summary');
      if (requestIdRef.current !== requestId) return;

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

  // refreshSummary is what consumers call after an action, where clearing the
  // counts synchronously is fine. Mounting issues the request directly instead,
  // so the provider does not render twice before anything has been asked for,
  // and the signed-out counts are derived below rather than written.
  useEffect(() => {
    if (!isAuthenticated) return undefined;

    const requestId = requestIdRef.current + 1;
    requestIdRef.current = requestId;

    let ignore = false;
    api.get('/api/shares/summary')
      .then((data) => {
        if (ignore || requestIdRef.current !== requestId) return;
        setSummary({
          pendingInvitations: data.pendingInvitations || 0,
          deadShares: data.deadShares || 0,
        });
      })
      .catch((error) => {
        // A badge is not worth a toast; see refreshSummary above.
        logger.error('Failed to load sharing summary:', error);
      });

    return () => { ignore = true; };
  }, [isAuthenticated]);

  const visibleSummary = isAuthenticated ? summary : EMPTY_SUMMARY;

  const value = useMemo(
    () => ({ summary: visibleSummary, refreshSummary }),
    [visibleSummary, refreshSummary]
  );

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
  // "Has an answer arrived" rather than "is a request outstanding": the second
  // has to be set true before every request and false after every outcome, and
  // the first render happens before any of that.
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState(null);
  const { isAuthenticated } = useAuth();
  const { refreshSummary } = useSharing();

  const reload = useCallback(async () => {
    if (!isAuthenticated) {
      setSharedByMe([]);
      setSharedWithMe([]);
      setLoaded(true);
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
      setLoaded(true);
      // The counts come from the same records, so refreshing them here keeps
      // the badge honest without another round of coordination.
      refreshSummary();
    }
  }, [isAuthenticated, refreshSummary]);

  // As above: reload is for the page's own actions, the mount path asks
  // directly so nothing is set before the request exists.
  useEffect(() => {
    if (!isAuthenticated) return undefined;

    let ignore = false;
    Promise.all([
      api.get('/api/shares/shared-by-me'),
      api.get('/api/shares/shared-with-me'),
    ])
      .then(([byMe, withMe]) => {
        if (ignore) return;
        setSharedByMe(byMe.sharedByMe || []);
        setSharedWithMe(withMe.sharedWithMe || []);
        setError(null);
      })
      .catch((err) => {
        if (ignore) return;
        logger.error('Failed to load sharing lists:', err);
        setError(err.message || 'Could not load your shared comics.');
      })
      .finally(() => {
        if (ignore) return;
        setLoaded(true);
        refreshSummary();
      });

    return () => { ignore = true; };
  }, [isAuthenticated, refreshSummary]);

  // Nothing to wait for when signed out, and the lists are empty regardless of
  // what the previous session left behind.
  const isLoading = isAuthenticated && !loaded;

  return {
    sharedByMe: isAuthenticated ? sharedByMe : EMPTY_LIST,
    sharedWithMe: isAuthenticated ? sharedWithMe : EMPTY_LIST,
    isLoading,
    error,
    reload,
  };
}
