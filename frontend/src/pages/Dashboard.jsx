
import { useState, useEffect } from "react";
import { ComicCard } from "@/components/ComicCard.jsx";
import { mockComics } from "@/lib/mockData.js";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs.jsx";
import { SearchBar } from "@/components/SearchBar.jsx";
import { Button } from "@/components/ui/button";
import { Upload, Plus } from "lucide-react";
import { Link } from "react-router-dom";

export default function Dashboard() {
  const [comics, setComics] = useState([]);
  const [searchResults, setSearchResults] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [searchParams, setSearchParams] = useState({ query: "", tags: [] });
  const [isSearchActive, setIsSearchActive] = useState(false);

  useEffect(() => {
    const loadComics = async () => {
      try {
        await new Promise(resolve => setTimeout(resolve, 800));
        
        // Let's enhance the mock data with tags
        const enhancedMockComics = mockComics.map(comic => ({
          ...comic,
          tags: generateRandomTags()
        }));
        
        setComics(enhancedMockComics);
        setSearchResults(enhancedMockComics);
      } catch (error) {
        console.error("Failed to load comics:", error);
      } finally {
        setIsLoading(false);
      }
    };

    loadComics();
  }, []);

  // Generate some random tags for demo purposes
  const generateRandomTags = () => {
    const allTags = ["DC", "Marvel", "Sci-Fi", "Fantasy", "Horror", "Adventure", "Superhero", "Manga", "Comedy"];
    const numTags = Math.floor(Math.random() * 4) + 1; // 1 to 4 tags
    const selectedTags = [];
    
    for (let i = 0; i < numTags; i++) {
      const randomIndex = Math.floor(Math.random() * allTags.length);
      const tag = allTags[randomIndex];
      if (!selectedTags.includes(tag)) {
        selectedTags.push(tag);
      }
    }
    
    return selectedTags;
  };

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
    // Update the comic's lastReadPage to undefined
    setComics(comics.map(comic => 
      comic.id === comicId ? { ...comic, lastReadPage: undefined } : comic
    ));
    
    // Also update search results if active
    if (isSearchActive) {
      setSearchResults(searchResults.map(comic => 
        comic.id === comicId ? { ...comic, lastReadPage: undefined } : comic
      ));
    }
  };

  const inProgressComics = searchResults.filter(comic => comic.lastReadPage !== undefined);
  const unreadComics = searchResults.filter(comic => comic.lastReadPage === undefined);

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
