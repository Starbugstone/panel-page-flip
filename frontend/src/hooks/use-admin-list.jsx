import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useSearchParams } from "react-router-dom";

import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { useToast } from "@/hooks/use-toast";
import {
  DEFAULT_PAGE_SIZE,
  buildAdminListUrl,
  normalizeListParams,
  readPaginationMeta,
} from "@/lib/admin-list-params";

const SEARCH_DEBOUNCE_MS = 300;

/**
 * Fetches one server-side page of an admin list.
 *
 * Page, size and search live in the URL when `urlKey` is given, so returning
 * from a user's detail page lands back on the page the admin was reading and
 * the view is linkable. Lists rendered inside another page (the comics and tags
 * tabs of a user page) leave `urlKey` off and keep their state locally, which
 * stops two lists on one screen from fighting over the same query string.
 *
 * @param {object} options
 * @param {string} options.basePath API path, e.g. "/api/users".
 * @param {Record<string, unknown>} [options.filters] Extra query parameters. Changing them resets to page 1.
 * @param {string} [options.urlKey] Prefix for the URL parameters; omit to keep state local.
 * @param {string} [options.itemsKey] Response key holding the rows, when it is not `items`.
 * @param {string} [options.errorTitle] Toast title used when the request fails.
 */
export function useAdminList({
  basePath,
  filters = {},
  urlKey,
  itemsKey = "items",
  errorTitle = "Failed to load",
}) {
  const { toast } = useToast();
  const [searchParams, setSearchParams] = useSearchParams();
  const [localParams, setLocalParams] = useState(() => normalizeListParams({ limit: DEFAULT_PAGE_SIZE }));

  const pageKey = urlKey ? `${urlKey}Page` : null;
  const limitKey = urlKey ? `${urlKey}Limit` : null;
  const searchKey = urlKey ? `${urlKey}Q` : null;

  // Read as primitives so an unrelated query parameter changing (the admin tab,
  // say) does not produce a new params object and refetch the list.
  const urlPage = urlKey ? searchParams.get(pageKey) : null;
  const urlLimit = urlKey ? searchParams.get(limitKey) : null;
  const urlSearch = urlKey ? searchParams.get(searchKey) ?? "" : "";

  const params = useMemo(() => (
    urlKey
      ? normalizeListParams({ page: urlPage, limit: urlLimit, search: urlSearch })
      : localParams
  ), [localParams, urlKey, urlLimit, urlPage, urlSearch]);

  // What the user is typing, kept separate from the committed search so every
  // keystroke does not become a request.
  const [searchInput, setSearchInput] = useState(params.search);
  const lastCommittedSearch = useRef(params.search);

  const [items, setItems] = useState([]);
  const [pagination, setPagination] = useState({ page: 1, limit: params.limit, totalItems: 0, totalPages: 1 });
  // Which request has finished, rather than a flag raised before each one and
  // lowered after it. Loading is then "the request these parameters describe
  // has not answered yet", which is true on the very first render without
  // anything having to set it.
  const [settledRequest, setSettledRequest] = useState(null);
  const [payload, setPayload] = useState(null);
  const [reloadToken, setReloadToken] = useState(0);

  const applyParams = useCallback((changes) => {
    if (!urlKey) {
      setLocalParams((current) => normalizeListParams({ ...current, ...changes }));
      return;
    }

    setSearchParams((current) => {
      const next = new URLSearchParams(current);
      const merged = normalizeListParams({
        page: changes.page ?? next.get(pageKey),
        limit: changes.limit ?? next.get(limitKey),
        search: changes.search ?? next.get(searchKey) ?? "",
      });

      merged.page === 1 ? next.delete(pageKey) : next.set(pageKey, String(merged.page));
      merged.limit === DEFAULT_PAGE_SIZE ? next.delete(limitKey) : next.set(limitKey, String(merged.limit));
      merged.search === "" ? next.delete(searchKey) : next.set(searchKey, merged.search);

      return next;
    }, { replace: true });
  }, [limitKey, pageKey, searchKey, setSearchParams, urlKey]);

  // A new search or page size starts again from the first page; staying on
  // page 6 of a result set that now has two pages shows nothing.
  const setSearch = useCallback((value) => setSearchInput(value), []);
  const setPage = useCallback((page) => applyParams({ page }), [applyParams]);
  const setLimit = useCallback((limit) => applyParams({ limit, page: 1 }), [applyParams]);
  const reload = useCallback(() => setReloadToken((token) => token + 1), []);

  useEffect(() => {
    if (searchInput === lastCommittedSearch.current) return undefined;

    const timeout = setTimeout(() => {
      lastCommittedSearch.current = searchInput;
      applyParams({ search: searchInput, page: 1 });
    }, SEARCH_DEBOUNCE_MS);

    return () => clearTimeout(timeout);
  }, [applyParams, searchInput]);

  // Someone else changed the URL — a back navigation, or a link into a filtered
  // view. Follow it rather than overwriting it on the next keystroke.
  useEffect(() => {
    if (params.search !== lastCommittedSearch.current) {
      lastCommittedSearch.current = params.search;
      setSearchInput(params.search);
    }
  }, [params.search]);

  const filterQuery = JSON.stringify(filters);

  // A changed filter is a different result set, so the page number from the old
  // one means nothing: picking an action filter while on page 3 of the audit log
  // would otherwise land on page 3 of the filtered results, which is usually
  // empty. Same reasoning as the search and page-size resets above.
  //
  // In an effect, not during render. setPage writes to the URL through
  // setSearchParams when this list is URL-backed, and updating the router while
  // rendering updates a different component mid-render - React warns, and the
  // navigation lands in an undefined order. Adjusting state during render is
  // only safe for state this component owns, which the query string is not.
  const lastFilterQuery = useRef(filterQuery);
  useEffect(() => {
    if (lastFilterQuery.current === filterQuery) return;
    lastFilterQuery.current = filterQuery;
    // eslint-disable-next-line react-hooks/set-state-in-effect -- see above: a router update during render is worse
    if (params.page !== 1) setPage(1);
  }, [filterQuery, params.page, setPage]);

  // Identifies the request these parameters describe, so a render can tell
  // whether the data on screen is the answer to the current question.
  const requestKey = `${basePath}|${JSON.stringify(params)}|${filterQuery}|${reloadToken}`;
  const isLoading = settledRequest !== requestKey;

  useEffect(() => {
    let cancelled = false;
    const requested = { page: params.page, limit: params.limit };

    api.get(buildAdminListUrl(basePath, params, JSON.parse(filterQuery)))
      .then((data) => {
        if (cancelled) return;
        const rows = data?.[itemsKey] || data?.items || [];
        setItems(rows);
        setPayload(data);
        setPagination(readPaginationMeta(data, requested, rows.length));
      })
      .catch((error) => {
        if (cancelled) return;
        logger.error(`Failed to load ${basePath}:`, error);
        toast({ title: errorTitle, description: error.message, variant: "destructive" });
        setItems([]);
        setPagination({ ...requested, totalItems: 0, totalPages: 1 });
      })
      .finally(() => { if (!cancelled) setSettledRequest(requestKey); });

    return () => { cancelled = true; };
  }, [basePath, errorTitle, filterQuery, itemsKey, params, reloadToken, requestKey, toast]);

  return {
    items,
    setItems,
    payload,
    pagination,
    isLoading,
    page: params.page,
    limit: params.limit,
    searchInput,
    setSearch,
    setPage,
    setLimit,
    reload,
  };
}
