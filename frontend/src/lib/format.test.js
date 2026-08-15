import { describe, expect, it } from "vitest";
import { formatDate, formatDateTime, formatFileSize, matchesQuery } from "./format";

describe("formatDateTime", () => {
  it("returns the fallback when there is nothing to format", () => {
    expect(formatDateTime(null)).toBe("N/A");
    expect(formatDateTime("not a date", "—")).toBe("—");
  });

  it("formats a real timestamp", () => {
    expect(formatDateTime("2026-08-05T14:30:00Z")).toMatch(/2026/);
  });
});

describe("formatDate", () => {
  it("omits the time", () => {
    const formatted = formatDate("2026-08-05T14:30:00Z");
    expect(formatted).toMatch(/2026/);
    expect(formatted).not.toMatch(/:/);
  });
});

describe("formatFileSize", () => {
  it("picks the unit that fits", () => {
    expect(formatFileSize(512)).toBe("512 B");
    expect(formatFileSize(1536)).toBe("1.5 KB");
    expect(formatFileSize(1572864)).toBe("1.5 MB");
    expect(formatFileSize(0)).toBe("0 B");
  });
});

describe("matchesQuery", () => {
  it("is a case-insensitive substring check that ignores non-strings", () => {
    expect(matchesQuery("Batman", "bat")).toBe(true);
    expect(matchesQuery("Batman", "spider")).toBe(false);
    expect(matchesQuery(null, "bat")).toBe(false);
  });
});
