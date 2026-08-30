import { useState } from "react";
import { useToast } from "@/hooks/use-toast";
import { describeBulkOutcome, runBulkAction } from "@/lib/bulk-actions";

/**
 * Runs a bulk action over the selected rows and reports what it managed.
 *
 * The list is reloaded whichever way the run went, including a partial one:
 * some of the rows on screen no longer describe reality, and which ones is
 * exactly what an administrator cannot be expected to work out. Reloading also
 * retires the selection, because {@link useRowSelection} is keyed by the list
 * the ticks were made against.
 *
 * @param {{reload: () => void}} options
 */
export function useAdminBulkAction({ reload }) {
  const { toast } = useToast();
  const [progress, setProgress] = useState(null);

  /**
   * @param {unknown[]} items
   * @param {(item: unknown) => Promise<unknown>} perform
   * @param {{noun: string, plural?: string, verbPast: string, labelOf?: (item: unknown) => string}} phrasing
   */
  const run = async (items, perform, phrasing) => {
    if (items.length === 0 || progress !== null) return null;

    setProgress({ done: 0, total: items.length });
    try {
      const outcome = await runBulkAction(items, perform, (done, total) => setProgress({ done, total }));
      toast(describeBulkOutcome(outcome, phrasing));
      reload();
      return outcome;
    } finally {
      setProgress(null);
    }
  };

  return { run, progress, isRunning: progress !== null };
}
