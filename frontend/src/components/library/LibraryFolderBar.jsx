import { FolderInput, Pencil, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { LibraryBreadcrumbs } from "@/components/library/LibraryBreadcrumbs";

/**
 * Where in the tree the library is, and what can be done to that folder.
 *
 * The actions are absent at the root, which is not a folder anybody can rename
 * or delete.
 */
export function LibraryFolderBar({ folders, activeFolderId, onNavigate, onMove, onRename, onDelete }) {
  return (
    <div className="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-lg border bg-card px-3 py-2">
      <LibraryBreadcrumbs folders={folders} folderId={activeFolderId} onNavigate={onNavigate} />
      {activeFolderId != null && (
        <div className="flex gap-1">
          <Button variant="ghost" size="sm" onClick={onMove}><FolderInput className="mr-1 h-4 w-4" />Move</Button>
          <Button variant="ghost" size="sm" onClick={onRename}><Pencil className="mr-1 h-4 w-4" />Rename</Button>
          <Button variant="ghost" size="sm" onClick={onDelete}><Trash2 className="mr-1 h-4 w-4" />Delete</Button>
        </div>
      )}
    </div>
  );
}
