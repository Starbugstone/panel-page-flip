import { useMemo, useState } from "react";
import { comicIdsInRange, describeComicSelection } from "@/lib/comic-selection";

/**
 * A table's tick boxes, and the bulk operations they enable.
 *
 * Every operation clears the selection on success and keeps it on failure: the
 * caller reports the error, and the comics somebody picked are exactly what
 * they need to try again with.
 */
export function useComicSelection({ comics, onBulkAddTag, onBulkDelete, onBulkMove, canShare }) {
  const [selectedIds, setSelectedIds] = useState(() => new Set());
  const [anchor, setAnchor] = useState(null);
  const [isUpdating, setIsUpdating] = useState(false);
  const [orphanedComics, setOrphanedComics] = useState([]);

  const selection = useMemo(
    () => describeComicSelection(comics, selectedIds, { canShare }),
    [canShare, comics, selectedIds]
  );

  const clear = () => {
    setSelectedIds(new Set());
    setAnchor(null);
  };

  const toggleAll = (checked) => {
    setSelectedIds(checked ? new Set(comics.map((comic) => comic.id)) : new Set());
    setAnchor(null);
  };

  /**
   * @param {boolean} checked
   * @param {{extendFromAnchor?: boolean}} options shift-clicking covers every
   *   comic between the last plain click and this one, the way a file manager
   *   does.
   *
   * The range takes the *anchor's* state rather than the clicked box's own
   * toggle: shift-clicking back inside a range you just selected should shorten
   * it, not invert the half you clicked through. The anchor also stays put, so
   * successive shift-clicks resize one range instead of walking it along.
   */
  const toggle = (comicId, checked, { extendFromAnchor = false } = {}) => {
    const range = extendFromAnchor && anchor ? comicIdsInRange(comics, anchor.id, comicId) : [];
    const selecting = range.length > 0 ? anchor.checked : checked;

    setSelectedIds((current) => {
      const next = new Set(current);
      for (const id of range.length > 0 ? range : [comicId]) {
        if (selecting) next.add(id); else next.delete(id);
      }
      return next;
    });

    if (range.length === 0) setAnchor({ id: comicId, checked });
  };

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
