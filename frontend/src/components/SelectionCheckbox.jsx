import { Checkbox } from "@/components/ui/checkbox";

/**
 * A row's tick box, carrying the modifier keys a change event drops.
 *
 * Radix reports `onCheckedChange` with the new value alone, so shift-clicking
 * would arrive at the selection indistinguishable from a plain click and no
 * range could ever be extended. The click event is used instead, and the
 * mousedown is swallowed because shift-clicking otherwise drags a text
 * selection across the rows.
 */
export function SelectionCheckbox({ checked, onToggle, label, disabled = false }) {
  return (
    <Checkbox
      checked={checked}
      disabled={disabled}
      onClick={(event) => onToggle(!checked, { extendFromAnchor: event.shiftKey })}
      onMouseDown={(event) => { if (event.shiftKey) event.preventDefault(); }}
      aria-label={label}
    />
  );
}

/**
 * The header box that takes the whole page in or lets it all go.
 *
 * "Indeterminate" is the honest third state for a part-selected page; without
 * it the box would claim the page is fully selected after a single row.
 */
export function SelectAllCheckbox({ state, onToggleAll, label }) {
  return (
    <Checkbox
      checked={state}
      onCheckedChange={(checked) => onToggleAll(checked === true)}
      aria-label={label}
    />
  );
}
