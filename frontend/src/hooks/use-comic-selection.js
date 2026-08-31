import { useMemo, useState } from "react";
import { useRowSelection } from "@/hooks/use-row-selection";
import { describeComicSelection } from "@/lib/comic-selection";

/**
 * A table's tick boxes, and the bulk operations they enable.
 *
 * The tick boxes themselves are {@link useRowSelection}, shared with the admin
 * tables; what is added here is what a *comic* selection may be done to and the
 * requests that do it.
 *
 * Every operation clears the selection on success and keeps it on failure: the
 * caller reports the error, and the comics somebody picked are exactly what
 * they need to try again with.
 */
export function useComicSelection({ comics, onBulkAddTag, onBulkDelete, onBulkMove, canShare }) {
  const rows = useRowSelection({ rows: comics });
  const [isUpdating, setIsUpdating] = useState(false);
  const [orphanedComics, setOrphanedComics] = useState([]);

  const selection = useMemo(
    () => describeComicSelection(comics, rows.selectedIds, { canShare }),
    [canShare, comics, rows.selectedIds]
  );

  /** @returns {boolean} whether the tag was applied */
  const addTag = async (name) => {
    if (!name || selection.selectedComicIds.length === 0) return false;

    setIsUpdating(true);
    try {
      await onBulkAddTag(selection.selectedComicIds, name);
      rows.clear();
      return true;
    } catch {
      // The caller reports the API error and keeps the selection for retry.
      return false;
    } finally {
      setIsUpdating(false);
    }
  };

  /**
   * @returns {boolean} whether the confirmation should close. A refusal for
   *   want of an orphan confirmation is a question, not a failure: the dialog
   *   stays open and asks it.
   */
  const deleteSelected = async (confirmOrphaned = false) => {
    setIsUpdating(true);
    try {
      await onBulkDelete(selection.selectedComicIds, { confirmOrphaned });
      rows.clear();
      setOrphanedComics([]);
      return true;
    } catch (error) {
      if (error.data?.code === "orphaned_comics_confirmation_required") {
        setOrphanedComics(error.data.orphanedComics || []);
      }
      return false;
    } finally {
      setIsUpdating(false);
    }
  };

  const moveSelected = async (folderId) => {
    await onBulkMove(selection.selectedComicIds, folderId);
    rows.clear();
  };

  return {
    ...selection,
    headerState: rows.headerState,
    isUpdating,
    orphanedComics,
    forgetOrphans: () => setOrphanedComics([]),
    isChecked: rows.isChecked,
    toggle: rows.toggle,
    toggleAll: rows.toggleAll,
    addTag,
    deleteSelected,
    moveSelected,
  };
}
