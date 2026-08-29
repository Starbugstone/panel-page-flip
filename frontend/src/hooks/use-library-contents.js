import { useMemo } from "react";
import { applyViewFilter, sortComics } from "@/lib/library-view";
import { foldersByParent } from "@/lib/library-folders";

/**
 * What is actually on screen: the comics after the view and the sort, and the
 * folders that sit alongside them.
 *
 * Subfolders are shown only when browsing the tree. A search reaches across
 * the whole library, and offering folders beside results that ignore them
 * would suggest the results respect them.
 */
export function useLibraryContents({ comics, folders, activeView, activeFolderId, isSearchActive, isFolderView, sort }) {
  const visibleComics = useMemo(
    () => sortComics(applyViewFilter(comics, { activeView, isSearchActive, isFolderView }), sort),
    [activeView, comics, isFolderView, isSearchActive, sort]
  );

  const childFolders = useMemo(() => {
    if (!isFolderView || isSearchActive) return [];
    return foldersByParent(folders).get(activeFolderId) || [];
  }, [activeFolderId, folders, isFolderView, isSearchActive]);

  const folderNames = useMemo(
    () => new Map(folders.map((folder) => [Number(folder.id), folder.name])),
    [folders]
  );

  return { visibleComics, childFolders, folderNames };
}
