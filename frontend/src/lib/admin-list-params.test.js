import { describe, expect, it } from "vitest";
import {
  DEFAULT_PAGE_SIZE,
  buildAdminListUrl,
  normalizeListParams,
  readPaginationMeta,
  visibleRange,
} from "@/lib/admin-list-params.js";

describe("normalizeListParams", () => {
  it("defaults an absent page and size", () => {
    expect(normalizeListParams()).toEqual({ page: 1, limit: DEFAULT_PAGE_SIZE, search: "" });
  });

  it("reads the values a URL supplies as strings", () => {
    expect(normalizeListParams({ page: "3", limit: "50", search: "batman" }))
      .toEqual({ page: 3, limit: 50, search: "batman" });
  });

  it("falls back to the first page for a nonsensical page", () => {
    expect(normalizeListParams({ page: "-2" }).page).toBe(1);
    expect(normalizeListParams({ page: "abc" }).page).toBe(1);
  });

  it("clamps a page size above the server's maximum", () => {
    expect(normalizeListParams({ limit: "5000" }).limit).toBe(100);
  });
});

describe("buildAdminListUrl", () => {
  it("always sends the page and size", () => {
    expect(buildAdminListUrl("/api/users", { page: 2, limit: 10 }))
      .toBe("/api/users?page=2&limit=10");
  });

  it("omits an empty search rather than sending a blank", () => {
    expect(buildAdminListUrl("/api/users", { page: 1, limit: 25, search: "   " }))
      .not.toContain("search");
  });

  it("trims the search term", () => {
    expect(buildAdminListUrl("/api/users", { page: 1, limit: 25, search: " bat " }))
      .toContain("search=bat");
  });

  it("encodes a search term that needs it", () => {
    expect(buildAdminListUrl("/api/users", { page: 1, limit: 25, search: "a&b c" }))
      .toContain("search=a%26b+c");
  });

  it("includes endpoint filters and drops the empty ones", () => {
    const url = buildAdminListUrl(
      "/api/comics",
      { page: 1, limit: 25 },
      { adminContext: true, ownerId: 42, action: "" }
    );

    expect(url).toContain("adminContext=true");
    expect(url).toContain("ownerId=42");
    expect(url).not.toContain("action");
  });
});

describe("readPaginationMeta", () => {
  it("reads the server's totals", () => {
    const meta = readPaginationMeta(
      { pagination: { page: 2, limit: 25, totalItems: 143, totalPages: 6 } },
      { page: 2, limit: 25 }
    );

    expect(meta).toEqual({ page: 2, limit: 25, totalItems: 143, totalPages: 6 });
  });

  it("falls back to a single page when the endpoint sends no totals", () => {
    const meta = readPaginationMeta({ items: [1, 2, 3] }, { page: 1, limit: 25 }, 3);

    expect(meta).toEqual({ page: 1, limit: 25, totalItems: 3, totalPages: 1 });
  });

  it("never reports fewer than one page", () => {
    expect(readPaginationMeta({ pagination: { totalPages: 0 } }, { page: 1, limit: 25 }).totalPages)
      .toBe(1);
  });
});

describe("visibleRange", () => {
  it("describes the rows on the current page", () => {
    expect(visibleRange({ page: 2, limit: 25, totalItems: 143 }, 25)).toEqual({ first: 26, last: 50 });
  });

  it("stops at the total on a partial last page", () => {
    expect(visibleRange({ page: 6, limit: 25, totalItems: 143 }, 18)).toEqual({ first: 126, last: 143 });
  });

  it("has nothing to describe for an empty list", () => {
    expect(visibleRange({ page: 1, limit: 25, totalItems: 0 }, 0)).toBeNull();
  });
});
