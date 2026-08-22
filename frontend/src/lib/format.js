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
 * Step down a byte count through 1024-based tiers until it fits a unit.
 *
 * Both formatters below scale by 1024; they differ only in what they call the
 * tiers and how much precision each tier deserves, so that is all they pass.
 *
 * @param {number|null|undefined} bytes
 * @param {string[]} units Tier names, smallest first. The last one is the ceiling.
 * @param {number[]} decimals Fraction digits per tier, parallel to `units`.
 */
function formatScaledBytes(bytes, units, decimals) {
  const value = Number(bytes) || 0;
  const magnitude = Math.abs(value);

  let tier = 0;
  while (tier < units.length - 1 && magnitude >= 1024 ** (tier + 1)) tier++;

  return `${(value / 1024 ** tier).toFixed(decimals[tier])} ${units[tier]}`;
}

/**
 * Format a byte count for display, e.g. "1.4 MB".
 *
 * Decimal-looking names over binary tiers, which is what file managers show and
 * what this has always shown. Anything measured against the storage quota wants
 * formatBytes instead, where the names match the arithmetic.
 *
 * @param {number|null|undefined} bytes
 */
export function formatFileSize(bytes) {
  return formatScaledBytes(bytes, ["B", "KB", "MB", "GB"], [0, 1, 1, 1]);
}

/**
 * Format a byte count in binary units, e.g. "3.18 GiB".
 *
 * Named for what it actually divides by, because the storage quota this reports
 * against is itself binary (10 * 1024^3) and a "10.7 GB" quota beside a 10 GiB
 * limit reads as a bug. Bigger units carry more decimals: a tenth of a GiB is
 * 107 MB, too coarse to watch an account approach its limit.
 *
 * @param {number|null|undefined} bytes
 */
export function formatBytes(bytes) {
  return formatScaledBytes(bytes, ["B", "KiB", "MiB", "GiB", "TiB"], [0, 1, 1, 2, 2]);
}

/**
 * The exact byte count, grouped for reading: "10,737,418,240".
 * @param {number|null|undefined} bytes
 */
export function formatByteCount(bytes) {
  return new Intl.NumberFormat("en-US").format(Number(bytes) || 0);
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
