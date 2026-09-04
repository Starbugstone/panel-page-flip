const CALENDAR_DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

const ADMIN_DATE_RANGE_SEPARATOR = "..";
export const ADMIN_EMPTY_DATE = "never";

function isCalendarDate(value) {
  const match = CALENDAR_DATE.exec(String(value ?? ""));
  if (!match) return false;

  const [, year, month, day] = match;
  const candidate = new Date(Date.UTC(Number(year), Number(month) - 1, Number(day)));
  return candidate.getUTCFullYear() === Number(year)
    && candidate.getUTCMonth() === Number(month) - 1
    && candidate.getUTCDate() === Number(day);
}

/**
 * Read the compact value used in a table query into the two controls shown by
 * the range picker. A legacy single day remains an exact-day range so saved
 * URLs and in-flight filters keep working.
 */
export function parseAdminDateRange(value) {
  const text = String(value ?? "").trim();
  if (!text) return { from: "", to: "", empty: false, valid: true };
  if (text.toLocaleLowerCase() === ADMIN_EMPTY_DATE) {
    return { from: "", to: "", empty: true, valid: true };
  }

  if (!text.includes(ADMIN_DATE_RANGE_SEPARATOR)) {
    const valid = isCalendarDate(text);
    return { from: valid ? text : "", to: valid ? text : "", empty: false, valid };
  }

  const parts = text.split(ADMIN_DATE_RANGE_SEPARATOR);
  if (parts.length !== 2) return { from: "", to: "", empty: false, valid: false };

  const [from, to] = parts;
  const valid = (!from || isCalendarDate(from))
    && (!to || isCalendarDate(to))
    && Boolean(from || to);

  return { from: valid ? from : "", to: valid ? to : "", empty: false, valid };
}

export function serializeAdminDateRange({ from = "", to = "", empty = false } = {}) {
  if (empty) return ADMIN_EMPTY_DATE;
  if (!from && !to) return "";
  return `${from}${ADMIN_DATE_RANGE_SEPARATOR}${to}`;
}

/** Convert a date-like value to the local calendar day the UI displays. */
export function localCalendarDate(value) {
  if (isCalendarDate(value)) return String(value);
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return null;
  return [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, "0"),
    String(date.getDate()).padStart(2, "0"),
  ].join("-");
}

/** Match a date cell against an inclusive local-calendar range. */
export function matchesAdminDateRange(value, query, { emptyValue = null } = {}) {
  const range = parseAdminDateRange(query);
  if (!range.valid) return false;
  if (range.empty) return value == null || value === "" || value === emptyValue;

  const day = localCalendarDate(value);
  if (!day) return false;
  if (range.from && day < range.from) return false;
  if (range.to && day > range.to) return false;
  return true;
}

/** Match a numeric cell against the inclusive `minimum..maximum` slider value. */
export function matchesAdminIntegerRange(value, query) {
  const match = /^(\d+)\.\.(\d+)$/.exec(String(query ?? ""));
  if (!match) return Number(value) === Number(query);
  const number = Number(value);
  return number >= Number(match[1]) && number <= Number(match[2]);
}

/**
 * Build a small, local suggestion set from the rows already on screen. This is
 * instant and never fires speculative admin-list requests while somebody is
 * typing. Accessors may return one value or an array of useful cell fragments.
 */
export function adminFilterSuggestions(rows, valueOf, limit = 100) {
  const suggestions = new Map();

  for (const row of rows || []) {
    const values = valueOf(row);
    for (const value of Array.isArray(values) ? values : [values]) {
      const text = String(value ?? "").trim();
      const key = text.toLocaleLowerCase();
      if (!text || suggestions.has(key)) continue;
      suggestions.set(key, text);
      if (suggestions.size >= limit) return [...suggestions.values()];
    }
  }

  return [...suggestions.values()];
}
