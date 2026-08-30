/** How many failures are named before the summary stops listing them. */
const NAMED_FAILURES = 3;

/**
 * Applies a per-row request to every selected row, one at a time.
 *
 * There is no batch endpoint behind these: each row goes through the same
 * single-row route the row's own button uses, which is what keeps the
 * permission check, the audit entry and the refusal message identical whether
 * an administrator acted on one row or forty. That makes partial success the
 * normal outcome — deleting eight accounts where three still own comics
 * deletes five — so failures are collected and reported rather than aborting
 * the rest.
 *
 * Sequential on purpose. These are destructive operations against a shared
 * installation, and firing a page of them at once turns one careless click
 * into a load spike.
 *
 * @template T
 * @param {T[]} items
 * @param {(item: T) => Promise<unknown>} perform
 * @param {(done: number, total: number) => void} [onProgress]
 * @returns {Promise<{succeeded: Array<{item: T, result: unknown}>, failed: Array<{item: T, message: string}>}>}
 */
export async function runBulkAction(items, perform, onProgress) {
  const succeeded = [];
  const failed = [];

  for (const item of items) {
    try {
      succeeded.push({ item, result: await perform(item) });
    } catch (error) {
      failed.push({ item, message: error?.message || "The request failed" });
    }
    onProgress?.(succeeded.length + failed.length, items.length);
  }

  return { succeeded, failed };
}

/** "1 user", "3 users". */
export function pluralize(count, noun, plural = `${noun}s`) {
  return `${count} ${count === 1 ? noun : plural}`;
}

/**
 * "Alice, Bob and 12 others" — a confirmation naming forty accounts is a wall
 * of text nobody reads, and the count is the part that decides the answer.
 */
export function summariseLabels(items, labelOf, max = 5) {
  const named = items.slice(0, max).map(labelOf);
  const rest = items.length - named.length;
  return rest > 0 ? `${named.join(", ")} and ${rest} others` : named.join(", ");
}

/**
 * A toast describing what a bulk run actually did.
 *
 * Partial success is reported as partial success, naming the rows that were
 * refused and why: "5 of 8 accounts deleted" with the three that own comics
 * listed is actionable, where a bare "deleted" would be a lie and a bare
 * "failed" would hide the five that went.
 *
 * @param {{succeeded: Array<{item: unknown}>, failed: Array<{item: unknown, message: string}>}} outcome
 * @param {object} phrasing
 * @param {string} phrasing.noun Singular noun for a row, e.g. "user".
 * @param {string} [phrasing.plural] Where adding an "s" is wrong.
 * @param {string} phrasing.verbPast What was done, e.g. "deleted".
 * @param {(item: unknown) => string} [phrasing.labelOf] Names a row in the failure list.
 */
export function describeBulkOutcome({ succeeded, failed }, { noun, plural, verbPast, labelOf }) {
  const total = succeeded.length + failed.length;

  if (failed.length === 0) {
    return { title: `${pluralize(succeeded.length, noun, plural)} ${verbPast}` };
  }

  const title = succeeded.length === 0
    ? `Nothing was ${verbPast}`
    : `${succeeded.length} of ${pluralize(total, noun, plural)} ${verbPast}`;

  return { title, description: describeFailures(failed, labelOf), variant: "destructive" };
}

function describeFailures(failed, labelOf) {
  const named = failed.slice(0, NAMED_FAILURES).map(({ item, message }) => {
    const label = labelOf?.(item);
    return label ? `${label}: ${message}` : message;
  });

  const rest = failed.length - named.length;
  return rest > 0 ? `${named.join("; ")}; and ${rest} more` : named.join("; ");
}
