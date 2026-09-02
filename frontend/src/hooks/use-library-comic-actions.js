import { useToast } from "@/hooks/use-toast.js";
import { api } from "@/lib/api";
import { describeBulkOutcome, runBulkAction } from "@/lib/bulk-actions";
import { buildComicUpdatePayload } from "@/lib/comic-updates";

/**
 * Everything the library can do to the comics in it.
 *
 * The bulk operations re-raise after reporting, because the caller — a table
 * with a selection, usually — has to know whether to clear it. The exception
 * is an orphan confirmation, which is not a failure but a question, and gets
 * asked again rather than announced as an error.
 */
export function useLibraryComicActions({ refreshCurrent, updateComicProgress, removeComicsFromLibrary, refreshSummary, moveComics }) {
  const { toast } = useToast();

  const report = (title) => (requestError) => {
    toast({ title, description: requestError.message, variant: "destructive" });
  };

  const resetReadingProgress = async (comicId) => {
    await api.post(`/api/comics/${comicId}/reading-progress/reset`, {});
    updateComicProgress(comicId, null);
    return true;
  };

  const resetReadingProgressForComics = async (comicIds) => {
    const outcome = await runBulkAction(comicIds, resetReadingProgress);

    toast(describeBulkOutcome(outcome, { noun: "comic", verbPast: "reset" }));
    if (outcome.failed.length > 0) {
      throw new Error("Some reading progress could not be reset.");
    }
  };

  const saveComic = async (updatedComic) => {
    await api.patch("/api/comics", { updates: [buildComicUpdatePayload(updatedComic)] });
    await refreshCurrent();
    return true;
  };

  const deleteComic = async (comicId, { confirmOrphaned = false } = {}) => {
    await api.delete("/api/comics", { body: { comicIds: [comicId], confirmOrphaned } });
    removeComicsFromLibrary([comicId]);
    return true;
  };

  /** Leaving a share is the recipient's side of a deletion; the file is not theirs. */
  const removeSharedComic = async (comic) => {
    await api.post(`/api/shares/${comic.shareId}/remove`, {});
    removeComicsFromLibrary([comic.id]);
    refreshSummary();
  };

  const addTagToComics = async (comicIds, tag) => {
    try {
      await api.patch("/api/comics", { updates: comicIds.map((id) => ({ id, changes: { addTags: [tag] } })) });
      await refreshCurrent();
      toast({ title: "Tag added", description: `Added “${tag}” to ${comicIds.length} comic(s).` });
    } catch (requestError) {
      report("Bulk update failed")(requestError);
      throw requestError;
    }
  };

  const deleteComics = async (comicIds, { confirmOrphaned = false } = {}) => {
    try {
      await api.delete("/api/comics", { body: { comicIds, confirmOrphaned } });
      removeComicsFromLibrary(comicIds);
      toast({ title: "Comics deleted", description: `${comicIds.length} comic(s) removed from your library.` });
    } catch (requestError) {
      // Not a failure: the caller re-asks with confirmOrphaned and tries again.
      if (requestError.data?.code !== "orphaned_comics_confirmation_required") {
        report("Bulk deletion failed")(requestError);
      }
      throw requestError;
    }
  };

  const moveComicsToFolder = async (comicIds, folderId) => {
    try {
      await moveComics(comicIds, folderId);
      await refreshCurrent();
      toast({ title: "Moved", description: `${comicIds.length} comic(s) moved.` });
    } catch (requestError) {
      report("Move failed")(requestError);
      throw requestError;
    }
  };

  return {
    resetReadingProgress,
    resetReadingProgressForComics,
    saveComic,
    deleteComic,
    removeSharedComic,
    addTagToComics,
    deleteComics,
    moveComicsToFolder,
  };
}
