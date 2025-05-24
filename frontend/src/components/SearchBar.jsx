
import { useState, useEffect } from "react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Search, X, Tag as TagIcon } from "lucide-react";

export function SearchBar({ onSearch }) {
  const [searchQuery, setSearchQuery] = useState("");
  const [selectedTags, setSelectedTags] = useState([]);
  const [availableTags, setAvailableTags] = useState([]);
  const [isLoadingTags, setIsLoadingTags] = useState(true);
  const [tagFetchError, setTagFetchError] = useState(null);
  const [showTagDropdown, setShowTagDropdown] = useState(false);
  const [retryCount, setRetryCount] = useState(0);
  const MAX_RETRIES = 3;

  useEffect(() => {
    const fetchTags = async () => {
      setIsLoadingTags(true);
      setTagFetchError(null);
      try {
        const response = await fetch("/api/tags");
        if (!response.ok) {
          const errorText = response.statusText;
          const status = response.status;
          let errorMessage;
          
          switch (status) {
            case 401:
              errorMessage = "You need to be logged in to view tags";
              break;
            case 403:
              errorMessage = "You don't have permission to view these tags";
              break;
            case 404:
              errorMessage = "Tag resource not found";
              break;
            case 500:
              errorMessage = "Server error while fetching tags";
              break;
            default:
              errorMessage = `Failed to fetch tags: ${errorText}`;
          }
          
          throw new Error(errorMessage);
        }
        const data = await response.json();
        setAvailableTags(data.tags || []); // Assuming the API returns { tags: [...] }
        setRetryCount(0); // Reset retry count on success
      } catch (error) {
        console.error("Error fetching tags:", error);
        setTagFetchError(error.message);
        
        // Implement retry logic for network errors
        if (retryCount < MAX_RETRIES && (error.message.includes('network') || error.message.includes('Server error'))) {
          setRetryCount(prev => prev + 1);
          const retryDelay = Math.pow(2, retryCount) * 1000; // Exponential backoff
          setTimeout(() => {
            console.log(`Retrying tag fetch (${retryCount + 1}/${MAX_RETRIES})...`);
            // This will trigger the useEffect again
            setTagFetchError(null);
          }, retryDelay);
        }
      } finally {
        setIsLoadingTags(false);
      }
    };

    fetchTags();
  }, [retryCount]);
  
  const handleSearch = (e) => {
    e.preventDefault();
    onSearch({
      query: searchQuery,
      tags: selectedTags.map(tag => tag.name) // Send tag names
    });
  };
  
  const toggleTag = (tag) => {
    // Check if tag object is already selected by comparing IDs
    if (selectedTags.find(t => t.id === tag.id)) {
      setSelectedTags(selectedTags.filter(t => t.id !== tag.id));
    } else {
      setSelectedTags([...selectedTags, tag]);
    }
  };
  
  const clearSearch = () => {
    setSearchQuery("");
    setSelectedTags([]);
    onSearch({ query: "", tags: [] }); // Send empty array for tags
  };
  
  return (
    <div className="w-full max-w-3xl">
      <form onSubmit={handleSearch} className="flex items-center gap-2">
        <div className="relative flex-1">
          <Search className="absolute left-2.5 top-2.5 h-5 w-5 text-muted-foreground" />
          <Input 
            type="search"
            placeholder="Search comics by title, author..."
            className="pl-10 pr-10"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
          />
          {(searchQuery || selectedTags.length > 0) && (
            <button 
              type="button" 
              className="absolute right-2.5 top-2.5 text-muted-foreground hover:text-foreground"
              onClick={clearSearch}
            >
              <X className="h-4 w-4" />
            </button>
          )}
        </div>
        
        <div className="relative">
          <Button 
            type="button" 
            variant="outline" 
            className="flex items-center gap-2"
            onClick={() => setShowTagDropdown(!showTagDropdown)}
          >
            <TagIcon className="h-4 w-4" />
            Tags
          </Button>
          
          {showTagDropdown && (
            <div className="absolute right-0 z-10 mt-2 w-64 rounded-md border bg-card shadow-lg">
              <div className="p-3 max-h-60 overflow-y-auto">
                <p className="text-sm font-medium mb-2">Filter by tags:</p>
                {isLoadingTags && <p className="text-sm text-muted-foreground">Loading tags...</p>}
                {tagFetchError && (
                  <div className="text-sm text-destructive">
                    <p>Error: {tagFetchError}</p>
                    {retryCount < MAX_RETRIES && (
                      <button 
                        className="text-sm text-primary hover:text-primary-focus mt-1"
                        onClick={() => setRetryCount(prev => prev + 1)}
                      >
                        Retry
                      </button>
                    )}
                  </div>
                )}
                {!isLoadingTags && !tagFetchError && (
                  <div className="flex flex-wrap gap-2">
                    {availableTags.map((tag) => (
                      <Badge 
                        key={tag.id} 
                        variant={selectedTags.find(t => t.id === tag.id) ? "default" : "outline"}
                        className="cursor-pointer"
                        onClick={() => toggleTag(tag)}
                      >
                        {tag.name}
                      </Badge>
                    ))}
                  </div>
                )}
              </div>
              <div className="border-t p-2 flex justify-between">
                <Button 
                  type="button" 
                  variant="ghost" 
                  size="sm" 
                  onClick={() => setSelectedTags([])}
                >
                  Clear
                </Button>
                <Button 
                  type="button" 
                  size="sm" 
                  onClick={() => setShowTagDropdown(false)}
                >
                  Apply
                </Button>
              </div>
            </div>
          )}
        </div>
        
        <Button type="submit">
          Search
        </Button>
      </form>
      
      {selectedTags.length > 0 && (
        <div className="flex flex-wrap gap-2 mt-2">
          {selectedTags.map((tag) => (
            <Badge key={tag.id} className="flex items-center gap-1">
              <TagIcon className="h-3 w-3" />
              {tag.name}
              <button 
                type="button"
                onClick={() => toggleTag(tag)} 
                className="ml-1 hover:text-destructive"
              >
                <X className="h-3 w-3" />
              </button>
            </Badge>
          ))}
        </div>
      )}
    </div>
  );
}
