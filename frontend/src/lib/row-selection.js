/**
 * The ids from one row to another, inclusive, in the order they are shown.
 *
 * Range selection is anchored to positions in the list a user can see, so a row
 * no longer on screen — paged or filtered away since the anchor was set —
 * yields no range at all rather than one measured from a stale index.
 */
export function rowIdsInRange(rows, fromId, toId) {
  const from = rows.findIndex((row) => row.id === fromId);
  const to = rows.findIndex((row) => row.id === toId);
  if (from === -1 || to === -1) return [];

  const [start, end] = from <= to ? [from, to] : [to, from];
  return rows.slice(start, end + 1).map((row) => row.id);
}

/**
 * The selected rows, in the order the table shows them.
 *
 * Derived from the intersection rather than read out of the selection state,
 * so an id left over from a list that has since changed cannot make the
 * counter, the tick boxes and the request describe different sets of rows.
 */
export function selectedRowsOf(rows, selectedIds) {
  return rows.filter((row) => selectedIds.has(row.id));
}

/**
 * What to draw in a "select all" box: every row, some of them, or none.
 *
 * Radix takes the string "indeterminate" rather than a third boolean, and
 * an empty table is "none" — a tick against no rows claims something false.
 */
export function headerCheckboxState(rowCount, selectedCount) {
  if (rowCount > 0 && selectedCount === rowCount) return true;
  return selectedCount > 0 ? "indeterminate" : false;
}
