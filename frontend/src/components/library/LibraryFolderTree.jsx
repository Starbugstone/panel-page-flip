import { Folder, FolderOpen } from "lucide-react";
import { foldersByParent } from "@/lib/library-folders";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export function LibraryFolderTree({ folders, activeFolderId, onSelect, disabledIds = new Set(), className }) {
  const grouped = foldersByParent(folders);

  const renderChildren = (parentId = null, depth = 0) => (grouped.get(parentId) || []).map((folder) => {
    const active = Number(activeFolderId) === Number(folder.id);
    const disabled = disabledIds.has(Number(folder.id));
    return (
      <div key={folder.id}>
        <Button
          type="button"
          variant={active ? "secondary" : "ghost"}
          className="h-9 w-full justify-start truncate px-2"
          style={{ paddingLeft: `${8 + depth * 18}px` }}
          onClick={() => onSelect(folder)}
          disabled={disabled}
          aria-current={active ? "page" : undefined}
        >
          {active ? <FolderOpen className="mr-2 h-4 w-4 shrink-0" /> : <Folder className="mr-2 h-4 w-4 shrink-0" />}
          <span className="truncate">{folder.name}</span>
        </Button>
        {renderChildren(Number(folder.id), depth + 1)}
      </div>
    );
  });

  return <div className={cn("space-y-0.5", className)}>{renderChildren()}</div>;
}
