import { useState } from "react";
import { headerCheckboxState, rowIdsInRange, selectedRowsOf } from "@/lib/row-selection";

const NOTHING = { forKey: null, ids: new Set(), anchor: null };

/**
 * A table's tick boxes: which rows are picked, and how clicking changes that.
 *
 * Selection is state the table owns, but the rows underneath it are not — an
 * admin list replaces them on every page, search and filter change. Rather than
 * clearing in an effect after the fact, the selection is keyed by `resetKey`
 * and simply is not read when the key no longer matches, so there is never a
 * render where a new page shows the previous page's ticks.
 *
 * @param {object} options
 * @param {Array<{id: number|string}>} options.rows The rows currently on screen.
 * @param {unknown} [options.resetKey] Changing this abandons the selection.
 */
export function useRowSelection({ rows, resetKey = null }) {
  const [state, setState] = useState(() => ({ ...NOTHING, forKey: resetKey }));
  const current = state.forKey === resetKey ? state : NOTHING;

  const selectedRows = selectedRowsOf(rows, current.ids);

  const write = (changes) => setState({ ...current, forKey: resetKey, ...changes });

  const clear = () => write({ ids: new Set(), anchor: null });

  const toggleAll = (checked) => write({
    ids: checked ? new Set(rows.map((row) => row.id)) : new Set(),
    anchor: null,
  });

  /**
   * @param {number|string} rowId
   * @param {boolean} checked
   * @param {{extendFromAnchor?: boolean}} options shift-clicking covers every
   *   row between the last plain click and this one, the way a file manager
   *   does.
   *
   * The range takes the *anchor's* state rather than the clicked box's own
   * toggle: shift-clicking back inside a range you just selected should shorten
   * it, not invert the half you clicked through. The anchor also stays put, so
   * successive shift-clicks resize one range instead of walking it along.
   */
  const toggle = (rowId, checked, { extendFromAnchor = false } = {}) => {
    const range = extendFromAnchor && current.anchor
      ? rowIdsInRange(rows, current.anchor.id, rowId)
      : [];
    const selecting = range.length > 0 ? current.anchor.checked : checked;

    const ids = new Set(current.ids);
    for (const id of range.length > 0 ? range : [rowId]) {
      if (selecting) ids.add(id); else ids.delete(id);
    }

    write({ ids, anchor: range.length > 0 ? current.anchor : { id: rowId, checked } });
  };

  return {
    selectedIds: current.ids,
    selectedRows,
    selectedCount: selectedRows.length,
    headerState: headerCheckboxState(rows.length, selectedRows.length),
    isChecked: (row) => current.ids.has(row.id),
    toggle,
    toggleAll,
    clear,
  };
}
