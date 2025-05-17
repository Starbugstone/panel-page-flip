import { useState, useEffect } from "react";
import { ComicCard } from "@/components/ComicCard.jsx";
import { mockComics } from "@/lib/mockData.js";
import { Input } from "@/components/ui/input.jsx";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs.jsx";

export default function Dashboard() {
  const [comics, setComics] = useState([]);
  const [searchTerm, setSearchTerm] = useState("");
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    const loadComics = async () => {
      try {
        await new Promise(resolve => setTimeout(resolve, 800));
        setComics(mockComics);
      } catch (error) {
        console.error("Failed to load comics:", error);
      } finally {
        setIsLoading(false);
      }
    };

    loadComics();
  }, []);

  const inProgressComics = comics.filter(comic => comic.lastReadPage !== undefined);
  const unreadComics = comics.filter(comic => comic.lastReadPage === undefined);

  const filterComics = (comicsList) => {
    if (!searchTerm) return comicsList;
    
    return comicsList.filter(comic => 
      comic.title.toLowerCase().includes(searchTerm.toLowerCase()) ||
      comic.description.toLowerCase().includes(searchTerm.toLowerCase()) ||
      (comic.author && comic.author.toLowerCase().includes(searchTerm.toLowerCase())) ||
      (comic.publisher && comic.publisher.toLowerCase().includes(searchTerm.toLowerCase()))
    );
  };

  const filteredInProgress = filterComics(inProgressComics);
  const filteredUnread = filterComics(unreadComics);
  const allFiltered = filterComics(comics);

  return (
    <div className="container mx-auto px-4 py-8">
      <h1 className="text-3xl font-comic mb-6">My Comic Library</h1>
      
      <div className="mb-6">
        <Input
          type="text"
          placeholder="Search comics..."
          value={searchTerm}
          onChange={(e) => setSearchTerm(e.target.value)}
          className="max-w-md"
        />
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
            {allFiltered.length > 0 ? (
              <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                {allFiltered.map((comic) => (
                  <ComicCard key={comic.id} comic={comic} />
                ))}
              </div>
            ) : (
              <p className="text-muted-foreground">No comics found matching your search.</p>
            )}
          </TabsContent>

          <TabsContent value="reading">
            {filteredInProgress.length > 0 ? (
              <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                {filteredInProgress.map((comic) => (
                  <ComicCard key={comic.id} comic={comic} />
                ))}
              </div>
            ) : (
              <p className="text-muted-foreground">No comics in progress matching your search.</p>
            )}
          </TabsContent>

          <TabsContent value="unread">
            {filteredUnread.length > 0 ? (
              <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                {filteredUnread.map((comic) => (
                  <ComicCard key={comic.id} comic={comic} />
                ))}
              </div>
            ) : (
              <p className="text-muted-foreground">No unread comics matching your search.</p>
            )}
          </TabsContent>
        </Tabs>
      )}
    </div>
  );
}
