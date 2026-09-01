import { describe, expect, it } from "vitest";

import {
  adminFilterSuggestions,
  matchesAdminDateRange,
  parseAdminDateRange,
  serializeAdminDateRange,
} from "./admin-table-filters";

describe("admin table filter helpers", () => {
  it("round-trips open and closed date ranges", () => {
    expect(serializeAdminDateRange({ from: "2026-08-01", to: "2026-08-31" }))
      .toBe("2026-08-01..2026-08-31");
    expect(parseAdminDateRange("2026-08-01..2026-08-31"))
      .toEqual({ from: "2026-08-01", to: "2026-08-31", empty: false, valid: true });
    expect(parseAdminDateRange("..2026-08-31").to).toBe("2026-08-31");
    expect(parseAdminDateRange("2026-08-01..").from).toBe("2026-08-01");
  });

  it("keeps legacy single-day filters as exact ranges", () => {
    expect(parseAdminDateRange("2026-08-14")).toEqual({
      from: "2026-08-14",
      to: "2026-08-14",
      empty: false,
      valid: true,
    });
  });

  it("matches inclusive date boundaries and missing dates", () => {
    expect(matchesAdminDateRange("2026-08-01T08:00:00Z", "2026-08-01..2026-08-31")).toBe(true);
    expect(matchesAdminDateRange("2026-08-31T08:00:00Z", "2026-08-01..2026-08-31")).toBe(true);
    expect(matchesAdminDateRange("2026-09-01T08:00:00Z", "2026-08-01..2026-08-31")).toBe(false);
    expect(matchesAdminDateRange(null, "never")).toBe(true);
  });

  it("deduplicates useful suggestion fragments without joining cell fields", () => {
    const suggestions = adminFilterSuggestions([
      { name: "Selina", email: "selina@example.test" },
      { name: "selina", email: "cat@example.test" },
    ], (row) => [row.name, row.email]);

    expect(suggestions).toEqual(["Selina", "selina@example.test", "cat@example.test"]);
  });
});
