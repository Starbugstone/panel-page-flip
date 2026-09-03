
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

function handlesSearchShortcut(event) {
  if (typeof event.key !== "string") return false;

  const isSlash = event.key === "/" && !event.ctrlKey && !event.metaKey && !event.altKey;
  const isCommandK = event.key.toLowerCase() === "k" && (event.ctrlKey || event.metaKey);
  if (!isSlash && !isCommandK) return false;

  // A dialog traps focus deliberately; pulling it out to a box behind the
  // dialog would be worse than ignoring the shortcut.
  if (event.target?.closest?.('[role="dialog"]')) return false;

  // "/" is a character someone may be trying to type. The explicit chord is
  // never ambiguous, so it keeps working from inside a field.
  return !isSlash || !isTypingTarget(event.target);
}

function useSearchShortcut(searchInputRef) {
  useEffect(() => {
    const handleShortcut = (event) => {
      if (!handlesSearchShortcut(event)) return;

      event.preventDefault();
      searchInputRef.current?.focus();
      searchInputRef.current?.select();
    };

    window.addEventListener("keydown", handleShortcut);
    return () => window.removeEventListener("keydown", handleShortcut);
  }, [searchInputRef]);
}

function Spinner({ className = "" }) {
  return (
    <svg aria-hidden="true" className={`animate-spin ${className}`} xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
    </svg>
  );
}

function SearchQueryInput({ inputRef, query, selectedCount, focused, onQueryChange, onFocusChange, onClear }) {
  const showHint = !focused && !query && selectedCount === 0;
  const showClear = Boolean(query) || selectedCount > 0;

  return (
    <div className="relative col-span-2 min-w-0 sm:col-span-1 sm:flex-1">
      <Search className="absolute left-2.5 top-2.5 h-5 w-5 text-muted-foreground" />
      <Input
        ref={inputRef}
        type="search"
        placeholder="Search comics by title, author..."
        // Chrome and Edge draw their own clear cross beside ours. The native
        // one only empties text and would leave selected tag filters active.
        className="pl-10 pr-10 [&::-webkit-search-cancel-button]:hidden"
        value={query}
        onChange={(event) => onQueryChange(event.target.value)}
        onFocus={() => onFocusChange(true)}
        onBlur={() => onFocusChange(false)}
        aria-label="Search comics"
        aria-keyshortcuts="/"
      />
      {showHint && (
        <kbd className="pointer-events-none absolute right-3 top-1/2 hidden -translate-y-1/2 rounded border bg-muted px-1.5 py-0.5 font-mono text-xs text-muted-foreground sm:inline-block">
          /
        </kbd>
      )}
      {showClear && (
        <button
          type="button"
          className="absolute right-2.5 top-2.5 text-muted-foreground hover:text-foreground"
          onClick={onClear}
          aria-label="Clear search and tag filters"
        >
          <X className="h-4 w-4" />
        </button>
      )}
    </div>
  );
}

function TagOptions({ loading, error, retryCount, onRetry, tags, selectedTags, onToggle, query }) {
  if (loading) return <p className="text-sm text-muted-foreground">Loading tags...</p>;
  if (error) {
    return (
      <div className="text-sm text-destructive">
        <p>Error: {error}</p>
        {retryCount < MAX_TAG_FETCH_RETRIES && (
          <button type="button" className="mt-1 text-sm text-primary hover:text-primary-focus" onClick={onRetry}>
            Retry
          </button>
        )}
      </div>
    );
  }

  return (
    <div className="space-y-1">
      {tags.map((tag) => {
        const selected = selectedTags.some((item) => item.id === tag.id);
        return (
          <label key={tag.id} className="flex min-w-0 w-full cursor-pointer items-center gap-3 rounded-md px-2 py-2 text-left hover:bg-accent">
            <Checkbox
              checked={selected}
              onCheckedChange={() => onToggle(tag)}
              aria-label={`Filter by ${tag.name}`}
            />
            <TagBadge tag={tag} className="min-w-0 flex-1" />
            {tag.isGlobal && <span className="ml-auto flex-none text-xs text-muted-foreground">Global</span>}
          </label>
        );
      })}
      {tags.length === 0 && (
        <p className="px-2 py-6 text-center text-sm text-muted-foreground">No tags match “{query}”.</p>
      )}
    </div>
  );
}

function TagFilterPanel({
  query, onQueryChange, selectedTags, onClear, onApply, loading, error, retryCount, onRetry, tags, onToggle,
}) {
  return (
    <div
      role="dialog"
      aria-label="Tag filters"
      className={`fixed inset-x-4 bottom-4 ${PAGE_LAYER_CLASSES.activeControlPanel} flex max-h-[calc(100dvh-2rem)] flex-col overflow-hidden rounded-lg border bg-card shadow-xl sm:absolute sm:bottom-auto sm:left-auto sm:right-0 sm:top-full sm:mt-2 sm:w-[min(24rem,calc(100vw-2rem))]`}
    >
      <div className="flex-none border-b p-3">
        <div className="mb-2 flex items-center justify-between gap-3">
          <p className="text-sm font-medium">Filter by tags</p>
          <span className="text-xs text-muted-foreground">{selectedTags.length} selected</span>
        </div>
        <div className="relative">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            value={query}
            onChange={(event) => onQueryChange(event.target.value)}
            placeholder="Search your tags…"
            className="h-9 pl-8"
            aria-label="Search tags"
            autoFocus
          />
        </div>
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-2 sm:max-h-72">
        <TagOptions {...{ loading, error, retryCount, onRetry, tags, selectedTags, onToggle, query }} />
      </div>
      <div className="flex flex-none justify-between border-t p-2">
        <Button type="button" variant="ghost" size="sm" onClick={onClear} disabled={selectedTags.length === 0}>Clear</Button>
        <Button type="button" size="sm" onClick={onApply}>Apply</Button>
      </div>
    </div>
  );
}

function TagFilter({ open, onOpenChange, loading, panelProps }) {
  const rootRef = useRef(null);
  const triggerRef = useRef(null);
  const className = open
    ? `relative w-full sm:w-auto ${PAGE_LAYER_CLASSES.activeControl}`
    : "relative w-full sm:w-auto";

  useEffect(() => {
    if (!open) return undefined;

    const dismissOnEscape = (event) => {
      if (event.key !== "Escape") return;

      event.preventDefault();
      onOpenChange(false);
      triggerRef.current?.focus();
    };
    const dismissOnOutsidePointer = (event) => {
      if (!rootRef.current?.contains(event.target)) onOpenChange(false);
    };

    document.addEventListener("keydown", dismissOnEscape);
    document.addEventListener("pointerdown", dismissOnOutsidePointer);
    return () => {
      document.removeEventListener("keydown", dismissOnEscape);
      document.removeEventListener("pointerdown", dismissOnOutsidePointer);
    };
  }, [onOpenChange, open]);

  return (
    <div ref={rootRef} className={className}>
      <Button
        ref={triggerRef}
        type="button"
        variant="outline"
        className="flex w-full items-center gap-2 sm:w-auto"
        onClick={() => onOpenChange(!open)}
        disabled={loading}
        aria-expanded={open}
        aria-haspopup="dialog"
      >
        {loading ? <Spinner className="mr-1 h-4 w-4" /> : <TagIcon className="h-4 w-4" />}
        Tags
      </Button>
      {open && <TagFilterPanel {...panelProps} loading={loading} />}
    </div>
  );
}

function SearchSubmitButton({ searching }) {
  return (
    <Button type="submit" className="w-full sm:w-auto" disabled={searching}>
      {searching ? (
        <><Spinner className="-ml-1 mr-2 h-4 w-4 text-white" /> Searching...</>
      ) : "Search"}
    </Button>
  );
}

function SelectedTagBadges({ tags, onToggle }) {
  if (tags.length === 0) return null;

  return (
    <div className="mt-2 flex max-w-full gap-2 overflow-x-auto pb-1">
      {tags.map((tag) => (
        <TagBadge key={tag.id} tag={tag} className="flex shrink-0 items-center gap-1">
          <TagIcon className="h-3 w-3" />
          {tag.name}
          <button
            type="button"
            onClick={() => onToggle(tag)}
            className="ml-1 hover:text-destructive"
            aria-label={`Remove ${tag.name} filter`}
          >
            <X className="h-3 w-3" />
          </button>
        </TagBadge>
      ))}
    </div>
  );
}

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
  useSearchShortcut(searchInputRef);


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

  const applyTags = () => {
    setShowTagDropdown(false);
    setTagQuery("");
    onSearch({ query: searchQuery, tags: selectedTags.map((tag) => tag.name) });
  };
  
  return (
    <div className={`relative w-full max-w-3xl ${showTagDropdown ? PAGE_LAYER_CLASSES.activeControl : ""}`}>
      <form onSubmit={handleSearch} className="grid grid-cols-2 items-center gap-2 sm:flex">
        <SearchQueryInput
          inputRef={searchInputRef}
          query={searchQuery}
          selectedCount={selectedTags.length}
          focused={isSearchFocused}
          onQueryChange={setSearchQuery}
          onFocusChange={setIsSearchFocused}
          onClear={clearSearch}
        />
        
        {/* Only raised while its panel is open. A permanently stacked button
            here is what used to float this control over the Edit and Share
            dialogs; see lib/overlay-layers.js for the full scale. */}
        <TagFilter
          open={showTagDropdown}
          onOpenChange={setShowTagDropdown}
          loading={isLoadingTags}
          panelProps={{
            query: tagQuery,
            onQueryChange: setTagQuery,
            selectedTags,
            onClear: () => setSelectedTags([]),
            onApply: applyTags,
            error: tagFetchError,
            retryCount,
            onRetry: retryTagFetch,
            tags: filteredTags,
            onToggle: toggleTag,
          }}
        />
        
        <SearchSubmitButton searching={isSearching} />
      </form>
      
      <SelectedTagBadges tags={selectedTags} onToggle={toggleTag} />
    </div>
  );
}
