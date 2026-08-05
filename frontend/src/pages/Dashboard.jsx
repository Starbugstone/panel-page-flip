
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

export default function Dashboard() {
  const [comics, setComics] = useState([]);
  // searchResults will now always mirror comics state, simplifying logic.
  // const [searchResults, setSearchResults] = useState([]); 
  const [isLoading, setIsLoading] = useState(true);
  const [isSearching, setIsSearching] = useState(false); // Specific state for search operations
  const [error, setError] = useState(null); // Added error state
  const [isSearchActive, setIsSearchActive] = useState(false);
  const [editingComic, setEditingComic] = useState(null);
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [viewMode, setViewMode] = useState("grid");
  const { toast } = useToast();
  const lastComicsUrl = useRef('/api/comics');

  // State for ShareComicModal
  const [isShareModalOpen, setIsShareModalOpen] = useState(false);
  const [shareModalComicId, setShareModalComicId] = useState(null);
  const [shareModalComicTitle, setShareModalComicTitle] = useState(null);

  const processComicsResponse = useCallback((data) => {
    const processedComics = data.comics.map(comic => ({
      ...comic,
      tagDetails: comic.tags || [],
      hiddenTagNames: (comic.tags || []).filter(tag => tag.hideFromLibrary).map(tag => tag.name),
      tags: comic.tags ? comic.tags.map(tag => tag.name) : [],
      lastReadPage: comic.readingProgress ? comic.readingProgress.currentPage : undefined,
    }));
    setComics(processedComics);
    // setSearchResults(processedComics); // comics state is now the single source of truth for display
    setError(null);
  }, []);

  const fetchComicsFromApi = useCallback(async (url) => {
    lastComicsUrl.current = url;
    // If this is a search operation, use the isSearching state instead of full isLoading
    if (url.includes('search=') || url.includes('tags=')) {
      setIsSearching(true);
    } else {
      setIsLoading(true);
    }
    setError(null);
    try {
      const data = await api.get(url);
      processComicsResponse(data);
    } catch (err) {
      logger.error("Failed to load comics:", err);
      const message = err.status === 429
        ? `Search rate limit exceeded. Please wait ${err.data?.retryAfter || 60} seconds before trying again.`
        : err.message || "Could not load comics.";
      toast({ title: err.status === 429 ? "Rate limit exceeded" : "Error", description: message, variant: "destructive" });
      setError(message);
      setComics([]);
    } finally {
      setIsLoading(false);
      setIsSearching(false);
    }
  }, [processComicsResponse, toast]);

  const loadComics = useCallback(async () => {
    setIsSearchActive(false); // Reset search active state
    await fetchComicsFromApi('/api/comics');
  }, [fetchComicsFromApi]);

  const fetchFilteredComics = async (searchQuery, tagNamesArray) => {
    let url = '/api/comics';
    const queryParams = new URLSearchParams();
    if (searchQuery) {
      queryParams.append('search', searchQuery);
    }
    if (tagNamesArray && tagNamesArray.length > 0) {
      queryParams.append('tags', tagNamesArray.join(','));
    }
    
    const queryString = queryParams.toString();
    if (queryString) {
      url += `?${queryString}`;
    }
    
    setIsSearchActive(!!searchQuery || (tagNamesArray && tagNamesArray.length > 0));
    await fetchComicsFromApi(url);
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
      
      // Update local state
      const updatedComics = comics.map(c => 
        c.id === comicId ? { ...c, lastReadPage: undefined, readingProgress: null } : c
      );
      setComics(updatedComics);
      
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
      
      await fetchComicsFromApi(lastComicsUrl.current);
      
      return true;
    } catch (error) {
      logger.error("Error updating comic:", error);
      throw error;
    }
  };
  
  const deleteComic = async (comicId, { confirmOrphaned = false } = {}) => {
    try {
      await api.delete("/api/comics", { body: { comicIds: [comicId], confirmOrphaned } });
      
      // Update local state
      const updatedComics = comics.filter(c => c.id !== comicId);
      setComics(updatedComics);
      
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
      await fetchComicsFromApi(lastComicsUrl.current);
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
      setComics((currentComics) => currentComics.filter((comic) => !comicIds.includes(comic.id)));
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

  // Filters now operate on the 'comics' state directly
  const inProgressComics = comics.filter(comic => comic.lastReadPage !== undefined && comic.lastReadPage > 0);
  const unreadComics = comics.filter(comic => comic.lastReadPage === undefined || comic.lastReadPage === 0);
  const dropboxComics = comics.filter(comic => comic.tags && comic.tags.includes('Dropbox'));

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
        <h1 className="text-3xl font-comic">My Comic Library</h1>
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
      {isSearching && !isLoading && (
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
      
      {isLoading ? (
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
            <TabsTrigger value="all">All Comics ({comics.length})</TabsTrigger>
            {dropboxComics.length > 0 && (
              <TabsTrigger value="dropbox">
                Dropbox ({dropboxComics.length})
              </TabsTrigger>
            )}
            {inProgressComics.length > 0 && (
              <TabsTrigger value="reading">
                Currently Reading ({inProgressComics.length})
              </TabsTrigger>
            )}
            {unreadComics.length > 0 && (
              <TabsTrigger value="unread">
                Not Started ({unreadComics.length})
              </TabsTrigger>
            )}
          </TabsList>

          <TabsContent value="all">
            <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
              {comics.map((comic) => (
                <ComicCard 
                  key={comic.id} 
                  comic={comic} 
                  onResetProgress={resetReadingProgress}
                  onEditComic={handleEditComic}
                  onDeleteComic={deleteComic}
                  onShareClick={handleOpenShareModal} // Added onShareClick prop
                />
              ))}
            </div>
          </TabsContent>

          <TabsContent value="dropbox">
            <div className="mb-4 p-4 bg-muted rounded-lg">
              <p className="text-sm text-muted-foreground">
                Comics synced from your Dropbox account. 
                <Link to="/dropbox-sync" className="text-primary hover:underline ml-1">
                  Manage Dropbox sync →
                </Link>
              </p>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
              {dropboxComics.map((comic) => (
                <ComicCard 
                  key={comic.id} 
                  comic={comic} 
                  onResetProgress={resetReadingProgress}
                  onEditComic={handleEditComic}
                  onDeleteComic={deleteComic}
                  onShareClick={handleOpenShareModal}
                />
              ))}
            </div>
          </TabsContent>

          <TabsContent value="reading">
            <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
              {inProgressComics.map((comic) => (
                <ComicCard 
                  key={comic.id} 
                  comic={comic} 
                  onResetProgress={resetReadingProgress}
                  onEditComic={handleEditComic}
                  onDeleteComic={deleteComic}
                  onShareClick={handleOpenShareModal} // Added onShareClick prop
                />
              ))}
            </div>
          </TabsContent>

          <TabsContent value="unread">
            <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
              {unreadComics.map((comic) => (
                <ComicCard 
                  key={comic.id} 
                  comic={comic} 
                  onResetProgress={resetReadingProgress}
                  onEditComic={handleEditComic}
                  onDeleteComic={deleteComic}
                  onShareClick={handleOpenShareModal} // Added onShareClick prop
                />
              ))}
            </div>
          </TabsContent>
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
