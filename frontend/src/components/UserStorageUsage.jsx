import { AlertTriangle } from "lucide-react";

import { Progress } from "@/components/ui/progress";
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@/components/ui/tooltip";
import { formatByteCount, formatBytes } from "@/lib/format";
import { cn } from "@/lib/utils";

/** Where quota use stops being background information and starts being news. */
const WARNING_PERCENT = 90;

function storageUsageView(usedBytes, quotaBytes, unmeasuredComicCount) {
  const used = Math.max(0, Number(usedBytes) || 0);
  const quota = Math.max(0, Number(quotaBytes) || 0);
  const unmeasured = Math.max(0, Number(unmeasuredComicCount) || 0);
  const percent = quota > 0 ? (used / quota) * 100 : null;
  const barValue = percent === null ? 0 : Math.min(100, Math.max(0, percent));
  const percentLabel = percent === null ? "Unlimited" : `${percent.toFixed(1)}%`;
  const usedLabel = formatBytes(used);
  const quotaLabel = quota > 0 ? formatBytes(quota) : null;
  const incompleteNote = unmeasured > 0
    ? `${unmeasured} ${unmeasured === 1 ? "comic has" : "comics have"} no stored file-size metadata; actual usage may be higher.`
    : null;
  const heading = incompleteNote ? "Measured storage used" : "Storage used";
  const summary = quotaLabel
    ? `${heading}: ${usedLabel} of ${quotaLabel}, ${percentLabel}.`
    : `${heading}: ${usedLabel}. Unlimited storage quota.`;

  return { used, quota, percent, barValue, percentLabel, usedLabel, quotaLabel, incompleteNote, summary };
}

/**
 * How much of an account's storage quota is gone: one table cell, or one block
 * of a card.
 *
 * Presentation only. The bytes and the effective quota both arrive from the API,
 * which takes them from the same place upload admission does, so this never
 * invents a second definition of "storage used".
 *
 * @param {object} props
 * @param {number} props.usedBytes Canonical owned-comic bytes.
 * @param {number|null} props.quotaBytes Effective quota; 0 means unlimited.
 * @param {number} [props.unmeasuredComicCount] Owned comics with no recorded size.
 * @param {string} [props.className]
 */
export function UserStorageUsage({ usedBytes, quotaBytes, unmeasuredComicCount = 0, className }) {
  // A quota of zero is unlimited, not a full disk: there is no percentage to
  // report and nothing honest to fill the bar with. Only the bar is clamped;
  // if the data says 112%, every number shown here says 112%.
  const view = storageUsageView(usedBytes, quotaBytes, unmeasuredComicCount);
  const {
    used, quota, percent, barValue, percentLabel, usedLabel, quotaLabel, incompleteNote, summary,
  } = view;

  return (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger asChild>
          {/* Focusable so the exact figures are not hover-only. It is not a
              button: there is nothing to activate, and the bar inside carries
              the accessible name. */}
          <div
            tabIndex={0}
            className={cn(
              "w-full min-w-[9rem] space-y-1 rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
              className
            )}
          >
            <div className="flex items-baseline justify-between gap-2 text-sm">
              <span className="flex items-center gap-1.5">
                <span className="font-medium">{usedLabel}</span>
                {quotaLabel && <span className="text-muted-foreground">/ {quotaLabel}</span>}
                {incompleteNote && (
                  <AlertTriangle className="h-3.5 w-3.5 shrink-0 text-amber-600" aria-hidden="true" />
                )}
              </span>
              <span className="text-xs text-muted-foreground">{percentLabel}</span>
            </div>
            <Progress
              className="h-2"
              value={barValue}
              indicatorClassName={toneClass(percent)}
              aria-label={incompleteNote ? `${summary} ${incompleteNote}` : summary}
            />
          </div>
        </TooltipTrigger>
        <TooltipContent className="max-w-xs space-y-1">
          <p>{summary}</p>
          <p className="text-xs text-muted-foreground">
            {formatByteCount(used)}
            {quota > 0 && ` / ${formatByteCount(quota)}`} bytes
          </p>
          {incompleteNote && <p className="text-xs text-amber-600">{incompleteNote}</p>}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  );
}

/** Colour reinforces the percentage beside it; it never carries the meaning alone. */
function toneClass(percent) {
  if (percent === null) return undefined;
  if (percent >= 100) return "bg-destructive";
  if (percent >= WARNING_PERCENT) return "bg-amber-500";
  return undefined;
}
