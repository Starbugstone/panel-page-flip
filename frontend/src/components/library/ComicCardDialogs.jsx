import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { describeShareImpactOfDeletion } from "@/lib/sharing";

function ConfirmDialog({ open, onOpenChange, title, description, extra, confirmLabel, onConfirm }) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        {extra}
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
          <Button variant="destructive" onClick={onConfirm}>{confirmLabel}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** The three confirmations a card can raise, each about a different loss. */
export function ComicCardDialogs({ comic, actions }) {
  const shareImpact = describeShareImpactOfDeletion(comic);
  const { isOrphaned } = actions;
  const dismiss = (open) => { if (!open) actions.close(); };

  return (
    <>
      <ConfirmDialog
        open={actions.openDialog === "reset"}
        onOpenChange={dismiss}
        title="Reset Reading Progress"
        description={`Are you sure you want to reset your reading progress for "${comic.title}"? This action cannot be undone.`}
        confirmLabel="Reset Progress"
        onConfirm={actions.confirmReset}
      />

      <ConfirmDialog
        open={actions.openDialog === "delete"}
        onOpenChange={dismiss}
        title={isOrphaned ? "Delete orphaned comic record?" : `Delete “${comic.title}”?`}
        description={isOrphaned
          ? `The source file for “${comic.title}” is no longer present. Do you want to remove only its orphaned library record?`
          : `Delete “${comic.title}” from your library? Its existing files will be moved to recoverable quarantine storage.`}
        // A deletion that also cuts other people off is a bigger decision than
        // one that does not, and has to say so before the button is pressed.
        extra={!isOrphaned && shareImpact && (
          <p className="rounded border border-destructive/40 bg-destructive/10 p-3 text-sm">{shareImpact}</p>
        )}
        confirmLabel={isOrphaned
          ? "Delete orphaned record"
          : shareImpact ? "Delete for everyone" : "Delete Comic"}
        onConfirm={actions.confirmDelete}
      />

      <ConfirmDialog
        open={actions.openDialog === "remove-shared"}
        onOpenChange={dismiss}
        title={`Remove “${comic.title}” from your collection?`}
        description={`This hides the comic from your collection. It is not deleted — ${comic.sharedBy?.name || "the owner"} keeps it, and you can restore it from the Sharing page for as long as they keep sharing it.`}
        confirmLabel="Remove from my collection"
        onConfirm={actions.confirmRemoveShared}
      />
    </>
  );
}
