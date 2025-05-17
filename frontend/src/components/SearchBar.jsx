
import { useState } from "react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Search, X, Tag as TagIcon } from "lucide-react";

export function SearchBar({ onSearch }) {
  const [searchQuery, setSearchQuery] = useState("");
  const [selectedTags, setSelectedTags] = useState([]);
  const [availableTags, setAvailableTags] = useState([
    "DC", "Marvel", "Sci-Fi", "Fantasy", "Horror", "Adventure", "Superhero", "Manga", "Comedy"
  ]);
  const [showTagDropdown, setShowTagDropdown] = useState(false);
  
  const handleSearch = (e) => {
    e.preventDefault();
    onSearch({
      query: searchQuery,
      tags: selectedTags
    });
  };
  
  const toggleTag = (tag) => {
    if (selectedTags.includes(tag)) {
      setSelectedTags(selectedTags.filter(t => t !== tag));
    } else {
      setSelectedTags([...selectedTags, tag]);
    }
  };
  
  const clearSearch = () => {
    setSearchQuery("");
    setSelectedTags([]);
    onSearch({ query: "", tags: [] });
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
                <div className="flex flex-wrap gap-2">
                  {availableTags.map((tag) => (
                    <Badge 
                      key={tag} 
                      variant={selectedTags.includes(tag) ? "default" : "outline"}
                      className="cursor-pointer"
                      onClick={() => toggleTag(tag)}
                    >
                      {tag}
                    </Badge>
                  ))}
                </div>
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
            <Badge key={tag} className="flex items-center gap-1">
              <TagIcon className="h-3 w-3" />
              {tag}
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
