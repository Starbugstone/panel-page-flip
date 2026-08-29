import { Link } from "react-router-dom";
import { FolderCog } from "lucide-react";
import { Button } from "@/components/ui/button";

/**
 * Why there is nothing here, and the one thing worth doing about it.
 *
 * An empty search offers to clear itself; an empty library offers an upload.
 * Getting that the wrong way round strands somebody on a page whose only
 * button cannot help them.
 */
export function LibraryEmptyState({ isSearchActive, isFolderView, activeView, uploadUrl, onClearSearch }) {
  const message = isSearchActive ? "No comics found matching your search"
    : isFolderView ? "This folder is empty."
      : activeView === "shared" ? "Nobody has shared a comic with you yet."
        : "No comics in this view.";

  return (
    <div className="py-12 text-center">
      <FolderCog className="mx-auto mb-3 h-10 w-10 text-muted-foreground" />
      <p className="mb-4 text-xl text-muted-foreground">{message}</p>
      {isSearchActive
        ? <Button onClick={onClearSearch}>Clear Search</Button>
        : <Button asChild><Link to={uploadUrl}>Upload a comic</Link></Button>}
    </div>
  );
}
