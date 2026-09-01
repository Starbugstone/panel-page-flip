import { useCallback, useState } from "react";
import { PendingSharesAlert } from "@/components/PendingSharesAlert.jsx";
import { SearchBar } from "@/components/SearchBar.jsx";
import { LibraryDialogs } from "@/components/library/LibraryDialogs";
import { LibraryFolderBar } from "@/components/library/LibraryFolderBar";
import { LibraryResults } from "@/components/library/LibraryResults";
import { DesktopLibrarySidebar, LibrarySidebar } from "@/components/library/LibrarySidebar";
import { LibraryToolbar } from "@/components/library/LibraryToolbar";
import { ComicTitleRenameBar } from "@/components/library/ComicTitleRenameBar";
import { Sheet, SheetContent, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import { useComicLibrary } from "@/hooks/use-comic-library.jsx";
import { useJumpToComic } from "@/hooks/use-jump-to-comic";
import { useFolderComicTitleRenamer } from "@/hooks/use-folder-comic-title-renamer";
import { useLibraryContents } from "@/hooks/use-library-contents";
import { useLibraryComicActions } from "@/hooks/use-library-comic-actions";
import { useLibraryFolderActions } from "@/hooks/use-library-folder-actions";
import { useLibraryFolders } from "@/hooks/use-library-folders";
import { useLibraryLocation } from "@/hooks/use-library-location";
import { useLibrarySorts } from "@/hooks/use-library-sorts";
import { useLibrarySearch } from "@/hooks/use-library-search";
import { useSharing } from "@/hooks/use-sharing.jsx";
import { jumpToComicCard, latestReadComic } from "@/lib/last-read-jump";

function titleRenameLocation(isFolderView, activeFolderId, activeView) {
  return isFolderView ? `folder:${activeFolderId ?? "root"}` : `view:${activeView}`;
}

function uploadPath(isFolderView, activeFolderId) {
  if (!isFolderView) return "/upload";
  return `/upload?folder=${activeFolderId == null ? "root" : activeFolderId}`;
}

function FolderWorkspaceHeader({
  isFolderView,
  folders,
  activeFolderId,
  folderActions,
  setCreatingFolder,
  setSharingFolder,
  setMovingFolder,
  isSearchActive,
  titleRenamer,
  lastReadComic,
}) {
  if (!isFolderView) return null;

  return (
    <>
      <LibraryFolderBar
        folders={folders}
        activeFolderId={activeFolderId}
        onNavigate={folderActions.navigateFolder}
        onCreate={() => setCreatingFolder(true)}
        onShare={async () => setSharingFolder(await folderActions.shareCurrentFolder())}
        onMove={() => setMovingFolder(true)}
        onRename={folderActions.renameCurrentFolder}
        onDelete={folderActions.deleteCurrentFolder}
        onAutoRename={!isSearchActive && !titleRenamer.session ? titleRenamer.startPreview : null}
        onJumpToLastRead={lastReadComic && (() => jumpToComicCard(lastReadComic.id))}
      />
      <ComicTitleRenameBar
        session={titleRenamer.session}
        onAccept={titleRenamer.accept}
        onUndo={titleRenamer.undo}
      />
    </>
  );
}

function LibrarySearchStatus({ isSearching, showSkeleton }) {
  if (!isSearching || showSkeleton) return null;
  return <div className="rounded-lg border bg-card p-4 text-center" role="status">Searching the whole library…</div>;
}

/**
 * The library: where the URL points, what came back, and what can be done to it.
 *
 * This composes and does not decide. Which comics the URL is asking for lives
 * in `useLibraryLocation`, fetching them in `useLibrarySearch`, and changing
 * them in the two action hooks.
 */
export default function Dashboard() {
  const { comics, isLoading, isRefreshing, error, loadLibrary, updateComicProgress, removeComicsFromLibrary } = useComicLibrary();
  const { folders, isLoading: foldersLoading, createFolder, updateFolder, deleteFolder, moveComics } = useLibraryFolders();
  const { refreshSummary } = useSharing();

  const [viewMode, setViewMode] = useState("grid");
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [editingComic, setEditingComic] = useState(null);
  const [movingComic, setMovingComic] = useState(null);
  const [movingFolder, setMovingFolder] = useState(false);
  const [creatingFolder, setCreatingFolder] = useState(false);
  // The comics the share workflow should open with, or null when it is closed.
  // One dialog for the card menu and for the table selection, so the two cannot
  // grow different ideas of what a share is.
  const [sharingComicIds, setSharingComicIds] = useState(null);
  // The folder the share workflow should open with, once the server has said
  // what is in it, or null when it is closed.
  const [sharingFolder, setSharingFolder] = useState(null);

  const closeSidebar = useCallback(() => setSidebarOpen(false), []);
  const location = useLibraryLocation({ folders, foldersLoading, onNavigate: closeSidebar });
  const { isFolderView, activeFolderId, activeView, jumpComicId, ownership, invalidFolder, navigateFolder, navigateView } = location;
  const { sort, setSort } = useLibrarySorts(activeView);

  const { isSearching, isSearchActive, search, loadComics, refreshCurrent } = useLibrarySearch({
    loadLibrary, ownership, isFolderView, activeFolderId, foldersLoading, invalidFolder,
  });

  const comicActions = useLibraryComicActions({
    refreshCurrent, updateComicProgress, removeComicsFromLibrary, refreshSummary, moveComics,
  });
  const folderActions = useLibraryFolderActions({
    folders, activeFolderId, navigateFolder, createFolder, updateFolder, deleteFolder,
  });

  const titleRenamer = useFolderComicTitleRenamer({
    locationKey: titleRenameLocation(isFolderView, activeFolderId, activeView),
    comics,
    refreshCurrent,
  });

  const { visibleComics, childFolders, folderNames } = useLibraryContents({
    comics: titleRenamer.previewComics, folders, activeView, activeFolderId, isSearchActive, isFolderView, sort,
  });

  // Only the grid renders the cards the jump scrolls to, and only a comic
  // someone has opened counts as a place to return to.
  const lastReadComic = viewMode === "grid" ? latestReadComic(visibleComics) : null;

  const showSkeleton = (isLoading || foldersLoading) && !isSearching;

  // The reader's way back names the comic it left; the same scroll takes it.
  // Wait until the grid itself is rendered: the provider may still hold the
  // previous list while the folder tree has temporarily replaced it with a
  // skeleton, and attempting the jump against that skeleton loses the request.
  useJumpToComic(jumpComicId, visibleComics, !showSkeleton && viewMode === "grid");

  const hasContent = visibleComics.length > 0 || childFolders.length > 0;
  const uploadUrl = uploadPath(isFolderView, activeFolderId);

  const sidebar = (
    <LibrarySidebar
      folders={folders}
      activeFolderId={isFolderView ? activeFolderId : null}
      activeView={isFolderView ? "folders" : activeView}
      onFolderSelect={navigateFolder}
      onViewSelect={navigateView}
      onCreateFolder={folderActions.createLibraryFolder}
    />
  );

  return (
    <div className="container mx-auto px-4 py-8">
      <LibraryToolbar
        isRefreshing={isRefreshing}
        sort={sort}
        onSortChange={setSort}
        viewMode={viewMode}
        onViewModeChange={setViewMode}
        onOpenSidebar={() => setSidebarOpen(true)}
        uploadUrl={uploadUrl}
      />

      <div className="mb-6 flex justify-center"><SearchBar onSearch={search} isSearching={isSearching} /></div>
      <PendingSharesAlert />
      <Sheet open={sidebarOpen} onOpenChange={setSidebarOpen}>
        <SheetContent side="left">
          <SheetHeader className="mb-5"><SheetTitle>Library</SheetTitle></SheetHeader>
          {sidebar}
        </SheetContent>
      </Sheet>

      <div className="grid gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
        <DesktopLibrarySidebar>{sidebar}</DesktopLibrarySidebar>
        <section className="min-w-0 space-y-5" aria-label="Library results">
          <FolderWorkspaceHeader
            isFolderView={isFolderView}
            folders={folders}
            activeFolderId={activeFolderId}
            folderActions={{ ...folderActions, navigateFolder }}
            setCreatingFolder={setCreatingFolder}
            setSharingFolder={setSharingFolder}
            setMovingFolder={setMovingFolder}
            isSearchActive={isSearchActive}
            titleRenamer={titleRenamer}
            lastReadComic={lastReadComic}
          />

          <LibrarySearchStatus isSearching={isSearching} showSkeleton={showSkeleton} />

          <LibraryResults
            showSkeleton={showSkeleton}
            error={error}
            hasContent={hasContent}
            onRetry={loadComics}
            emptyState={{
              isSearchActive,
              isFolderView,
              activeView,
              uploadUrl,
              onClearSearch: () => search({ query: "", tags: [] }),
            }}
            contents={{
              viewMode,
              comics: visibleComics,
              childFolders,
              folders,
              folderNames,
              showLocation: isSearchActive || !isFolderView,
              onOpenFolder: navigateFolder,
              onEditComic: setEditingComic,
              comicActions: { ...comicActions, onMoveComic: setMovingComic },
              onShareComics: setSharingComicIds,
            }}
          />
        </section>
      </div>

      <LibraryDialogs
        folders={folders}
        editing={{ comic: editingComic, onClose: () => setEditingComic(null) }}
        sharing={{
          comicIds: sharingComicIds,
          folder: sharingFolder,
          onClose: () => { setSharingComicIds(null); setSharingFolder(null); },
        }}
        movingComic={{ comic: movingComic, onClose: () => setMovingComic(null) }}
        movingFolder={{ open: movingFolder, folderId: activeFolderId, onOpenChange: setMovingFolder }}
        creatingFolder={{ open: creatingFolder, parent: folderActions.currentFolder() ?? null, onOpenChange: setCreatingFolder }}
        folderActions={folderActions}
        onMoveComics={comicActions.moveComicsToFolder}
        onSaveComic={comicActions.saveComic}
        onShared={() => loadLibrary()}
      />
    </div>
  );
}
