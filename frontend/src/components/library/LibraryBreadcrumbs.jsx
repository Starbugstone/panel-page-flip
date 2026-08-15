import { ChevronRight, Library } from "lucide-react";
import { Button } from "@/components/ui/button";
import { folderAncestors } from "@/lib/library-folders";

export function LibraryBreadcrumbs({ folders, folderId, onNavigate }) {
  const ancestors = folderId ? folderAncestors(folders, folderId) : [];
  return (
    <nav aria-label="Library breadcrumb" className="flex min-w-0 items-center gap-1 overflow-hidden text-sm">
      <Button type="button" variant="ghost" size="sm" className="h-8 shrink-0 px-2" onClick={() => onNavigate(null)}>
        <Library className="mr-1 h-4 w-4" /> My Library
      </Button>
      {ancestors.map((folder, index) => (
        <div key={folder.id} className="flex min-w-0 items-center gap-1">
          <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="h-8 min-w-0 truncate px-2"
            onClick={() => onNavigate(folder.id)}
            aria-current={index === ancestors.length - 1 ? "page" : undefined}
          >
            <span className="truncate">{folder.name}</span>
          </Button>
        </div>
      ))}
    </nav>
  );
}
