
import { useMemo, useRef, useState, useEffect } from "react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { TagBadge } from "@/components/TagBadge";
import { Checkbox } from "@/components/ui/checkbox";
import { Search, X, Tag as TagIcon } from "lucide-react";
import { fuzzyFilter } from "@/lib/fuzzy-search";
import { isTypingTarget } from "@/lib/keyboard";
import { PAGE_LAYER_CLASSES } from "@/lib/overlay-layers";
import { MAX_TAG_FETCH_RETRIES, useTagOptions } from "@/hooks/use-tag-options";

export function SearchBar({ onSearch, isSearching = false }) {
  const [searchQuery, setSearchQuery] = useState("");
  const [isSearchFocused, setIsSearchFocused] = useState(false);
  const searchInputRef = useRef(null);
  const [selectedTags, setSelectedTags] = useState([]);
  const [showTagDropdown, setShowTagDropdown] = useState(false);
  const [tagQuery, setTagQuery] = useState("");
  const {
    availableTags,
    isLoadingTags,
    tagFetchError,
    retryCount,
    retryTagFetch,
  } = useTagOptions();
  const filteredTags = useMemo(() => {
    return fuzzyFilter(availableTags, tagQuery, ["name"])
      .sort((a, b) => {
        const aSelected = selectedTags.some((tag) => tag.id === a.id);
        const bSelected = selectedTags.some((tag) => tag.id === b.id);
        return Number(bSelected) - Number(aSelected) || a.name.localeCompare(b.name);
      });
  }, [availableTags, selectedTags, tagQuery]);

  // "/" jumps to the search box, Ctrl/Cmd+K too.
  useEffect(() => {
    const handleShortcut = (event) => {
      if (typeof event.key !== "string") return;

      const isSlash = event.key === "/" && !event.ctrlKey && !event.metaKey && !event.altKey;
      const isCommandK = event.key.toLowerCase() === "k" && (event.ctrlKey || event.metaKey);
      if (!isSlash && !isCommandK) return;

      // A dialog traps focus deliberately; pulling it out to a box behind the
      // dialog would be worse than ignoring the shortcut.
      if (event.target?.closest?.('[role="dialog"]')) return;

      // "/" is a character someone may be trying to type. The explicit chord is
      // never ambiguous, so it keeps working from inside a field.
      if (isSlash && isTypingTarget(event.target)) return;

      event.preventDefault();
      searchInputRef.current?.focus();
      searchInputRef.current?.select();
    };

    window.addEventListener("keydown", handleShortcut);
    return () => window.removeEventListener("keydown", handleShortcut);
  }, []);


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
    <div className={`w-full max-w-3xl ${showTagDropdown ? `relative ${PAGE_LAYER_CLASSES.activeControl}` : ""}`}>
      <form onSubmit={handleSearch} className="flex items-center gap-2">
        <div className="relative flex-1">
          <Search className="absolute left-2.5 top-2.5 h-5 w-5 text-muted-foreground" />
          <Input
            ref={searchInputRef}
            type="search"
            placeholder="Search comics by title, author..."
            // Chrome and Edge draw their own clear cross inside a search field
            // on hover and focus, which lands next to the button below and
            // reads as two crosses. Ours is the one to keep: the native cross
            // only empties the text, leaving the selected tags filtering.
            className="pl-10 pr-10 [&::-webkit-search-cancel-button]:hidden"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            onFocus={() => setIsSearchFocused(true)}
            onBlur={() => setIsSearchFocused(false)}
            aria-keyshortcuts="/"
          />
          {/* Only shown when the clear button is not, so the two never collide */}
          {!isSearchFocused && !searchQuery && selectedTags.length === 0 && (
            <kbd className="pointer-events-none absolute right-3 top-1/2 hidden -translate-y-1/2 rounded border bg-muted px-1.5 py-0.5 font-mono text-xs text-muted-foreground sm:inline-block">
              /
            </kbd>
          )}
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
        
        {/* Only raised while its panel is open. A permanently stacked button
            here is what used to float this control over the Edit and Share
            dialogs; see lib/overlay-layers.js for the full scale. */}
        <div className={showTagDropdown ? `relative ${PAGE_LAYER_CLASSES.activeControl}` : "relative"}>
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
            <div className={`absolute right-0 ${PAGE_LAYER_CLASSES.activeControlPanel} mt-2 w-[min(24rem,calc(100vw-2rem))] overflow-hidden rounded-lg border bg-card shadow-xl`}>
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
                    {retryCount < MAX_TAG_FETCH_RETRIES && (
                      <button 
                        className="text-sm text-primary hover:text-primary-focus mt-1"
                        onClick={retryTagFetch}
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
