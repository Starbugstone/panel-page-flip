/**
 * Apply the shared admin table controls to an endpoint that returns its whole
 * result set (overview, Dropbox connections, and the bounded report queue).
 *
 * @param {Array<object>} rows
 * @param {{sort: string, direction: string, columnFilters: Record<string, string>}} controls
 * @param {Record<string, {value: (row: object) => unknown, filter?: (value: unknown, query: string, row: object) => boolean}>} columns
 */
export function filterAndSortAdminRows(rows, controls, columns) {
  const filtered = rows.filter((row) => Object.entries(controls.columnFilters).every(([field, query]) => {
    const column = columns[field];
    if (!column) return true;
    const value = column.value(row);
    const normalized = query.toLocaleLowerCase();
    return column.filter
      ? column.filter(value, normalized, row)
      : String(value ?? "").toLocaleLowerCase().includes(normalized);
  }));

  const column = columns[controls.sort];
  if (!column) return filtered;

  const multiplier = controls.direction === "ASC" ? 1 : -1;
  return [...filtered].sort((left, right) => compare(column.value(left), column.value(right)) * multiplier);
}

function compare(left, right) {
  if (left == null && right == null) return 0;
  if (left == null) return 1;
  if (right == null) return -1;
  if (typeof left === "number" && typeof right === "number") return left - right;
  return String(left).localeCompare(String(right), undefined, { numeric: true, sensitivity: "base" });
}

