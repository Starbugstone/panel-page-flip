import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

function ConfirmDialog({ open, onOpenChange, title, description, confirmLabel, busy, onConfirm }) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
          <Button variant="destructive" disabled={busy} onClick={onConfirm}>{confirmLabel}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * The two things on this page that cannot be undone, each behind its own
 * confirmation. Both act on more than one share, which is why they are asked
 * about here rather than from a row.
 */
export function SharingConfirmDialogs({ cleanup, stopSharing, busy }) {
  return (
    <>
      <ConfirmDialog
        open={cleanup.open}
        onOpenChange={cleanup.onOpenChange}
        title={cleanup.copy.title}
        description={cleanup.copy.body}
        confirmLabel="Remove all dead shares"
        busy={busy}
        onConfirm={cleanup.onConfirm}
      />

      <ConfirmDialog
        open={stopSharing.target !== null}
        onOpenChange={() => stopSharing.onCancel()}
        title={`Stop sharing “${stopSharing.target?.title}”?`}
        description="Everyone who currently has access, and everyone with a pending invitation, will lose it immediately. Your comic and its file are not affected."
        confirmLabel="Stop sharing with everyone"
        busy={busy}
        onConfirm={stopSharing.onConfirm}
      />
    </>
  );
}
