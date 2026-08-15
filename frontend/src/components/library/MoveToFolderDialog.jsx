import { useMemo, useState } from "react";
import { Home } from "lucide-react";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { LibraryFolderTree } from "@/components/library/LibraryFolderTree";
import { folderDescendantIds } from "@/lib/library-folders";

export function MoveToFolderDialog({
  open,
  onOpenChange,
  folders,
  currentFolderId = null,
  onMove,
  itemCount = 1,
  movingFolderId = null,
  itemLabel = "comic",
}) {
  const [target, setTarget] = useState(currentFolderId);
  const [moving, setMoving] = useState(false);
  const disabledIds = useMemo(
    () => movingFolderId == null ? new Set() : folderDescendantIds(folders, movingFolderId),
    [folders, movingFolderId]
  );

  const submit = async () => {
    setMoving(true);
    try {
      await onMove(target == null ? null : Number(target));
      onOpenChange(false);
    } catch {
      // The caller owns the user-facing error. Keep the dialog open so the
      // same destination can be retried without an unhandled event promise.
    } finally {
      setMoving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={(next) => { if (next) setTarget(currentFolderId); onOpenChange(next); }}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>Move {itemLabel} to folder</DialogTitle>
          <DialogDescription>Choose a private library location for {itemCount} {itemCount === 1 ? itemLabel : `${itemLabel}s`}.</DialogDescription>
        </DialogHeader>
        <div className="max-h-80 overflow-y-auto rounded-md border p-2" role="tree" aria-label="Destination folder">
          <Button type="button" variant={target == null ? "secondary" : "ghost"} className="h-9 w-full justify-start px-2" onClick={() => setTarget(null)}>
            <Home className="mr-2 h-4 w-4" /> My Library / root
          </Button>
          {folders.length > 0 ? (
            <LibraryFolderTree folders={folders} activeFolderId={target} onSelect={(folder) => setTarget(folder.id)} disabledIds={disabledIds} />
          ) : (
            <p className="p-4 text-center text-sm text-muted-foreground">No folders yet. Comics can stay in My Library.</p>
          )}
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={moving}>Cancel</Button>
          <Button type="button" onClick={submit} disabled={moving}>{moving ? "Moving…" : "Move"}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
