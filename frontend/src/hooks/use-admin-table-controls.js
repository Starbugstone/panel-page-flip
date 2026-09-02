import { useCallback, useMemo, useState } from "react";

const BROWSER_TIMEZONE = Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC";

/**
 * Shared sort and column-filter state for admin tables.
 *
 * Query keys deliberately match the backend allow-lists. Keeping them in one
 * object means useAdminList treats every change as a new result set and resets
 * server-side pagination to its first page.
 */
export function useAdminTableControls({ defaultSort, defaultDirection = "DESC" } = {}) {
  const [sort, setSortState] = useState(defaultSort || "");
  const [direction, setDirection] = useState(defaultDirection);
  const [columnFilters, setColumnFilters] = useState({});

  const setSort = useCallback((field, nextDirection) => {
    setSortState(field);
    setDirection(nextDirection);
  }, []);

  const setColumnFilter = useCallback((field, value) => {
    setColumnFilters((current) => {
      const next = { ...current };
      const normalized = String(value ?? "").trim();
      if (normalized) next[field] = normalized;
      else delete next[field];
      return next;
    });
  }, []);

  const query = useMemo(() => ({
    ...(sort ? { sort, direction } : {}),
    ...columnFilters,
    // Server-side date filters must use the same calendar days the browser
    // displays, including daylight-saving transitions at either range edge.
    filterTimezone: BROWSER_TIMEZONE,
  }), [columnFilters, direction, sort]);

  // Every AdminColumnHeader in a table wires up the same four props, so they
  // are assembled once here rather than restated per header, per table.
  const headerProps = useMemo(
    () => ({ sort, direction, onSort: setSort, onFilter: setColumnFilter }),
    [direction, setColumnFilter, setSort, sort],
  );

  return {
    sort,
    direction,
    columnFilters,
    query,
    headerProps,
    setSort,
    setColumnFilter,
  };
}
