import { describe, expect, it } from "vitest";
import { formatByteCount, formatBytes, formatDate, formatDateTime, formatFileSize, matchesQuery } from "./format";

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

describe("formatBytes", () => {
  it("uses binary units, because the quota it reports against is binary", () => {
    expect(formatBytes(0)).toBe("0 B");
    expect(formatBytes(512)).toBe("512 B");
    expect(formatBytes(1024)).toBe("1.0 KiB");
    expect(formatBytes(44669239)).toBe("42.6 MiB");
    expect(formatBytes(3414781133)).toBe("3.18 GiB");
    expect(formatBytes(10 * 1024 ** 3)).toBe("10.00 GiB");
    expect(formatBytes(3 * 1024 ** 4)).toBe("3.00 TiB");
  });

  it("treats nothing as nothing rather than NaN", () => {
    expect(formatBytes(null)).toBe("0 B");
    expect(formatBytes(undefined)).toBe("0 B");
  });
});

describe("the ladder both byte formatters share", () => {
  it("crosses tiers at the same boundaries under either set of names", () => {
    expect(formatFileSize(1024 ** 2 - 1)).toBe("1024.0 KB");
    expect(formatBytes(1024 ** 2 - 1)).toBe("1024.0 KiB");
    expect(formatFileSize(1024 ** 2)).toBe("1.0 MB");
    expect(formatBytes(1024 ** 2)).toBe("1.0 MiB");
  });

  it("stops at its largest unit rather than inventing one", () => {
    expect(formatFileSize(5 * 1024 ** 4)).toBe("5120.0 GB");
    expect(formatBytes(5 * 1024 ** 5)).toBe("5120.00 TiB");
  });
});

describe("formatByteCount", () => {
  it("groups the exact figure so it can be read", () => {
    expect(formatByteCount(10737418240)).toBe("10,737,418,240");
    expect(formatByteCount(0)).toBe("0");
  });
});

describe("matchesQuery", () => {
  it("is a case-insensitive substring check that ignores non-strings", () => {
    expect(matchesQuery("Batman", "bat")).toBe(true);
    expect(matchesQuery("Batman", "spider")).toBe(false);
    expect(matchesQuery(null, "bat")).toBe(false);
  });
});
