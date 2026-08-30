import { X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { pluralize } from "@/lib/bulk-actions";

/**
 * What the ticked rows can be done to, and why part of the selection cannot.
 *
 * An action names the subset it will actually run on rather than being disabled
 * whenever the selection is mixed: ticking a whole page and revoking the shares
 * that are still revocable is the normal way this gets used. What that leaves
 * behind is stated, because an administrator who ticked eight rows and saw five
 * acted on needs to be told which three, not left to count.
 *
 * @param {object} props
 * @param {number} props.selectedCount
 * @param {number} props.totalCount Rows on the current page.
 * @param {string} props.noun Singular name for a row, e.g. "user".
 * @param {string} [props.plural] Where adding an "s" is wrong.
 * @param {Array<{key: string, label: string, icon: Function, variant?: string,
 *   eligible: unknown[], ineligibleReason?: string, onClick: () => void}>} props.actions
 * @param {{done: number, total: number}|null} [props.progress] Non-null while a run is in flight.
 * @param {() => void} props.onClear
 */
export function AdminBulkActionsBar({
  selectedCount,
  totalCount,
  noun,
  plural,
  actions,
  progress = null,
  onClear,
}) {
  const isRunning = progress !== null;
  const partial = isRunning
    ? []
    : actions.filter((action) => action.ineligibleReason && action.eligible.length < selectedCount);

  return (
    <div className="flex flex-col gap-3 rounded-lg border bg-card p-3 lg:flex-row lg:items-center lg:justify-between">
      <div className="text-sm" aria-live="polite">
        <p className="font-medium">
          {isRunning
            ? `Working… ${progress.done} of ${progress.total}`
            : `${selectedCount} of ${pluralize(totalCount, noun, plural)} selected`}
        </p>
        {/* Shift-click is not discoverable, and these tables are where it saves
            the most work. Said once, next to the thing it applies to. */}
        {selectedCount === 0 && !isRunning && (
          <p className="text-muted-foreground">
            Tick rows to act on several at once. Shift-click selects a range.
          </p>
        )}
        {partial.map((action) => (
          <p key={action.key} className="text-muted-foreground">
            {action.label}: {action.eligible.length} of {selectedCount} eligible — {action.ineligibleReason}
          </p>
        ))}
      </div>

      <div className="flex flex-wrap gap-2">
        {actions.map(({ key, label, icon: Icon, variant = "outline", eligible, onClick }) => (
          <Button
            key={key}
            variant={variant}
            size="sm"
            disabled={isRunning || eligible.length === 0}
            onClick={onClick}
          >
            <Icon className="mr-2 h-4 w-4" aria-hidden="true" />
            {label}
          </Button>
        ))}
        <Button variant="ghost" size="sm" onClick={onClear} disabled={isRunning || selectedCount === 0}>
          <X className="mr-2 h-4 w-4" aria-hidden="true" />
          Clear
        </Button>
      </div>
    </div>
  );
}
