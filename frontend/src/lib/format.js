/**
 * Shared display formatters.
 *
 * These used to be redefined in almost every admin component, which let them
 * drift apart. Import from here instead of writing a local copy.
 */

/**
 * Format a date/time for display, e.g. "5 Aug 2026, 14:30".
 * @param {string|Date|null|undefined} value
 * @param {string} fallback Shown when there is no value.
 */
export function formatDateTime(value, fallback = "N/A") {
  if (!value) return fallback;

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return fallback;

  return new Intl.DateTimeFormat("en-US", { dateStyle: "medium", timeStyle: "short" }).format(date);
}

/**
 * Format a date for display without the time, e.g. "5 Aug 2026".
 * @param {string|Date|null|undefined} value
 * @param {string} fallback Shown when there is no value.
 */
export function formatDate(value, fallback = "N/A") {
  if (!value) return fallback;

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return fallback;

  return new Intl.DateTimeFormat("en-US", { dateStyle: "medium" }).format(date);
}

/**
 * Format a byte count for display, e.g. "1.4 MB".
 * @param {number|null|undefined} bytes
 */
export function formatFileSize(bytes) {
  const value = Number(bytes) || 0;
  if (value < 1024) return `${value} B`;
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;
  if (value < 1024 * 1024 * 1024) return `${(value / (1024 * 1024)).toFixed(1)} MB`;
  return `${(value / (1024 * 1024 * 1024)).toFixed(1)} GB`;
}

/**
 * Case-insensitive "does this field contain the query" check that tolerates
 * null/undefined fields, which most comic metadata columns are.
 * @param {unknown} value
 * @param {string} query Already lowercased.
 */
export function matchesQuery(value, query) {
  return typeof value === "string" && value.toLowerCase().includes(query);
}
