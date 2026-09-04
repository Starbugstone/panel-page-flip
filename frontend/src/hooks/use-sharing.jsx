import { createContext, useCallback, useContext, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { useAuth } from './use-auth';
import { api } from '@/lib/api';
import { buildAdminListUrl, DEFAULT_PAGE_SIZE } from '@/lib/admin-list-params';
import { logger } from '@/lib/logger';

const SharingContext = createContext(undefined);

const EMPTY_SUMMARY = { pendingInvitations: 0, deadShares: 0 };
// Stable identities so a signed-out render does not hand consumers new objects.
const EMPTY_LIST = [];
const EMPTY_PAGINATION = { page: 1, limit: DEFAULT_PAGE_SIZE, totalItems: 0, totalPages: 1 };
const SEARCH_DEBOUNCE_MS = 300;

function emptySharingLists(user, byMeUrl, error = null) {
  return {
    forUser: user,
    byMe: EMPTY_LIST,
    withMe: EMPTY_LIST,
    byMePagination: EMPTY_PAGINATION,
    byMeUrl,
    byMeListKey: error ? `${byMeUrl}|error` : byMeUrl,
    error,
  };
}

function visibleSharingLists(current, byMeUrl, isAuthenticated, byMeSearchInput) {
  const currentUrlMatches = current?.byMeUrl === byMeUrl;

  return {
    sharedByMe: current?.byMe ?? EMPTY_LIST,
    sharedWithMe: current?.withMe ?? EMPTY_LIST,
    byMePagination: current?.byMePagination ?? EMPTY_PAGINATION,
    byMeListKey: currentUrlMatches ? current.byMeListKey : byMeUrl,
    byMeIsLoading: isAuthenticated && current?.byMeUrl !== byMeUrl,
    byMeSearchInput,
    isLoading: isAuthenticated && current === null,
    error: current?.error ?? null,
  };
}

function currentSharingResult(result, user) {
  return result?.forUser === user ? result : null;
}

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
 * to a dead entry), and refetching is cheaper than keeping two views of the
 * same records in step.
 *
 * "Shared by me" is a server-side table, so this hook also owns its search and
 * pagination. Sorts and column filters come from the table controls on the
 * page and are folded into the same request.
 */
export function useSharingLists(byMeFilters = {}) {
  // One piece of state for the whole answer, tagged with the account it belongs
  // to. Loading and the lists then follow from it, so signing out and back in
  // cannot leave the previous session's shares on screen with nothing marked as
  // loading — the tag simply stops matching.
  const [result, setResult] = useState(null);
  const [byMeParams, setByMeParams] = useState({ page: 1, limit: DEFAULT_PAGE_SIZE, search: '' });
  const [byMeSearchInput, setByMeSearchInput] = useState('');
  const { isAuthenticated, user } = useAuth();
  const { refreshSummary } = useSharing();
  const filterQuery = JSON.stringify(byMeFilters);
  const lastFilterQuery = useRef(filterQuery);
  const listRevisionRef = useRef(0);
  const requestRevisionRef = useRef(0);

  const setByMePage = useCallback((page) => {
    setByMeParams((current) => ({ ...current, page: Math.max(1, page) }));
  }, []);
  // A new page size starts again from the first page; staying on page 6 of a
  // result set that now has two pages shows nothing.
  const setByMeLimit = useCallback((limit) => {
    setByMeParams((current) => ({ ...current, page: 1, limit }));
  }, []);

  useEffect(() => {
    const timeout = setTimeout(() => {
      setByMeParams((current) => {
        const search = byMeSearchInput.trim();
        return current.search === search ? current : { ...current, page: 1, search };
      });
    }, SEARCH_DEBOUNCE_MS);

    return () => clearTimeout(timeout);
  }, [byMeSearchInput]);

  useEffect(() => {
    if (lastFilterQuery.current === filterQuery) return;
    lastFilterQuery.current = filterQuery;
    setByMeParams((current) => (current.page === 1 ? current : { ...current, page: 1 }));
  }, [filterQuery]);

  const byMeUrl = buildAdminListUrl(
    '/api/shares/shared-by-me',
    byMeParams,
    JSON.parse(filterQuery)
  );
  const currentRequestContext = useRef(null);
  useLayoutEffect(() => {
    currentRequestContext.current = { isAuthenticated, user, byMeUrl };
  }, [byMeUrl, isAuthenticated, user]);

  const applyLists = useCallback((byMe, withMe) => {
    listRevisionRef.current += 1;
    setResult({
      forUser: user,
      byMe: byMe.sharedByMe || [],
      withMe: withMe.sharedWithMe || [],
      byMePagination: byMe.pagination || EMPTY_PAGINATION,
      byMeUrl,
      byMeListKey: `${byMeUrl}|${listRevisionRef.current}`,
      error: null,
    });

    // The page that was asked for can stop existing — deleting the last share
    // record on the last page shrinks the set. Landing on the new last page
    // beats rendering "you have not shared any comics yet" over a list that
    // still has comics on its earlier pages.
    const totalPages = byMe.pagination?.totalPages;
    if (totalPages) {
      setByMeParams((current) => (current.page > totalPages ? { ...current, page: totalPages } : current));
    }
  }, [byMeUrl, user]);

  const applyError = useCallback((err) => {
    logger.error('Failed to load sharing lists:', err);
    setResult(emptySharingLists(
      user,
      byMeUrl,
      err.message || 'Could not load your shared comics.'
    ));
  }, [byMeUrl, user]);

  /**
   * Both halves in one round trip, applied only while this request still owns
   * the current account and URL.
   *
   * The revision also orders overlapping reloads for the same URL. URL and
   * account refs are checked separately because an action can retain an old
   * reload callback across a table navigation.
   *
   * A promise chain rather than async/await, so that every setState sits
   * inside a callback: called from an effect, an awaited one reads as a
   * synchronous setState and the rule against cascading renders rejects it.
   */
  const fetchLists = useCallback((isCancelled = () => false) => {
    const context = currentRequestContext.current;
    if (!context.isAuthenticated || context.user !== user || context.byMeUrl !== byMeUrl) {
      return Promise.resolve();
    }

    const requestRevision = requestRevisionRef.current + 1;
    requestRevisionRef.current = requestRevision;
    const isStale = () => {
      const current = currentRequestContext.current;

      return isCancelled()
        || requestRevisionRef.current !== requestRevision
        || !current.isAuthenticated
        || current.user !== user
        || current.byMeUrl !== byMeUrl;
    };

    return Promise.all([
      api.get(byMeUrl),
      api.get('/api/shares/shared-with-me'),
    ])
      .then(([byMe, withMe]) => { if (!isStale()) applyLists(byMe, withMe); })
      .catch((err) => { if (!isStale()) applyError(err); })
      // The counts come from the same records, so refreshing them here keeps the
      // badge honest without another round of coordination.
      .finally(() => { if (!isStale()) refreshSummary(); });
  }, [applyError, applyLists, byMeUrl, refreshSummary, user]);

  const reload = useCallback(async () => {
    const current = currentRequestContext.current;
    if (!current.isAuthenticated) {
      requestRevisionRef.current += 1;
      setResult(emptySharingLists(current.user, current.byMeUrl));
      return;
    }

    await fetchLists();
  }, [fetchLists]);

  // As above: reload is for the page's own actions, the mount path asks
  // directly so nothing is set before the request exists. Turning the page
  // lands here too, because it changes the URL the fetch depends on.
  useEffect(() => {
    if (!isAuthenticated) return undefined;

    let ignore = false;
    fetchLists(() => ignore);

    return () => { ignore = true; };
  }, [fetchLists, isAuthenticated]);

  const current = currentSharingResult(result, user);
  const visible = visibleSharingLists(current, byMeUrl, isAuthenticated, byMeSearchInput);

  return {
    ...visible,
    reload,
    setByMeSearchInput,
    setByMePage,
    setByMeLimit,
  };
}
