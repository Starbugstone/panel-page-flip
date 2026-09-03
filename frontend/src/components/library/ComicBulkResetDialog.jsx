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

const comicLabel = (count) => count === 1 ? "comic" : "comics";

/** Confirms clearing the personal reading positions of the selected comics. */
export function ComicBulkResetDialog({ open, onOpenChange, selection, onConfirm }) {
  const count = selection.selectedComicIds.length;
  const isUpdating = selection.isUpdating;

  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Reset progress for {count} selected {comicLabel(count)}?</AlertDialogTitle>
          <AlertDialogDescription>
            Every selected comic will start again from page one. This action cannot be undone.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={isUpdating}>Cancel</AlertDialogCancel>
          <AlertDialogAction
            onClick={(event) => { event.preventDefault(); onConfirm(); }}
            disabled={isUpdating}
          >
            {isUpdating ? "Resetting..." : "Reset selected"}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
