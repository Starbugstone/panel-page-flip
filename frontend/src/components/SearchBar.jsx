
import { useMemo, useState, useEffect } from "react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { TagBadge } from "@/components/TagBadge";
import { Checkbox } from "@/components/ui/checkbox";
import { Search, X, Tag as TagIcon } from "lucide-react";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { fuzzyFilter } from "@/lib/fuzzy-search";

export function SearchBar({ onSearch, isSearching = false }) {
  const [searchQuery, setSearchQuery] = useState("");
  const [selectedTags, setSelectedTags] = useState([]);
  const [availableTags, setAvailableTags] = useState([]);
  const [isLoadingTags, setIsLoadingTags] = useState(true);
  const [tagFetchError, setTagFetchError] = useState(null);
  const [showTagDropdown, setShowTagDropdown] = useState(false);
  const [tagQuery, setTagQuery] = useState("");
  const [retryCount, setRetryCount] = useState(0);
  const MAX_RETRIES = 3;
  const filteredTags = useMemo(() => {
    return fuzzyFilter(availableTags, tagQuery, ["name"])
      .sort((a, b) => {
        const aSelected = selectedTags.some((tag) => tag.id === a.id);
        const bSelected = selectedTags.some((tag) => tag.id === b.id);
        return Number(bSelected) - Number(aSelected) || a.name.localeCompare(b.name);
      });
  }, [availableTags, selectedTags, tagQuery]);

  useEffect(() => {
    const fetchTags = async () => {
      setIsLoadingTags(true);
      setTagFetchError(null);
      try {
        const data = await api.get("/api/tags");
        setAvailableTags(data.tags || []); // Assuming the API returns { tags: [...] }
        setRetryCount(0); // Reset retry count on success
      } catch (error) {
        logger.error("Error fetching tags:", error);
        setTagFetchError(error.message);
        
        // Implement retry logic for network errors
        if (retryCount < MAX_RETRIES && (error.message.includes('network') || error.message.includes('Server error'))) {
          setRetryCount(prev => prev + 1);
          const retryDelay = Math.pow(2, retryCount) * 1000; // Exponential backoff
          setTimeout(() => {
            logger.log(`Retrying tag fetch (${retryCount + 1}/${MAX_RETRIES})...`);
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
    <div className={`w-full max-w-3xl ${showTagDropdown ? "relative z-[70]" : ""}`}>
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
        
        <div className="relative z-[70]">
          <Button 
            type="button" 
            variant="outline" 
            className="flex items-center gap-2"
            onClick={() => setShowTagDropdown(!showTagDropdown)}
            disabled={isLoadingTags}
          >
            {isLoadingTags ? (
              <>
                <svg className="animate-spin h-4 w-4 mr-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Tags
              </>
            ) : (
              <>
                <TagIcon className="h-4 w-4" />
                Tags
              </>
            )}
          </Button>
          
          {showTagDropdown && (
            <div className="absolute right-0 z-[80] mt-2 w-[min(24rem,calc(100vw-2rem))] overflow-hidden rounded-lg border bg-card shadow-xl">
              <div className="border-b p-3">
                <div className="mb-2 flex items-center justify-between gap-3">
                  <p className="text-sm font-medium">Filter by tags</p>
                  <span className="text-xs text-muted-foreground">{selectedTags.length} selected</span>
                </div>
                <div className="relative">
                  <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                  <Input
                    value={tagQuery}
                    onChange={(event) => setTagQuery(event.target.value)}
                    placeholder="Search your tags…"
                    className="h-9 pl-8"
                    autoFocus
                  />
                </div>
              </div>
              <div className="max-h-72 overflow-y-auto p-2">
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
                  <div className="space-y-1">
                    {filteredTags.map((tag) => {
                      const selected = selectedTags.some((item) => item.id === tag.id);
                      return (
                        <label
                          key={tag.id}
                          className="flex w-full cursor-pointer items-center gap-3 rounded-md px-2 py-2 text-left hover:bg-accent"
                        >
                          <Checkbox
                            checked={selected}
                            onCheckedChange={() => toggleTag(tag)}
                          />
                          <TagBadge tag={tag} />
                          {tag.isGlobal && <span className="ml-auto text-xs text-muted-foreground">Global</span>}
                        </label>
                      );
                    })}
                    {filteredTags.length === 0 && (
                      <p className="px-2 py-6 text-center text-sm text-muted-foreground">No tags match “{tagQuery}”.</p>
                    )}
                  </div>
                )}
              </div>
              <div className="border-t p-2 flex justify-between">
                <Button 
                  type="button" 
                  variant="ghost" 
                  size="sm" 
                  onClick={() => setSelectedTags([])}
                  disabled={selectedTags.length === 0}
                >
                  Clear
                </Button>
                <Button 
                  type="button" 
                  size="sm" 
                  onClick={() => {
                    setShowTagDropdown(false);
                    setTagQuery("");
                    // Trigger search when applying tag selection
                    onSearch({
                      query: searchQuery,
                      tags: selectedTags.map(tag => tag.name)
                    });
                  }}
                >
                  Apply
                </Button>
              </div>
            </div>
          )}
        </div>
        
        <Button type="submit" disabled={isSearching}>
          {isSearching ? (
            <>
              <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              Searching...
            </>
          ) : (
            "Search"
          )}
        </Button>
      </form>
      
      {selectedTags.length > 0 && (
        <div className="mt-2 flex max-w-full gap-2 overflow-x-auto pb-1">
          {selectedTags.map((tag) => (
            <TagBadge key={tag.id} tag={tag} className="flex shrink-0 items-center gap-1">
              <TagIcon className="h-3 w-3" />
              {tag.name}
              <button 
                type="button"
                onClick={() => toggleTag(tag)} 
                className="ml-1 hover:text-destructive"
              >
                <X className="h-3 w-3" />
              </button>
            </TagBadge>
          ))}
        </div>
      )}
    </div>
  );
}
