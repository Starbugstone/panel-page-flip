import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";

const plural = (count, one, many) => (count === 1 ? one : many);

/**
 * Confirming a bulk deletion, in one of two situations.
 *
 * Normally the files survive in quarantine and the warning is about the people
 * who lose access. When the server has answered that some files are already
 * gone, the dialog changes to name those records instead: the only thing left
 * to delete is the library entry, and pretending otherwise would promise a
 * recovery that cannot happen.
 */
export function ComicBulkDeleteDialog({ open, onOpenChange, selection, onConfirm }) {
  const orphans = selection.orphanedComics;
  const isUpdating = selection.isUpdating;
  const count = selection.selectedComicIds.length;

  const title = orphans.length > 0
    ? `Delete ${orphans.length} orphaned comic ${plural(orphans.length, "record", "records")}?`
    : `Delete ${count} selected ${plural(count, "comic", "comics")}?`;

  const confirmLabel = isUpdating ? "Deleting..."
    : orphans.length === 1 ? "Delete orphaned record"
      : orphans.length > 1 ? "Delete orphaned records"
        : "Delete selected";

  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>{title}</AlertDialogTitle>
          <AlertDialogDescription>
            {orphans.length > 0 ? (
              <span className="space-y-2">
                <span className="block font-medium text-destructive">
                  The source file{plural(orphans.length, " is", "s are")} no longer present.
                </span>
                <span className="block">
                  {orphans.map((comic) => comic.title).join(", ")}. Only the orphaned library
                  record{plural(orphans.length, "", "s")} can be removed.
                </span>
              </span>
            ) : (
              <span className="space-y-2">
                <span className="block">
                  They will be removed from your library and their existing files moved to recoverable quarantine storage.
                </span>
                {/* A deletion that also cuts other people off is a bigger
                    decision than one that does not, and has to say so. */}
                {selection.bulkShareImpact && (
                  <span className="block font-medium text-destructive">{selection.bulkShareImpact}</span>
                )}
              </span>
            )}
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={isUpdating}>Cancel</AlertDialogCancel>
          <AlertDialogAction
            onClick={(event) => { event.preventDefault(); onConfirm(orphans.length > 0); }}
            disabled={isUpdating}
            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
          >
            {confirmLabel}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
