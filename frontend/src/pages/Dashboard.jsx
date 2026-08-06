
import { useCallback, useState, useEffect, useRef } from "react";
import { ComicCard } from "@/components/ComicCard.jsx";
import { ComicTableView } from "@/components/ComicTableView.jsx";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs.jsx";
import { SearchBar } from "@/components/SearchBar.jsx";
import { Button } from "@/components/ui/button";
import { Grid3X3, List, Upload } from "lucide-react";
import { Link } from "react-router-dom";
import { useToast } from "@/hooks/use-toast.js";
import { ComicEditDialog } from "@/components/ComicEditDialog.jsx";
import { ShareComicModal } from "@/components/ShareComicModal.jsx";
import { PendingSharesAlert } from "@/components/PendingSharesAlert.jsx";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { getComicProgressState } from "@/lib/comic-progress";
import { useComicLibrary } from "@/hooks/use-comic-library.jsx";

// Covers above the fold are worth fetching eagerly; the rest can wait until
// they are scrolled towards.
const EAGER_COVER_COUNT = 8;

export default function Dashboard() {
  // The library lives in a store, so returning from a comic re-renders the
  // cards that are already loaded instead of clearing them and starting over.
  const {
    comics,
    isLoading,
    isRefreshing,
    error,
    loadLibrary,
    updateComicProgress,
    removeComicsFromLibrary,
  } = useComicLibrary();
  const [isSearching, setIsSearching] = useState(false); // Specific state for search operations
  // A search keeps the current results visible under its own overlay; every
  // other first-time load has nothing to show yet but the skeleton.
  const showSkeleton = isLoading && !isSearching;
  const [isSearchActive, setIsSearchActive] = useState(false);
  const [editingComic, setEditingComic] = useState(null);
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [viewMode, setViewMode] = useState("grid");
  const { toast } = useToast();
  const lastComicsUrl = useRef('/api/comics');
  const lastSearchQuery = useRef('');
  // Searches can overlap. Only the last one started is allowed to take the
  // overlay down, so a quick first result cannot uncover a search still running.
  const searchRequestId = useRef(0);

  // State for ShareComicModal
  const [isShareModalOpen, setIsShareModalOpen] = useState(false);
  const [shareModalComicId, setShareModalComicId] = useState(null);
  const [shareModalComicTitle, setShareModalComicTitle] = useState(null);

  const fetchComicsFromApi = useCallback(async (url, fuzzyQuery = '') => {
    lastComicsUrl.current = url;
    lastSearchQuery.current = fuzzyQuery;
    // A search keeps its own overlay over the results it is replacing; the store
    // decides between the skeleton and a quiet refresh for everything else.
    const isSearchRequest = Boolean(fuzzyQuery) || url.includes('tags=');
    const requestId = searchRequestId.current + 1;
    searchRequestId.current = requestId;
    if (isSearchRequest) {
      setIsSearching(true);
    }
    try {
      await loadLibrary({ url, fuzzyQuery });
    } finally {
      // Any latest request may take the overlay down, not just a search: a plain
      // reload started after a search is the one whose result is on screen, and
      // leaving the flag to the search alone would strand the overlay.
      if (searchRequestId.current === requestId) {
        setIsSearching(false);
      }
    }
  }, [loadLibrary]);

  const loadComics = useCallback(async () => {
    setIsSearchActive(false); // Reset search active state
    await fetchComicsFromApi('/api/comics');
  }, [fetchComicsFromApi]);

  const fetchFilteredComics = async (searchQuery, tagNamesArray) => {
    let url = '/api/comics';
    const queryParams = new URLSearchParams();
    if (tagNamesArray && tagNamesArray.length > 0) {
      queryParams.append('tags', tagNamesArray.join(','));
    }

    const queryString = queryParams.toString();
    if (queryString) {
      url += `?${queryString}`;
    }

    setIsSearchActive(!!searchQuery || (tagNamesArray && tagNamesArray.length > 0));
    await fetchComicsFromApi(url, searchQuery);
  };

  useEffect(() => {
    loadComics();
  }, [loadComics]);

  // Constants for input validation
  const MAX_SEARCH_QUERY_LENGTH = 100;
  const MAX_TAGS_COUNT = 10;
  
  const handleSearch = (params) => {
    // Validate and sanitize search parameters
    const sanitizedParams = {
      query: params.query ? params.query.slice(0, MAX_SEARCH_QUERY_LENGTH) : "",
      tags: params.tags ? params.tags.slice(0, MAX_TAGS_COUNT) : []
    };
    
    // Show warning if input was truncated
    if (params.query && params.query.length > MAX_SEARCH_QUERY_LENGTH) {
      toast({
        title: "Search query truncated",
        description: `Your search query was too long and has been truncated to ${MAX_SEARCH_QUERY_LENGTH} characters.`,
        variant: "warning"
      });
    }
    
    if (params.tags && params.tags.length > MAX_TAGS_COUNT) {
      toast({
        title: "Too many tags selected",
        description: `Only the first ${MAX_TAGS_COUNT} tags will be used for filtering.`,
        variant: "warning"
      });
    }
    
    if (!sanitizedParams.query && (!sanitizedParams.tags || sanitizedParams.tags.length === 0)) {
      loadComics(); // Fetch all comics if search is cleared
    } else {
      fetchFilteredComics(sanitizedParams.query, sanitizedParams.tags);
    }
  };

  const resetReadingProgress = async (comicId) => {
    try {
      await api.post(`/api/comics/${comicId}/reading-progress/reset`, {});

      updateComicProgress(comicId, null);

      return true;
    } catch (error) {
      logger.error("Error resetting reading progress:", error);
      throw error;
    }
  };
  
  const handleEditComic = (comic) => {
    setEditingComic(comic);
    setIsEditDialogOpen(true);
  };
  
  const handleSaveComic = async (updatedComic) => {
    try {
      await api.patch("/api/comics", {
        updates: [{
          id: updatedComic.id,
          changes: {
            title: updatedComic.title,
            author: updatedComic.author,
            publisher: updatedComic.publisher,
            description: updatedComic.description,
            tags: updatedComic.tags,
          },
        }],
      });
      
      await fetchComicsFromApi(lastComicsUrl.current, lastSearchQuery.current);

      return true;
    } catch (error) {
      logger.error("Error updating comic:", error);
      throw error;
    }
  };

  const deleteComic = async (comicId, { confirmOrphaned = false } = {}) => {
    try {
      await api.delete("/api/comics", { body: { comicIds: [comicId], confirmOrphaned } });

      removeComicsFromLibrary([comicId]);

      return true;
    } catch (error) {
      if (error.data?.code !== "orphaned_comics_confirmation_required") {
        logger.error("Error deleting comic:", error);
      }
      throw error;
    }
  };

  const addTagToSelectedComics = async (comicIds, tag) => {
    try {
      await api.patch("/api/comics", {
        updates: comicIds.map((id) => ({ id, changes: { addTags: [tag] } })),
      });
      await fetchComicsFromApi(lastComicsUrl.current, lastSearchQuery.current);
      toast({ title: "Tag added", description: `Added “${tag}” to ${comicIds.length} comic(s).` });
    } catch (error) {
      logger.error("Error adding a tag to selected comics:", error);
      toast({ title: "Bulk update failed", description: error.message, variant: "destructive" });
      throw error;
    }
  };

  const deleteSelectedComics = async (comicIds, { confirmOrphaned = false } = {}) => {
    try {
      const result = await api.delete("/api/comics", { body: { comicIds, confirmOrphaned } });
      removeComicsFromLibrary(comicIds);
      const orphanCount = result.orphanedComicIds?.length || 0;
      toast({
        title: "Comics deleted",
        description: orphanCount > 0
          ? `${comicIds.length} ${comicIds.length === 1 ? "record" : "records"} removed; ${orphanCount} comic ${orphanCount === 1 ? "file was" : "files were"} already missing.`
          : `${comicIds.length} ${comicIds.length === 1 ? "comic was" : "comics were"} removed from your library.`,
      });
    } catch (error) {
      if (error.data?.code !== "orphaned_comics_confirmation_required") {
        logger.error("Error deleting selected comics:", error);
        toast({ title: "Bulk deletion failed", description: error.message, variant: "destructive" });
      }
      throw error;
    }
  };

  // Filters now operate on the 'comics' state directly. A finished comic is no
  // longer "currently reading", so it is classified by its progress state
  // rather than by page number alone.
  const isCompleted = (comic) => getComicProgressState(comic).label === "Fully read";
  const inProgressComics = comics.filter(comic => comic.lastReadPage > 0 && !isCompleted(comic));
  const unreadComics = comics.filter(comic => !comic.lastReadPage);
  const dropboxComics = comics.filter(comic => comic.tags && comic.tags.includes('Dropbox'));

  const comicTabs = [
    { value: "all", label: "All Comics", items: comics, alwaysShown: true },
    { value: "dropbox", label: "Dropbox", items: dropboxComics },
    { value: "reading", label: "Currently Reading", items: inProgressComics },
    { value: "unread", label: "Not Started", items: unreadComics },
  ];

  // Handlers for ShareComicModal
  const handleOpenShareModal = (comicId, comicTitle) => {
    setShareModalComicId(comicId);
    setShareModalComicTitle(comicTitle);
    setIsShareModalOpen(true);
  };

  const handleCloseShareModal = () => {
    setIsShareModalOpen(false);
    // Reset comic details for the modal, modal itself might have a delay for animation.
    setShareModalComicId(null);
    setShareModalComicTitle(null);
  };

  return (
    <div className="container mx-auto px-4 py-8">
      <div className="flex flex-col md:flex-row justify-between items-center mb-8 gap-4">
        <div className="flex items-center gap-3">
          <h1 className="text-3xl font-comic">My Comic Library</h1>
          {/* A background refresh keeps the cards it already has; this is the
              only sign that fresher data is on its way. */}
          {isRefreshing && (
            <span className="text-sm text-muted-foreground" role="status">Refreshing…</span>
          )}
        </div>
        <div className="flex flex-wrap items-center justify-center gap-2">
          <div className="flex rounded-md border p-1" aria-label="Library view">
            <Button variant={viewMode === "grid" ? "secondary" : "ghost"} size="sm" onClick={() => setViewMode("grid")} aria-pressed={viewMode === "grid"}>
              <Grid3X3 className="mr-2 h-4 w-4" /> Grid
            </Button>
            <Button variant={viewMode === "table" ? "secondary" : "ghost"} size="sm" onClick={() => setViewMode("table")} aria-pressed={viewMode === "table"}>
              <List className="mr-2 h-4 w-4" /> Table
            </Button>
          </div>
          <Link to="/upload">
            <Button className="flex items-center gap-2">
              <Upload size={16} />
              Upload New Comic
            </Button>
          </Link>
        </div>
      </div>
      
      <div className="mb-8 flex justify-center">
        <SearchBar onSearch={handleSearch} isSearching={isSearching} />
      </div>
      
      {/* Pending Shares Alert */}
      <PendingSharesAlert />

      {/* Loading overlay for search operations */}
      {isSearching && !showSkeleton && (
        <div className="fixed inset-0 bg-background/50 backdrop-blur-sm z-50 flex items-center justify-center pointer-events-none">
          <div className="bg-card p-6 rounded-lg shadow-lg flex items-center space-x-4 border">
            <svg className="animate-spin h-8 w-8 text-primary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span className="text-lg font-medium">Searching comics...</span>
          </div>
        </div>
      )}
      
      {showSkeleton ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
          {[...Array(6)].map((_, i) => (
            <div key={i} className="comic-card animate-pulse">
              <div className="pt-[140%] bg-muted"></div>
              <div className="p-4">
                <div className="h-4 bg-muted rounded mb-2"></div>
                <div className="h-3 bg-muted rounded w-2/3"></div>
              </div>
            </div>
          ))}
        </div>
      ) : error ? (
        <div className="text-center py-12">
          <p className="text-xl text-destructive mb-4">{error}</p>
          <Button onClick={loadComics}>Try Again</Button>
        </div>
      ) : comics.length === 0 ? (
        <div className="text-center py-12">
          <p className="text-xl text-muted-foreground mb-4">
            {isSearchActive ? "No comics found matching your search" : "No comics in your library yet."}
          </p>
          {isSearchActive && (
            <Button onClick={() => handleSearch({ query: "", tags: [] })}>
              Clear Search
            </Button>
          )}
           {!isSearchActive && (
             <Link to="/upload">
              <Button>Upload Your First Comic</Button>
            </Link>
           )}
        </div>
      ) : viewMode === "table" ? (
        <ComicTableView
          comics={comics}
          onEditComic={handleEditComic}
          onBulkAddTag={addTagToSelectedComics}
          onBulkDelete={deleteSelectedComics}
        />
      ) : (
        <Tabs defaultValue="all" className="space-y-6">
          <TabsList>
            {comicTabs.map(({ value, label, items, alwaysShown }) => (
              (alwaysShown || items.length > 0) && (
                <TabsTrigger key={value} value={value}>
                  {label} ({items.length})
                </TabsTrigger>
              )
            ))}
          </TabsList>

          {comicTabs.map(({ value, items }) => (
            <TabsContent key={value} value={value}>
              {value === "dropbox" && (
                <div className="mb-4 p-4 bg-muted rounded-lg">
                  <p className="text-sm text-muted-foreground">
                    Comics synced from your Dropbox account.
                    <Link to="/dropbox-sync" className="text-primary hover:underline ml-1">
                      Manage Dropbox sync →
                    </Link>
                  </p>
                </div>
              )}
              <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                {items.map((comic, index) => (
                  <ComicCard
                    key={comic.id}
                    comic={comic}
                    coverPriority={index < EAGER_COVER_COUNT}
                    onResetProgress={resetReadingProgress}
                    onEditComic={handleEditComic}
                    onDeleteComic={deleteComic}
                    onShareClick={handleOpenShareModal}
                  />
                ))}
              </div>
            </TabsContent>
          ))}
        </Tabs>
      )}
      
      {/* Comic Edit Dialog */}
      {editingComic && (
        <ComicEditDialog
          comic={editingComic}
          isOpen={isEditDialogOpen}
          onClose={() => {
            setIsEditDialogOpen(false);
            setEditingComic(null);
          }}
          onSave={handleSaveComic}
        />
      )}

      {/* Share Comic Modal */}
      <ShareComicModal
        isOpen={isShareModalOpen}
        onClose={handleCloseShareModal}
        comicId={shareModalComicId}
        comicTitle={shareModalComicTitle}
        // apiBaseUrl can be passed if needed, otherwise modal uses its default
      />
    </div>
  );
}
