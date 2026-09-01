import { act, renderHook } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { useAdminTableControls } from "./use-admin-table-controls";

describe("useAdminTableControls", () => {
  it("sends the sort and every set filter as one query object", () => {
    const { result } = renderHook(() => useAdminTableControls({ defaultSort: "createdAt" }));

    expect(result.current.query).toEqual({ sort: "createdAt", direction: "DESC" });

    act(() => result.current.setColumnFilter("filterOwner", "  Selina  "));
    act(() => result.current.setSort("owner", "ASC"));

    expect(result.current.query).toEqual({ sort: "owner", direction: "ASC", filterOwner: "Selina" });
  });

  // A blank box is no filter at all, not a filter for the empty string, or the
  // request would carry a parameter the operator has just cleared.
  it("drops a filter that is cleared or is only whitespace", () => {
    const { result } = renderHook(() => useAdminTableControls({ defaultSort: "name" }));

    act(() => result.current.setColumnFilter("filterName", "Noir"));
    act(() => result.current.setColumnFilter("filterName", "   "));

    expect(result.current.columnFilters).toEqual({});
    expect(result.current.query).toEqual({ sort: "name", direction: "DESC" });
  });

  it("hands every column header the same wiring", () => {
    const { result } = renderHook(() => useAdminTableControls({ defaultSort: "name", defaultDirection: "ASC" }));
    const first = result.current.headerProps;

    expect(first).toEqual({
      sort: "name",
      direction: "ASC",
      onSort: result.current.setSort,
      onFilter: result.current.setColumnFilter,
    });

    // Stable while the sort is, so a table of headers does not re-render on
    // every keystroke elsewhere on the page.
    act(() => result.current.setColumnFilter("filterName", "Noir"));
    expect(result.current.headerProps).toBe(first);

    act(() => result.current.setSort("createdAt", "DESC"));
    expect(result.current.headerProps).not.toBe(first);
  });
});
