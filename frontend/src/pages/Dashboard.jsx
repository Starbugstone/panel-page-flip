
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
  const [searchResults, setSearchResults] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [searchParams, setSearchParams] = useState({ query: "", tags: [] });
  const [isSearchActive, setIsSearchActive] = useState(false);
  const { toast } = useToast();

  useEffect(() => {
    const loadComics = async () => {
      setIsLoading(true);
      try {
        const response = await fetch('/api/comics'); // GET request by default
        if (!response.ok) {
          const errorData = await response.json().catch(() => ({ message: "Failed to load comics." }));
          console.error("Failed to load comics:", errorData.message);
          toast({ title: "Error", description: errorData.message || "Could not load comics.", variant: "destructive" });
          setComics([]);
          setSearchResults([]);
          return; // Removed finally here, it's outside
        }
        const data = await response.json();
        
        const processedComics = data.comics.map(comic => ({
          ...comic,
          // Process tags to be just an array of tag names for easier filtering
          tags: comic.tags ? comic.tags.map(tag => tag.name) : [],
          // Map reading progress data to what ComicCard expects
          lastReadPage: comic.readingProgress ? comic.readingProgress.currentPage : undefined,
        }));

        setComics(processedComics);
        setSearchResults(processedComics);
      } catch (error) {
        console.error("Failed to load comics:", error);
        toast({ title: "Error", description: "Could not connect to server or other error.", variant: "destructive" });
        setComics([]);
        setSearchResults([]);
      } finally {
        setIsLoading(false);
      }
    };

    loadComics();
  }, [toast]); // Added toast to dependency array as it's used in the effect

  // Removed generateRandomTags function

  const handleSearch = (params) => {
    setSearchParams(params);
    
    // If there's no search query and no tags selected, show all comics
    if (!params.query && params.tags.length === 0) {
      setSearchResults(comics);
      setIsSearchActive(false);
      return;
    }
    
    setIsSearchActive(true);
    
    // Filter comics based on search params
    const filtered = comics.filter(comic => {
      // Check if comic matches search query
      const matchesQuery = !params.query || 
        comic.title.toLowerCase().includes(params.query.toLowerCase()) ||
        (comic.description && comic.description.toLowerCase().includes(params.query.toLowerCase())) ||
        (comic.author && comic.author.toLowerCase().includes(params.query.toLowerCase())) ||
        (comic.publisher && comic.publisher.toLowerCase().includes(params.query.toLowerCase()));
      
      // Check if comic has any of the selected tags
      const matchesTags = params.tags.length === 0 || 
        params.tags.some(tag => comic.tags.includes(tag));
      
      return matchesQuery && matchesTags;
    });
    
    setSearchResults(filtered);
  };

  const resetReadingProgress = (comicId) => {
    console.log("Resetting progress for comicId (local state change only):", comicId);
    // This function will need to be updated to interact with the backend for persistence.
    // For now, it updates the local state.
    const updatedComics = comics.map(c => 
      c.id === comicId ? { ...c, lastReadPage: undefined, readingProgress: null } : c
    );
    setComics(updatedComics);

    const updatedSearchResults = searchResults.map(c =>
      c.id === comicId ? { ...c, lastReadPage: undefined, readingProgress: null } : c
    );
    setSearchResults(updatedSearchResults);
    
    // Potentially show a toast that this is a temporary client-side reset
    // toast({ title: "Progress Reset (Locally)", description: "Reading progress has been reset in the app. Backend update needed for persistence."});
  };

  // Ensure these filters use the potentially updated `lastReadPage` field correctly
  const inProgressComics = searchResults.filter(comic => comic.lastReadPage !== undefined && comic.lastReadPage > 0);
  const unreadComics = searchResults.filter(comic => comic.lastReadPage === undefined || comic.lastReadPage === 0);

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
      ) : searchResults.length === 0 ? (
        <div className="text-center py-12">
          <p className="text-xl text-muted-foreground mb-4">No comics found matching your search</p>
          {isSearchActive && (
            <Button onClick={() => handleSearch({ query: "", tags: [] })}>
              Clear Search
            </Button>
          )}
        </div>
      ) : (
        <Tabs defaultValue="all" className="space-y-6">
          <TabsList>
            <TabsTrigger value="all">All Comics ({searchResults.length})</TabsTrigger>
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
              {searchResults.map((comic) => (
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
