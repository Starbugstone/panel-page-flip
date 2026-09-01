import { describe, expect, it } from "vitest";
import { filterAndSortAdminRows } from "./admin-client-table";

const columns = {
  name: { value: (row) => row.name },
  count: { value: (row) => row.count },
};

describe("filterAndSortAdminRows", () => {
  it("combines per-column filters and sorts without mutating the source", () => {
    const rows = [
      { name: "Bruce", count: 3 },
      { name: "Barbara", count: 8 },
      { name: "Barry", count: 2 },
    ];

    const result = filterAndSortAdminRows(rows, {
      sort: "count",
      direction: "DESC",
      columnFilters: { name: "ba" },
    }, columns);

    expect(result).toEqual([
      { name: "Barbara", count: 8 },
      { name: "Barry", count: 2 },
    ]);
    expect(rows[0].name).toBe("Bruce");
  });
});
