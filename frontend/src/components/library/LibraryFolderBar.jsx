import { FolderInput, FolderPlus, History, Pencil, Share2, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { LibraryBreadcrumbs } from "@/components/library/LibraryBreadcrumbs";

/**
 * Where in the tree the library is, and what can be done to that folder.
 *
 * Share, move, rename and delete are absent at the root, which is not a folder
 * those actions can target. Creation remains available at every location. The
 * jump to the last-read comic only arrives when there is a card to jump to —
 * the caller withholds it in table view and when nothing here has been opened.
 */
export function LibraryFolderBar({ folders, activeFolderId, onNavigate, onCreate, onShare, onMove, onRename, onDelete, onJumpToLastRead }) {
  return (
    <div className="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-lg border bg-card px-3 py-2">
      <LibraryBreadcrumbs folders={folders} folderId={activeFolderId} onNavigate={onNavigate} />
      <div className="flex flex-wrap gap-1">
        {onJumpToLastRead && (
          <Button variant="outline" size="sm" onClick={onJumpToLastRead}>
            <History className="mr-1 h-4 w-4" />
            Last read
          </Button>
        )}
        <Button variant="outline" size="sm" onClick={onCreate}>
          <FolderPlus className="mr-1 h-4 w-4" />
          {activeFolderId == null ? "New folder" : "New subfolder"}
        </Button>
        {activeFolderId != null && (
          <>
            {/* Named for the folder rather than for the comics in it: the
                whole point is that somebody who wants to hand over DragonBall
                should not have to think about how many volumes that is. */}
            <Button variant="outline" size="sm" onClick={onShare}><Share2 className="mr-1 h-4 w-4" />Share folder</Button>
            <Button variant="ghost" size="sm" onClick={onMove}><FolderInput className="mr-1 h-4 w-4" />Move</Button>
            <Button variant="ghost" size="sm" onClick={onRename}><Pencil className="mr-1 h-4 w-4" />Rename</Button>
            <Button variant="ghost" size="sm" onClick={onDelete}><Trash2 className="mr-1 h-4 w-4" />Delete</Button>
          </>
        )}
      </div>
    </div>
  );
}
