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

function emptySharingLists(user, byMeUrl, withMeUrl, error = null) {
  return {
    forUser: user,
    byMe: EMPTY_LIST,
    withMe: EMPTY_LIST,
    byMePagination: EMPTY_PAGINATION,
    withMePagination: EMPTY_PAGINATION,
    byMeUrl,
    withMeUrl,
    byMeListKey: error ? `${byMeUrl}|error` : byMeUrl,
    withMeListKey: error ? `${withMeUrl}|error` : withMeUrl,
    error,
  };
}

function visibleSharingLists(current, byMeUrl, withMeUrl, isAuthenticated, byMeSearchInput, withMeSearchInput) {
  const byMe = visibleSharingTable(current, "byMe", byMeUrl, isAuthenticated);
  const withMe = visibleSharingTable(current, "withMe", withMeUrl, isAuthenticated);

  return {
    sharedByMe: byMe.rows,
    sharedWithMe: withMe.rows,
    byMePagination: byMe.pagination,
    withMePagination: withMe.pagination,
    byMeListKey: byMe.listKey,
    withMeListKey: withMe.listKey,
    byMeIsLoading: byMe.isLoading,
    withMeIsLoading: withMe.isLoading,
    byMeSearchInput,
    withMeSearchInput,
    isLoading: isAuthenticated && current === null,
    error: current?.error ?? null,
  };
}

function visibleSharingTable(current, prefix, url, isAuthenticated) {
  const matches = current?.[`${prefix}Url`] === url;

  return {
    rows: current?.[prefix] ?? EMPTY_LIST,
    pagination: current?.[`${prefix}Pagination`] ?? EMPTY_PAGINATION,
    listKey: matches ? current[`${prefix}ListKey`] : url,
    isLoading: isAuthenticated && !matches,
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
  const [result, setResult] = useState(null);
  const { isAuthenticated, user } = useAuth();
  const account = isAuthenticated ? user : null;
  const activeAccount = useRef(null);
  const requestIdRef = useRef(0);

  // Invalidate both in-flight requests and callbacks captured by an old action
  // before effects from the next session can run.
  useLayoutEffect(() => {
    activeAccount.current = account;
    return () => {
      activeAccount.current = null;
      requestIdRef.current += 1;
    };
  }, [account]);

  const refreshSummary = useCallback(() => {
    if (!account || activeAccount.current !== account) return Promise.resolve();
    const requestId = ++requestIdRef.current;

    return api.get('/api/shares/summary')
      .then((data) => {
        if (activeAccount.current !== account || requestIdRef.current !== requestId) return;
        setResult({
          forUser: account,
          summary: {
            pendingInvitations: data.pendingInvitations || 0,
            deadShares: data.deadShares || 0,
          },
        });
      })
      .catch((error) => {
        // Preserve this account's last known counts during a temporary failure.
        logger.error('Failed to load sharing summary:', error);
      });
  }, [account]);

  useEffect(() => { refreshSummary(); }, [refreshSummary]);

  const visibleSummary = account && result?.forUser === account ? result.summary : EMPTY_SUMMARY;

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
function usePagedSharingTable(filters) {
  const [params, setParams] = useState({ page: 1, limit: DEFAULT_PAGE_SIZE, search: '' });
  const [searchInput, setSearchInput] = useState('');
  const filterQuery = JSON.stringify(filters);
  const [appliedFilterQuery, setAppliedFilterQuery] = useState(filterQuery);

  const setPage = useCallback((page) => {
    setParams((current) => ({ ...current, page: Math.max(1, page) }));
  }, []);
  const setLimit = useCallback((limit) => {
    setParams((current) => ({ ...current, page: 1, limit }));
  }, []);

  useEffect(() => {
    const timeout = setTimeout(() => {
      setParams((current) => {
        const search = searchInput.trim();
        return current.search === search ? current : { ...current, page: 1, search };
      });
    }, SEARCH_DEBOUNCE_MS);

    return () => clearTimeout(timeout);
  }, [searchInput]);

  if (appliedFilterQuery !== filterQuery) {
    setAppliedFilterQuery(filterQuery);
    setParams((current) => (current.page === 1 ? current : { ...current, page: 1 }));
  }

  return { params, searchInput, setSearchInput, setPage, setLimit, setParams };
}

export function useSharingLists(byMeFilters = {}, withMeFilters = {}) {
  // One piece of state for the whole answer, tagged with the account it belongs
  // to. Loading and the lists then follow from it, so signing out and back in
  // cannot leave the previous session's shares on screen with nothing marked as
  // loading — the tag simply stops matching.
  const [result, setResult] = useState(null);
  const byMeTable = usePagedSharingTable(byMeFilters);
  const withMeTable = usePagedSharingTable(withMeFilters);
  const setByMeParams = byMeTable.setParams;
  const setWithMeParams = withMeTable.setParams;
  const { isAuthenticated, user } = useAuth();
  const { refreshSummary } = useSharing();
  const listRevisionRef = useRef(0);
  const requestRevisionRef = useRef(0);

  const byMeUrl = buildAdminListUrl('/api/shares/shared-by-me', byMeTable.params, byMeFilters);
  const withMeUrl = buildAdminListUrl('/api/shares/shared-with-me', withMeTable.params, withMeFilters);
  const currentRequestContext = useRef(null);
  useLayoutEffect(() => {
    currentRequestContext.current = { isAuthenticated, user, byMeUrl, withMeUrl };
  }, [byMeUrl, isAuthenticated, user, withMeUrl]);

  const applyLists = useCallback((byMe, withMe, requestedByMeUrl, requestedWithMeUrl) => {
    listRevisionRef.current += 1;
    setResult({
      forUser: user,
      byMe: byMe.sharedByMe || [],
      withMe: withMe.sharedWithMe || [],
      byMePagination: byMe.pagination || EMPTY_PAGINATION,
      withMePagination: withMe.pagination || EMPTY_PAGINATION,
      byMeUrl: requestedByMeUrl,
      withMeUrl: requestedWithMeUrl,
      byMeListKey: `${requestedByMeUrl}|${listRevisionRef.current}`,
      withMeListKey: `${requestedWithMeUrl}|${listRevisionRef.current}`,
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
    const withMeTotalPages = withMe.pagination?.totalPages;
    if (withMeTotalPages) {
      setWithMeParams((current) => (
        current.page > withMeTotalPages ? { ...current, page: withMeTotalPages } : current
      ));
    }
  }, [setByMeParams, setWithMeParams, user]);

  const applyError = useCallback((err, requestedByMeUrl, requestedWithMeUrl) => {
    logger.error('Failed to load sharing lists:', err);
    setResult(emptySharingLists(
      user,
      requestedByMeUrl,
      requestedWithMeUrl,
      err.message || 'Could not load your shared comics.'
    ));
  }, [user]);

  /**
   * Both halves in one round trip, applied only while the answer still
   * describes what is on screen.
   *
   * `requestedUrl` is passed in rather than closed over, because the two
   * callers want different things from it. The mount path asks for the URL of
   * the render it belongs to; `reload` asks for whatever the table is showing
   * *now*, since an action's callback is captured before the action finishes
   * and a bulk run can outlive a search, a filter or a page turn. Closing over
   * it would make those reloads no-ops, which is the one outcome an action
   * cannot survive: the rows it just changed would stay on screen as they were.
   *
   * The revision then orders overlapping requests, so a slow earlier one can
   * never overwrite a later answer.
   *
   * A promise chain rather than async/await, so that every setState sits
   * inside a callback: called from an effect, an awaited one reads as a
   * synchronous setState and the rule against cascading renders rejects it.
   */
  const fetchLists = useCallback((isCancelled, requestedByMeUrl, requestedWithMeUrl) => {
    const context = currentRequestContext.current;
    if (!context.isAuthenticated || context.user !== user) {
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
        || current.byMeUrl !== requestedByMeUrl
        || current.withMeUrl !== requestedWithMeUrl;
    };

    return Promise.all([
      api.get(requestedByMeUrl),
      api.get(requestedWithMeUrl),
    ])
      .then(([byMe, withMe]) => {
        if (!isStale()) applyLists(byMe, withMe, requestedByMeUrl, requestedWithMeUrl);
      })
      .catch((err) => {
        if (!isStale()) applyError(err, requestedByMeUrl, requestedWithMeUrl);
      })
      // The counts come from the same records, so refreshing them here keeps the
      // badge honest without another round of coordination.
      .finally(() => { if (!isStale()) refreshSummary(); });
  }, [applyError, applyLists, refreshSummary, user]);

  const reload = useCallback(async () => {
    const current = currentRequestContext.current;
    if (!current.isAuthenticated) {
      requestRevisionRef.current += 1;
      setResult(emptySharingLists(current.user, current.byMeUrl, current.withMeUrl));
      return;
    }

    await fetchLists(() => false, current.byMeUrl, current.withMeUrl);
  }, [fetchLists]);

  // As above: reload is for the page's own actions, the mount path asks
  // directly so nothing is set before the request exists. Turning the page
  // lands here too, because it changes the URL the fetch depends on.
  useEffect(() => {
    if (!isAuthenticated) return undefined;

    let ignore = false;
    fetchLists(() => ignore, byMeUrl, withMeUrl);

    return () => { ignore = true; };
  }, [byMeUrl, fetchLists, isAuthenticated, withMeUrl]);

  const current = currentSharingResult(result, user);
  const visible = visibleSharingLists(
    current,
    byMeUrl,
    withMeUrl,
    isAuthenticated,
    byMeTable.searchInput,
    withMeTable.searchInput,
  );

  return {
    ...visible,
    reload,
    setByMeSearchInput: byMeTable.setSearchInput,
    setByMePage: byMeTable.setPage,
    setByMeLimit: byMeTable.setLimit,
    setWithMeSearchInput: withMeTable.setSearchInput,
    setWithMePage: withMeTable.setPage,
    setWithMeLimit: withMeTable.setLimit,
  };
}
