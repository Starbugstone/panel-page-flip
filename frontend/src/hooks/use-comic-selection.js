import { useMemo, useState } from "react";
import { describeComicSelection } from "@/lib/comic-selection";

/**
 * A table's tick boxes, and the bulk operations they enable.
 *
 * Every operation clears the selection on success and keeps it on failure: the
 * caller reports the error, and the comics somebody picked are exactly what
 * they need to try again with.
 */
export function useComicSelection({ comics, onBulkAddTag, onBulkDelete, onBulkMove, canShare }) {
  const [selectedIds, setSelectedIds] = useState(() => new Set());
  const [isUpdating, setIsUpdating] = useState(false);
  const [orphanedComics, setOrphanedComics] = useState([]);

  const selection = useMemo(
    () => describeComicSelection(comics, selectedIds, { canShare }),
    [canShare, comics, selectedIds]
  );

  const clear = () => setSelectedIds(new Set());

  const toggleAll = (checked) => setSelectedIds(checked ? new Set(comics.map((comic) => comic.id)) : new Set());

  const toggle = (comicId, checked) => setSelectedIds((current) => {
    const next = new Set(current);
    if (checked) next.add(comicId); else next.delete(comicId);
    return next;
  });

  /** @returns {boolean} whether the tag was applied */
  const addTag = async (name) => {
    if (!name || selection.selectedComicIds.length === 0) return false;

    setIsUpdating(true);
    try {
      await onBulkAddTag(selection.selectedComicIds, name);
      clear();
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
      clear();
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
    clear();
  };

  return {
    ...selection,
    isUpdating,
    orphanedComics,
    forgetOrphans: () => setOrphanedComics([]),
    isChecked: (comic) => selectedIds.has(comic.id),
    toggle,
    toggleAll,
    addTag,
    deleteSelected,
    moveSelected,
  };
}
