
import { useState, useEffect } from "react";
import { ComicCard } from "@/components/ComicCard.jsx";
// import { mockComics } from "@/lib/mockData.js";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs.jsx";
import { SearchBar } from "@/components/SearchBar.jsx";
import { Button } from "@/components/ui/button";
import { Upload } from "lucide-react"; // Plus removed as it's not used
import { Link } from "react-router-dom";
import { useToast } from "@/hooks/use-toast.js";
import { ComicEditDialog } from "@/components/ComicEditDialog.jsx";

export default function Dashboard() {
  const [comics, setComics] = useState([]);
  // searchResults will now always mirror comics state, simplifying logic.
  // const [searchResults, setSearchResults] = useState([]); 
  const [isLoading, setIsLoading] = useState(true);
  const [isSearching, setIsSearching] = useState(false); // Specific state for search operations
  const [error, setError] = useState(null); // Added error state
  const [searchParams, setSearchParams] = useState({ query: "", tags: [] });
  const [isSearchActive, setIsSearchActive] = useState(false);
  const [editingComic, setEditingComic] = useState(null);
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const { toast } = useToast();

  const processComicsResponse = (data) => {
    const processedComics = data.comics.map(comic => ({
      ...comic,
      tags: comic.tags ? comic.tags.map(tag => tag.name) : [],
      lastReadPage: comic.readingProgress ? comic.readingProgress.currentPage : undefined,
    }));
    setComics(processedComics);
    // setSearchResults(processedComics); // comics state is now the single source of truth for display
    setError(null);
  };

  const fetchComicsFromApi = async (url) => {
    // If this is a search operation, use the isSearching state instead of full isLoading
    if (url.includes('search=') || url.includes('tags=')) {
      setIsSearching(true);
    } else {
      setIsLoading(true);
    }
    setError(null);
    try {
      const response = await fetch(url);
      if (!response.ok) {
        const errorData = await response.json().catch(() => ({ message: "Failed to load comics." }));
        console.error("Failed to load comics:", errorData.message);
        
        // Handle rate limiting specifically
        if (response.status === 429) { // 429 Too Many Requests
          const retryAfter = errorData.retryAfter || 60;
          toast({ 
            title: "Rate limit exceeded", 
            description: `Please wait ${retryAfter} seconds before trying again.`, 
            variant: "warning",
            duration: 5000 // Show for 5 seconds
          });
          setError(`Search rate limit exceeded. Please wait ${retryAfter} seconds before trying again.`);
        } else {
          toast({ title: "Error", description: errorData.message || "Could not load comics.", variant: "destructive" });
          setError(errorData.message || "Could not load comics.");
        }
        
        setComics([]); // Clear comics on error
        return;
      }
      const data = await response.json();
      processComicsResponse(data);
    } catch (err) {
      console.error("Failed to load comics:", err);
      toast({ title: "Error", description: "Could not connect to server or other error.", variant: "destructive" });
      setError("Could not connect to server or other error.");
      setComics([]);
    } finally {
      setIsLoading(false);
      setIsSearching(false);
    }
  };

  const loadComics = async () => {
    setIsSearchActive(false); // Reset search active state
    await fetchComicsFromApi('/api/comics');
  };

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
  }, [toast]); // loadComics itself doesn't change, but toast is a dependency of its internals indirectly

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
    
    setSearchParams(sanitizedParams); // Keep track of current search parameters
    
    if (!sanitizedParams.query && (!sanitizedParams.tags || sanitizedParams.tags.length === 0)) {
      loadComics(); // Fetch all comics if search is cleared
    } else {
      fetchFilteredComics(sanitizedParams.query, sanitizedParams.tags);
    }
  };

  const resetReadingProgress = async (comicId) => {
    try {
      const response = await fetch(`/api/comics/${comicId}/reading-progress/reset`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        }
      });
      
      if (!response.ok) {
        const errorData = await response.json().catch(() => ({ message: "Failed to reset reading progress." }));
        throw new Error(errorData.message || "Failed to reset reading progress.");
      }
      
      // Update local state
      const updatedComics = comics.map(c => 
        c.id === comicId ? { ...c, lastReadPage: undefined, readingProgress: null } : c
      );
      setComics(updatedComics);
      
      return true;
    } catch (error) {
      console.error("Error resetting reading progress:", error);
      throw error;
    }
  };
  
  const handleEditComic = (comic) => {
    setEditingComic(comic);
    setIsEditDialogOpen(true);
  };
  
  const handleSaveComic = async (updatedComic) => {
    try {
      const response = await fetch(`/api/comics/${updatedComic.id}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          title: updatedComic.title,
          author: updatedComic.author,
          publisher: updatedComic.publisher,
          description: updatedComic.description,
          tags: updatedComic.tags
        })
      });
      
      if (!response.ok) {
        const errorData = await response.json().catch(() => ({ message: "Failed to update comic." }));
        throw new Error(errorData.message || "Failed to update comic.");
      }
      
      // Update local state
      const updatedComics = comics.map(c => 
        c.id === updatedComic.id ? { ...c, ...updatedComic } : c
      );
      setComics(updatedComics);
      
      return true;
    } catch (error) {
      console.error("Error updating comic:", error);
      throw error;
    }
  };
  
  const deleteComic = async (comicId) => {
    try {
      const response = await fetch(`/api/comics/${comicId}`, {
        method: 'DELETE'
      });
      
      if (!response.ok) {
        const errorData = await response.json().catch(() => ({ message: "Failed to delete comic." }));
        throw new Error(errorData.message || "Failed to delete comic.");
      }
      
      // Update local state
      const updatedComics = comics.filter(c => c.id !== comicId);
      setComics(updatedComics);
      
      return true;
    } catch (error) {
      console.error("Error deleting comic:", error);
      throw error;
    }
  };

  // Filters now operate on the 'comics' state directly
  const inProgressComics = comics.filter(comic => comic.lastReadPage !== undefined && comic.lastReadPage > 0);
  const unreadComics = comics.filter(comic => comic.lastReadPage === undefined || comic.lastReadPage === 0);

  return (
    <div className="container mx-auto px-4 py-8">
      <div className="flex flex-col md:flex-row justify-between items-center mb-8 gap-4">
        <h1 className="text-3xl font-comic">My Comic Library</h1>
        <Link to="/upload">
          <Button className="flex items-center gap-2">
            <Upload size={16} />
            Upload New Comic
          </Button>
        </Link>
      </div>
      
      <div className="mb-8 flex justify-center">
        <SearchBar onSearch={handleSearch} isSearching={isSearching} />
      </div>

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
      ) : (
        <Tabs defaultValue="all" className="space-y-6">
          <TabsList>
            <TabsTrigger value="all">All Comics ({comics.length})</TabsTrigger>
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
    </div>
  );
}
