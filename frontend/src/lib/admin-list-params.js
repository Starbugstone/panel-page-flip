/**
 * Query-string handling for the paginated admin tables.
 *
 * The admin lists used to fetch everything and filter in the browser, so their
 * state was purely local. Now that the server decides what a page contains, the
 * page/search/filter triple has to survive a reload and a trip to a user's
 * detail page and back — which means it lives in the URL, and these helpers are
 * what read it out and turn it into an API request.
 */

export const PAGE_SIZE_OPTIONS = [10, 25, 50, 100];
export const DEFAULT_PAGE_SIZE = 25;

/** Mirrors the backend's clamp so the UI never asks for a page size it will not get. */
const MAX_PAGE_SIZE = 100;

/**
 * Coerce whatever came out of the URL into usable list state.
 *
 * A hand-edited or stale URL should still render a list, so anything
 * unparseable falls back to the default rather than erroring.
 *
 * @param {{page?: unknown, limit?: unknown, search?: unknown}} [params]
 * @returns {{page: number, limit: number, search: string}}
 */
export function normalizeListParams({ page, limit, search } = {}) {
  const parsedPage = Number.parseInt(page, 10);
  const parsedLimit = Number.parseInt(limit, 10);

  return {
    page: Number.isFinite(parsedPage) && parsedPage > 0 ? parsedPage : 1,
    limit: PAGE_SIZE_OPTIONS.includes(parsedLimit)
      ? parsedLimit
      : Math.min(MAX_PAGE_SIZE, Number.isFinite(parsedLimit) && parsedLimit > 0 ? parsedLimit : DEFAULT_PAGE_SIZE),
    search: typeof search === "string" ? search : "",
  };
}

/**
 * Build the API URL for one page of a list.
 *
 * Empty filters are left out entirely rather than sent as blanks, so the
 * request for an unfiltered first page is byte-identical every time and stays
 * cacheable.
 *
 * @param {string} basePath e.g. "/api/users"
 * @param {{page: number, limit: number, search?: string}} params
 * @param {Record<string, string|number|boolean|null|undefined>} [filters]
 * @returns {string}
 */
export function buildAdminListUrl(basePath, params, filters = {}) {
  const { page, limit, search } = normalizeListParams(params);
  const query = new URLSearchParams();

  for (const [key, value] of Object.entries(filters)) {
    if (value === null || value === undefined || value === "") continue;
    query.set(key, String(value));
  }

  query.set("page", String(page));
  query.set("limit", String(limit));
  if (search.trim() !== "") query.set("search", search.trim());

  return `${basePath}?${query.toString()}`;
}

/**
 * The pagination block from a list response, tolerating an endpoint that has
 * not been migrated yet.
 *
 * @param {object} payload
 * @param {{page: number, limit: number}} requested
 * @param {number} itemCount How many rows came back, used when the server sent no totals.
 */
export function readPaginationMeta(payload, requested, itemCount = 0) {
  const meta = payload?.pagination;
  if (!meta) {
    return { page: requested.page, limit: requested.limit, totalItems: itemCount, totalPages: 1 };
  }

  return {
    page: Number(meta.page) || requested.page,
    limit: Number(meta.limit) || requested.limit,
    totalItems: Number(meta.totalItems) || 0,
    totalPages: Math.max(1, Number(meta.totalPages) || 1),
  };
}

/**
 * The 1-based row range the current page covers, for "Showing 26–50 of 143".
 * Returns null when there is nothing to show.
 */
export function visibleRange({ page, limit, totalItems }, itemCount) {
  if (!totalItems || !itemCount) return null;

  const first = (page - 1) * limit + 1;

  return { first, last: Math.min(totalItems, first + itemCount - 1) };
}
