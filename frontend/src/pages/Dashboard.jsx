import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { FolderCog, FolderInput, Folders, Grid3X3, List, Pencil, Trash2, Upload } from "lucide-react";
import { ComicCard } from "@/components/ComicCard.jsx";
import { ComicTableView } from "@/components/ComicTableView.jsx";
import { SearchBar } from "@/components/SearchBar.jsx";
import { Button } from "@/components/ui/button";
import { Sheet, SheetContent, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import { ComicEditDialog } from "@/components/ComicEditDialog.jsx";
import { ShareComicsDialog } from "@/components/ShareComicsDialog.jsx";
import { PendingSharesAlert } from "@/components/PendingSharesAlert.jsx";
import { LibrarySidebar } from "@/components/library/LibrarySidebar";
import { LibraryBreadcrumbs } from "@/components/library/LibraryBreadcrumbs";
import { LibraryFolderCard } from "@/components/library/LibraryFolderCard";
import { MoveToFolderDialog } from "@/components/library/MoveToFolderDialog";
import { useToast } from "@/hooks/use-toast.js";
import { useComicLibrary } from "@/hooks/use-comic-library.jsx";
import { useLibraryFolders } from "@/hooks/use-library-folders";
import { useSharing } from "@/hooks/use-sharing.jsx";
import { api } from "@/lib/api";
import { getComicProgressState } from "@/lib/comic-progress";
import { buildComicUpdatePayload } from "@/lib/comic-updates";
import { foldersByParent } from "@/lib/library-folders";

const EAGER_COVER_COUNT = 8;
const VIEWS = new Set(["all", "mine", "shared", "reading", "unread", "dropbox"]);

export default function Dashboard() {
  const { comics, isLoading, isRefreshing, error, loadLibrary, updateComicProgress, removeComicsFromLibrary } = useComicLibrary();
  const { folders, isLoading: foldersLoading, createFolder, updateFolder, deleteFolder, moveComics } = useLibraryFolders();
  const { refreshSummary } = useSharing();
  const { toast } = useToast();
  const [searchParams, setSearchParams] = useSearchParams();
  const [isSearching, setIsSearching] = useState(false);
  const [isSearchActive, setIsSearchActive] = useState(false);
  const [editingComic, setEditingComic] = useState(null);
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [viewMode, setViewMode] = useState("grid");
  const [sort, setSort] = useState("title-asc");
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [movingComic, setMovingComic] = useState(null);
  const [movingFolder, setMovingFolder] = useState(false);
  // The comics the share workflow should open with, or null when it is closed.
  // One dialog for the card menu and for the table selection, so the two cannot
  // grow different ideas of what a share is.
  const [sharingComicIds, setSharingComicIds] = useState(null);
  const lastComicsUrl = useRef("/api/comics");
  const lastSearchQuery = useRef("");
  const searchRequestId = useRef(0);

  const rawFolder = searchParams.get("folder");
  const isFolderView = rawFolder !== null;
  const activeFolderId = rawFolder && rawFolder !== "root" && /^\d+$/.test(rawFolder) ? Number(rawFolder) : null;
  const requestedView = searchParams.get("view") || "all";
  const activeView = VIEWS.has(requestedView) ? requestedView : "all";
  const ownership = activeView === "mine" || activeView === "shared" ? activeView : "all";
  const malformedFolder = rawFolder !== null && rawFolder !== "root" && !/^\d+$/.test(rawFolder);
  const missingFolder = !foldersLoading && activeFolderId != null && !folders.some((folder) => Number(folder.id) === activeFolderId);
  const invalidFolder = malformedFolder || missingFolder;

  const navigateFolder = useCallback((folderId) => {
    const next = new URLSearchParams();
    next.set("folder", folderId == null ? "root" : String(folderId));
    setSearchParams(next);
    setSidebarOpen(false);
  }, [setSearchParams]);

  const navigateView = useCallback((view) => {
    const next = new URLSearchParams();
    if (view !== "all") next.set("view", view);
    setSearchParams(next);
    setSidebarOpen(false);
  }, [setSearchParams]);

  useEffect(() => {
    if (foldersLoading) return;
    if (invalidFolder) {
      const timer = window.setTimeout(() => navigateFolder(null), 0);
      return () => window.clearTimeout(timer);
    }
  }, [foldersLoading, invalidFolder, navigateFolder]);

  const fetchComicsFromApi = useCallback(async (url, fuzzyQuery = "") => {
    lastComicsUrl.current = url;
    lastSearchQuery.current = fuzzyQuery;
    const requestId = searchRequestId.current + 1;
    searchRequestId.current = requestId;
    if (fuzzyQuery || url.includes("tags=")) setIsSearching(true);
    try {
      await loadLibrary({ url, fuzzyQuery });
    } finally {
      if (searchRequestId.current === requestId) setIsSearching(false);
    }
  }, [loadLibrary]);

  const buildLibraryUrl = useCallback((tagNames = [], includeLocation = true) => {
    const query = new URLSearchParams();
    if (tagNames.length > 0) query.set("tags", tagNames.join(","));
    if (ownership !== "all") query.set("ownership", ownership);
    if (includeLocation && isFolderView) query.set("folder", activeFolderId == null ? "root" : String(activeFolderId));
    return query.size > 0 ? `/api/comics?${query}` : "/api/comics";
  }, [activeFolderId, isFolderView, ownership]);

  const loadComics = useCallback(async () => {
    setIsSearchActive(false);
    await fetchComicsFromApi(buildLibraryUrl());
  }, [buildLibraryUrl, fetchComicsFromApi]);

  useEffect(() => {
    // Resolve the viewer's private folder tree first. This avoids a guaranteed
    // 400/404 request for malformed or stale bookmarked folder ids before the
    // URL fallback can replace them with the root location.
    if (isFolderView && (foldersLoading || invalidFolder)) return;
    const url = buildLibraryUrl();
    lastComicsUrl.current = url;
    lastSearchQuery.current = "";
    const requestId = searchRequestId.current + 1;
    searchRequestId.current = requestId;
    let ignore = false;
    loadLibrary({ url, fuzzyQuery: "" }).finally(() => {
      if (!ignore && searchRequestId.current === requestId) setIsSearching(false);
      if (!ignore) setIsSearchActive(false);
    });
    return () => { ignore = true; };
  }, [buildLibraryUrl, foldersLoading, invalidFolder, isFolderView, loadLibrary]);

  const handleSearch = async ({ query = "", tags = [] }) => {
    const safeQuery = query.slice(0, 100);
    const safeTags = tags.slice(0, 10);
    if (query.length > safeQuery.length) {
      toast({
        title: "Search query truncated",
        description: "Search queries are limited to 100 characters.",
        variant: "warning",
      });
    }
    if (tags.length > safeTags.length) {
      toast({
        title: "Too many tags selected",
        description: "Only the first 10 tags will be used for filtering.",
        variant: "warning",
      });
    }
    if (!safeQuery && safeTags.length === 0) {
      await loadComics();
      return;
    }
    setIsSearchActive(true);
    const tagQuery = new URLSearchParams();
    if (safeTags.length > 0) tagQuery.set("tags", safeTags.join(","));
    await fetchComicsFromApi(tagQuery.size > 0 ? `/api/comics?${tagQuery}` : "/api/comics", safeQuery);
  };

  const refreshCurrent = () => fetchComicsFromApi(lastComicsUrl.current, lastSearchQuery.current);

  const resetReadingProgress = async (comicId) => {
    await api.post(`/api/comics/${comicId}/reading-progress/reset`, {});
    updateComicProgress(comicId, null);
    return true;
  };

  const handleSaveComic = async (updatedComic) => {
    await api.patch("/api/comics", { updates: [buildComicUpdatePayload(updatedComic)] });
    await refreshCurrent();
    return true;
  };

  const deleteComic = async (comicId, { confirmOrphaned = false } = {}) => {
    await api.delete("/api/comics", { body: { comicIds: [comicId], confirmOrphaned } });
    removeComicsFromLibrary([comicId]);
    return true;
  };

  const removeSharedComic = async (comic) => {
    await api.post(`/api/shares/${comic.shareId}/remove`, {});
    removeComicsFromLibrary([comic.id]);
    refreshSummary();
  };

  const addTagToSelectedComics = async (comicIds, tag) => {
    try {
      await api.patch("/api/comics", { updates: comicIds.map((id) => ({ id, changes: { addTags: [tag] } })) });
      await refreshCurrent();
      toast({ title: "Tag added", description: `Added “${tag}” to ${comicIds.length} comic(s).` });
    } catch (requestError) {
      toast({ title: "Bulk update failed", description: requestError.message, variant: "destructive" });
      throw requestError;
    }
  };

  const deleteSelectedComics = async (comicIds, { confirmOrphaned = false } = {}) => {
    try {
      await api.delete("/api/comics", { body: { comicIds, confirmOrphaned } });
      removeComicsFromLibrary(comicIds);
      toast({ title: "Comics deleted", description: `${comicIds.length} comic(s) removed from your library.` });
    } catch (requestError) {
      if (requestError.data?.code !== "orphaned_comics_confirmation_required") {
        toast({ title: "Bulk deletion failed", description: requestError.message, variant: "destructive" });
      }
      throw requestError;
    }
  };

  const moveSelectedComics = async (comicIds, folderId) => {
    try {
      await moveComics(comicIds, folderId);
      await refreshCurrent();
      toast({ title: "Moved", description: `${comicIds.length} comic(s) moved.` });
    } catch (requestError) {
      toast({ title: "Move failed", description: requestError.message, variant: "destructive" });
      throw requestError;
    }
  };

  const createLibraryFolder = async (name, parentId) => {
    try {
      await createFolder(name, parentId);
      toast({ title: "Folder created", description: `Created “${name}”.` });
      return true;
    } catch (requestError) {
      toast({ title: "Could not create folder", description: requestError.message, variant: "destructive" });
      return false;
    }
  };

  const renameCurrentFolder = async () => {
    const folder = folders.find((item) => Number(item.id) === activeFolderId);
    if (!folder) return;
    const name = window.prompt("Rename folder", folder.name)?.trim();
    if (!name || name === folder.name) return;
    try {
      await updateFolder(folder.id, { name });
    } catch (requestError) {
      toast({ title: "Could not rename folder", description: requestError.message, variant: "destructive" });
    }
  };

  const deleteCurrentFolder = async () => {
    const folder = folders.find((item) => Number(item.id) === activeFolderId);
    if (!folder || !window.confirm(`Delete “${folder.name}”? No comic files will be deleted.`)) return;
    try {
      await deleteFolder(folder.id, false);
      navigateFolder(folder.parentId);
    } catch (requestError) {
      if (requestError.data?.code !== "folder_deletion_confirmation_required") {
        toast({ title: "Could not delete folder", description: requestError.message, variant: "destructive" });
        return;
      }
      const summary = requestError.data.summary;
      const destination = folder.parentId == null ? "My Library" : "the parent folder";
      if (!window.confirm(`This removes ${summary.folderCount} folder(s). ${summary.comicCount} comic(s) will move to ${destination}. No comic files will be deleted.`)) return;
      try {
        await deleteFolder(folder.id, true);
        navigateFolder(folder.parentId);
      } catch (deleteError) {
        toast({ title: "Could not delete folder", description: deleteError.message, variant: "destructive" });
      }
    }
  };

  const filteredComics = useMemo(() => {
    let result = comics;
    if (!isSearchActive && !isFolderView) {
      if (activeView === "reading") result = result.filter((comic) => getComicProgressState(comic).label === "In progress");
      if (activeView === "unread") result = result.filter((comic) => getComicProgressState(comic).label === "Not started");
      if (activeView === "dropbox") result = result.filter((comic) => comic.tags?.includes("Dropbox"));
    }
    return [...result].sort((a, b) => {
      if (sort === "title-desc") return (b.title || "").localeCompare(a.title || "");
      if (sort === "uploaded-desc") return new Date(b.uploadedAt || 0) - new Date(a.uploadedAt || 0);
      if (sort === "uploaded-asc") return new Date(a.uploadedAt || 0) - new Date(b.uploadedAt || 0);
      if (sort === "updated-desc") return new Date(b.updatedAt || 0) - new Date(a.updatedAt || 0);
      return (a.title || "").localeCompare(b.title || "");
    });
  }, [activeView, comics, isFolderView, isSearchActive, sort]);

  const childFolders = useMemo(() => {
    if (!isFolderView || isSearchActive) return [];
    return foldersByParent(folders).get(activeFolderId) || [];
  }, [activeFolderId, folders, isFolderView, isSearchActive]);
  const folderNames = useMemo(() => new Map(folders.map((folder) => [Number(folder.id), folder.name])), [folders]);
  const showSkeleton = (isLoading || foldersLoading) && !isSearching;
  const hasContent = filteredComics.length > 0 || childFolders.length > 0;
  const uploadUrl = isFolderView ? `/upload?folder=${activeFolderId == null ? "root" : activeFolderId}` : "/upload";

  const sidebar = (
    <LibrarySidebar folders={folders} activeFolderId={isFolderView ? activeFolderId : null} activeView={isFolderView ? "folders" : activeView} onFolderSelect={navigateFolder} onViewSelect={navigateView} onCreateFolder={createLibraryFolder} />
  );

  return (
    <div className="container mx-auto px-4 py-8">
      <div className="mb-6 flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
        <div className="flex items-center gap-3"><h1 className="text-3xl font-comic">My Comic Library</h1>{isRefreshing && <span className="text-sm text-muted-foreground" role="status">Refreshing…</span>}</div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="outline" className="lg:hidden" onClick={() => setSidebarOpen(true)}><Folders className="mr-2 h-4 w-4" />Folders</Button>
          <select value={sort} onChange={(event) => setSort(event.target.value)} className="h-9 rounded-md border bg-background px-3 text-sm" aria-label="Sort comics">
            <option value="title-asc">Title A–Z</option><option value="title-desc">Title Z–A</option><option value="uploaded-desc">Recently added</option><option value="uploaded-asc">Oldest added</option><option value="updated-desc">Recently updated</option>
          </select>
          <div className="flex rounded-md border p-1" aria-label="Library view">
            <Button variant={viewMode === "grid" ? "secondary" : "ghost"} size="sm" onClick={() => setViewMode("grid")} aria-pressed={viewMode === "grid"}><Grid3X3 className="mr-2 h-4 w-4" />Grid</Button>
            <Button variant={viewMode === "table" ? "secondary" : "ghost"} size="sm" onClick={() => setViewMode("table")} aria-pressed={viewMode === "table"}><List className="mr-2 h-4 w-4" />Table</Button>
          </div>
          <Button asChild><Link to={uploadUrl}><Upload className="mr-2 h-4 w-4" />Upload</Link></Button>
        </div>
      </div>

      <div className="mb-6 flex justify-center"><SearchBar onSearch={handleSearch} isSearching={isSearching} /></div>
      <PendingSharesAlert />
      <Sheet open={sidebarOpen} onOpenChange={setSidebarOpen}><SheetContent side="left"><SheetHeader className="mb-5"><SheetTitle>Library</SheetTitle></SheetHeader>{sidebar}</SheetContent></Sheet>

      <div className="grid gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
        <div className="hidden rounded-lg border bg-card p-3 lg:block">{sidebar}</div>
        <main className="min-w-0 space-y-5">
          {isFolderView && (
            <div className="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-lg border bg-card px-3 py-2">
              <LibraryBreadcrumbs folders={folders} folderId={activeFolderId} onNavigate={navigateFolder} />
              {activeFolderId != null && <div className="flex gap-1"><Button variant="ghost" size="sm" onClick={() => setMovingFolder(true)}><FolderInput className="mr-1 h-4 w-4" />Move</Button><Button variant="ghost" size="sm" onClick={renameCurrentFolder}><Pencil className="mr-1 h-4 w-4" />Rename</Button><Button variant="ghost" size="sm" onClick={deleteCurrentFolder}><Trash2 className="mr-1 h-4 w-4" />Delete</Button></div>}
            </div>
          )}

          {isSearching && !showSkeleton && <div className="rounded-lg border bg-card p-4 text-center" role="status">Searching the whole library…</div>}
          {showSkeleton ? (
            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4">{[...Array(6)].map((_, index) => <div key={index} className="animate-pulse"><div className="pt-[140%] bg-muted" /><div className="mt-2 h-4 rounded bg-muted" /></div>)}</div>
          ) : error ? (
            <div className="py-12 text-center"><p className="mb-4 text-xl text-destructive">{error}</p><Button onClick={loadComics}>Try Again</Button></div>
          ) : !hasContent ? (
            <div className="py-12 text-center"><FolderCog className="mx-auto mb-3 h-10 w-10 text-muted-foreground" /><p className="mb-4 text-xl text-muted-foreground">{isSearchActive ? "No comics found matching your search" : isFolderView ? "This folder is empty." : activeView === "shared" ? "Nobody has shared a comic with you yet." : "No comics in this view."}</p>{isSearchActive ? <Button onClick={() => handleSearch({ query: "", tags: [] })}>Clear Search</Button> : <Button asChild><Link to={uploadUrl}>Upload a comic</Link></Button>}</div>
          ) : viewMode === "table" ? (
            <div className="overflow-x-auto">
              {childFolders.length > 0 && <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">{childFolders.map((folder) => <LibraryFolderCard key={folder.id} folder={folder} onOpen={navigateFolder} />)}</div>}
              <ComicTableView comics={filteredComics} folders={folders} onEditComic={(comic) => { setEditingComic(comic); setIsEditDialogOpen(true); }} onBulkAddTag={addTagToSelectedComics} onBulkDelete={deleteSelectedComics} onBulkMove={moveSelectedComics} onShareSelected={setSharingComicIds} />
            </div>
          ) : (
            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4">
              {childFolders.map((folder) => <LibraryFolderCard key={folder.id} folder={folder} onOpen={navigateFolder} />)}
              {filteredComics.map((comic, index) => <ComicCard key={comic.id} comic={comic} coverPriority={index < EAGER_COVER_COUNT} onResetProgress={resetReadingProgress} onEditComic={(item) => { setEditingComic(item); setIsEditDialogOpen(true); }} onDeleteComic={deleteComic} onShareClick={(id) => setSharingComicIds([id])} onRemoveSharedComic={removeSharedComic} onMoveComic={setMovingComic} locationName={isSearchActive || !isFolderView ? (comic.libraryFolderId == null ? "My Library" : folderNames.get(Number(comic.libraryFolderId))) : null} />)}
            </div>
          )}
        </main>
      </div>

      {editingComic && <ComicEditDialog comic={editingComic} isOpen={isEditDialogOpen} onClose={() => { setIsEditDialogOpen(false); setEditingComic(null); }} onSave={handleSaveComic} />}
      {/* Mounted only while open and keyed on the selection, so a dialog opened
          from a different comic never inherits the previous one. */}
      {sharingComicIds && (
        <ShareComicsDialog
          key={sharingComicIds.join(",")}
          isOpen
          onClose={() => setSharingComicIds(null)}
          initialComicIds={sharingComicIds}
          lockSelection
          onShared={() => loadLibrary()}
        />
      )}
      <MoveToFolderDialog key={movingComic?.id ?? "no-comic"} open={Boolean(movingComic)} onOpenChange={(open) => { if (!open) setMovingComic(null); }} folders={folders} currentFolderId={movingComic?.libraryFolderId ?? null} itemCount={1} onMove={(folderId) => moveSelectedComics([movingComic.id], folderId)} />
      <MoveToFolderDialog key={`folder-${activeFolderId}`} open={movingFolder} onOpenChange={setMovingFolder} folders={folders} currentFolderId={folders.find((folder) => Number(folder.id) === activeFolderId)?.parentId ?? null} movingFolderId={activeFolderId} itemCount={1} itemLabel="folder" onMove={async (parentId) => {
        try {
          await updateFolder(activeFolderId, { parentId });
          toast({ title: "Folder moved" });
        } catch (moveError) {
          toast({ title: "Could not move folder", description: moveError.message, variant: "destructive" });
          throw moveError;
        }
      }} />
    </div>
  );
}
