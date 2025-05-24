
import { useState, useEffect } from "react";
import { ComicCard } from "@/components/ComicCard.jsx";
// import { mockComics } from "@/lib/mockData.js";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs.jsx";
import { SearchBar } from "@/components/SearchBar.jsx";
import { Button } from "@/components/ui/button";
import { Upload } from "lucide-react"; // Plus removed as it's not used
import { Link } from "react-router-dom";
import { useToast } from "@/hooks/use-toast.js";

export default function Dashboard() {
  const [comics, setComics] = useState([]);
  // searchResults will now always mirror comics state, simplifying logic.
  // const [searchResults, setSearchResults] = useState([]); 
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null); // Added error state
  const [searchParams, setSearchParams] = useState({ query: "", tags: [] });
  const [isSearchActive, setIsSearchActive] = useState(false);
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
    setIsLoading(true);
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

  const resetReadingProgress = (comicId) => {
    console.log("Resetting progress for comicId (local state change only):", comicId);
    const updatedComics = comics.map(c => 
      c.id === comicId ? { ...c, lastReadPage: undefined, readingProgress: null } : c
    );
    setComics(updatedComics);
    // Since searchResults now mirrors comics, no separate update needed if it were still used.
    // toast({ title: "Progress Reset (Locally)", description: "Reading progress has been reset in the app. Backend update needed for persistence."});
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
        <SearchBar onSearch={handleSearch} />
      </div>

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
                />
              ))}
            </div>
          </TabsContent>
        </Tabs>
      )}
    </div>
  );
}
