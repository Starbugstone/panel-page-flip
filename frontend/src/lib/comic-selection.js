import { describeBulkShareImpactOfDeletion } from "@/lib/sharing";
import { selectedRowsOf } from "@/lib/row-selection";

/** Mirrors SharingWorkflowService::MAX_BULK_COMICS; the server decides. */
export const MAX_SHAREABLE_SELECTION = 20;

/**
 * Owner-only actions are derived from server capabilities. Selection itself is
 * broader because personal folder moves are valid for shared comics too.
 */
const isOwnerActionEligible = (comic) => comic.canEdit !== false && comic.canDelete !== false;

/**
 * What a selection is, and what may be done with it.
 *
 * `selectedIds` is raw state that outlives the list — a comic can leave
 * `comics` and come back — so everything here is derived from the intersection
 * instead. That is why the counter, the warning and the request cannot
 * describe different sets of comics.
 *
 * Sharing has its own eligibility: a comic somebody shared with *you* cannot be
 * passed on. A mixed selection is blocked and explained rather than silently
 * filtered — a sender told "12 shared" while meaning 15 has been told the wrong
 * thing.
 */
export function describeComicSelection(comics, selectedIds, { canShare = true } = {}) {
  const selectedComics = selectedRowsOf(comics, selectedIds);
  const selectedComicIds = selectedComics.map((comic) => comic.id);

  const ownerActionsAllowed = selectedComics.length > 0 && selectedComics.every(isOwnerActionEligible);
  const shareable = selectedComics.filter((comic) => comic.canShare !== false);
  const shareBlocked = selectedComics.length > 0 && shareable.length !== selectedComics.length;
  const shareOverLimit = selectedComics.length > MAX_SHAREABLE_SELECTION;

  return {
    selectedComics,
    selectedComicIds,
    ownerActionsAllowed,
    shareBlocked,
    shareOverLimit,
    canShareSelection: canShare && selectedComics.length > 0 && !shareBlocked && !shareOverLimit,
    bulkShareImpact: describeBulkShareImpactOfDeletion(selectedComics),
  };
}

