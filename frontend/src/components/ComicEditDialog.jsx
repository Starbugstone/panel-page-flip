import { useState, useEffect, useRef } from "react";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Badge } from "@/components/ui/badge";
import { X, Tag as TagIcon, Plus, Loader2 } from "lucide-react";
import { useToast } from "@/hooks/use-toast.js";
import { useTags } from "@/hooks/use-tags.jsx";
import { cn } from "@/lib/utils.js";

export function ComicEditDialog({ comic, isOpen, onClose, onSave }) {
  const [title, setTitle] = useState("");
  const [author, setAuthor] = useState("");
  const [publisher, setPublisher] = useState("");
  const [description, setDescription] = useState("");
  const [tags, setTags] = useState([]);
  const [newTag, setNewTag] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [tagSuggestions, setTagSuggestions] = useState([]);
  const [isLoadingSuggestions, setIsLoadingSuggestions] = useState(false);
  const [showSuggestions, setShowSuggestions] = useState(false);
  const suggestionsRef = useRef(null);
  const tagInputRef = useRef(null);
  const { toast } = useToast();
  const { searchTags, addTagToCache, isAdminContext } = useTags();

  useEffect(() => {
    if (comic) {
      setTitle(comic.title || "");
      setAuthor(comic.author || "");
      setPublisher(comic.publisher || "");
      setDescription(comic.description || "");
      setTags(comic.tags || []);
    }
  }, [comic]);

  // Fetch tag suggestions based on input
  const fetchTagSuggestions = async (query) => {
    if (!query.trim() || query.trim().length < 2) {
      setTagSuggestions([]);
      setShowSuggestions(false);
      return;
    }

    setIsLoadingSuggestions(true);
    try {
      // Use the tag context to search for tags
      // Pass the current context (admin or not)
      const results = await searchTags(query.trim(), isAdminContext());
      setTagSuggestions(results.map(tag => tag.name));
      setShowSuggestions(true);
    } catch (error) {
      console.error('Error fetching tag suggestions:', error);
      setTagSuggestions([]);
    } finally {
      setIsLoadingSuggestions(false);
    }
  };

  // Debounce function for tag suggestions
  useEffect(() => {
    const timeoutId = setTimeout(() => {
      fetchTagSuggestions(newTag);
    }, 300);
    return () => clearTimeout(timeoutId);
  }, [newTag]);

  // Close suggestions when clicking outside
  useEffect(() => {
    const handleClickOutside = (event) => {
      if (suggestionsRef.current && !suggestionsRef.current.contains(event.target) && 
          tagInputRef.current && !tagInputRef.current.contains(event.target)) {
        setShowSuggestions(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const handleAddTag = () => {
    if (newTag.trim() && !tags.includes(newTag.trim())) {
      setTags([...tags, newTag.trim()]);
      setNewTag("");
      setShowSuggestions(false);
    }
  };

  const handleRemoveTag = (tagToRemove) => {
    setTags(tags.filter(tag => tag !== tagToRemove));
  };

  const handleKeyDown = (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      handleAddTag();
    } else if (e.key === "Escape") {
      setShowSuggestions(false);
    } else if (e.key === "ArrowDown" && showSuggestions && tagSuggestions.length > 0) {
      e.preventDefault();
      const suggestionElements = suggestionsRef.current?.querySelectorAll('button');
      if (suggestionElements?.length) suggestionElements[0].focus();
    }
  };
  
  const handleSuggestionKeyDown = (e, index) => {
    if (e.key === "Enter") {
      e.preventDefault();
      selectSuggestion(tagSuggestions[index]);
    } else if (e.key === "Escape") {
      setShowSuggestions(false);
      tagInputRef.current?.focus();
    } else if (e.key === "ArrowDown") {
      e.preventDefault();
      const suggestionElements = suggestionsRef.current?.querySelectorAll('button');
      if (suggestionElements?.length && index < suggestionElements.length - 1) {
        suggestionElements[index + 1].focus();
      }
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      const suggestionElements = suggestionsRef.current?.querySelectorAll('button');
      if (index > 0 && suggestionElements?.length) {
        suggestionElements[index - 1].focus();
      } else {
        tagInputRef.current?.focus();
      }
    }
  };

  const selectSuggestion = (suggestion) => {
    if (!tags.includes(suggestion)) {
      setTags([...tags, suggestion]);
      setNewTag("");
      setShowSuggestions(false);
      tagInputRef.current?.focus();
    }
  };

  const handleSubmit = async () => {
    if (!title.trim()) {
      toast({
        title: "Title required",
        description: "Please provide a title for the comic.",
        variant: "destructive"
      });
      return;
    }

    setIsSubmitting(true);
    try {
      const result = await onSave({
        id: comic.id,
        title,
        author,
        publisher,
        description,
        tags
      });
      
      // If we have new tags, add them to the cache
      if (result && result.tags) {
        result.tags.forEach(tag => {
          if (typeof tag === 'object' && tag.id && tag.name) {
            addTagToCache(tag);
          }
        });
      }
      
      toast({
        title: "Comic updated",
        description: "Comic details have been updated successfully."
      });
      
      onClose();
    } catch (error) {
      toast({
        title: "Update failed",
        description: error.message || "Failed to update comic details.",
        variant: "destructive"
      });
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="sm:max-w-[500px]">
        <DialogHeader>
          <DialogTitle>Edit Comic Details</DialogTitle>
        </DialogHeader>
        
        <div className="grid gap-4 py-4">
          <div className="grid gap-2">
            <Label htmlFor="title">Title *</Label>
            <Input
              id="title"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              placeholder="Comic title"
              required
            />
          </div>
          
          <div className="grid gap-2">
            <Label htmlFor="author">Author</Label>
            <Input
              id="author"
              value={author}
              onChange={(e) => setAuthor(e.target.value)}
              placeholder="Comic author"
            />
          </div>
          
          <div className="grid gap-2">
            <Label htmlFor="publisher">Publisher</Label>
            <Input
              id="publisher"
              value={publisher}
              onChange={(e) => setPublisher(e.target.value)}
              placeholder="Comic publisher"
            />
          </div>
          
          <div className="grid gap-2">
            <Label htmlFor="description">Description</Label>
            <Textarea
              id="description"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="Comic description"
              rows={3}
            />
          </div>
          
          <div className="grid gap-2">
            <Label>Tags</Label>
            <div className="flex flex-wrap gap-2 mb-2">
              {tags.map((tag, index) => (
                <Badge key={index} variant="secondary" className="flex items-center gap-1">
                  <TagIcon size={12} />
                  {tag}
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-4 w-4 p-0 ml-1"
                    onClick={() => handleRemoveTag(tag)}
                  >
                    <X size={12} />
                  </Button>
                </Badge>
              ))}
            </div>
            <div className="flex gap-2 relative">
              <div className="flex-1 relative">
                <Input
                  ref={tagInputRef}
                  value={newTag}
                  onChange={(e) => setNewTag(e.target.value)}
                  onKeyDown={handleKeyDown}
                  onFocus={() => newTag.trim().length >= 2 && setShowSuggestions(true)}
                  placeholder="Add a tag"
                  className="flex-1 w-full"
                  autoComplete="off"
                  autoCorrect="off"
                  autoCapitalize="off"
                  spellCheck="false"
                  name="tag-input-unique"
                />
                {isLoadingSuggestions && (
                  <div className="absolute right-2 top-1/2 transform -translate-y-1/2">
                    <Loader2 size={16} className="animate-spin text-muted-foreground" />
                  </div>
                )}
                {showSuggestions && tagSuggestions.length > 0 && (
                  <div 
                    ref={suggestionsRef}
                    className="absolute z-10 mt-1 w-full bg-background border rounded-md shadow-lg max-h-60 overflow-auto"
                  >
                    <div className="py-1">
                      {tagSuggestions.map((suggestion, index) => (
                        <button
                          key={index}
                          type="button"
                          className={cn(
                            "w-full text-left px-4 py-2 text-sm hover:bg-accent focus:bg-accent focus:outline-none",
                            tags.includes(suggestion) ? "opacity-50" : ""
                          )}
                          onClick={() => selectSuggestion(suggestion)}
                          onKeyDown={(e) => handleSuggestionKeyDown(e, index)}
                          disabled={tags.includes(suggestion)}
                        >
                          {suggestion}
                          {tags.includes(suggestion) && " (already added)"}
                        </button>
                      ))}
                    </div>
                  </div>
                )}
              </div>
              <Button 
                type="button" 
                size="sm" 
                onClick={handleAddTag}
                disabled={!newTag.trim()}
              >
                <Plus size={16} className="mr-1" />
                Add
              </Button>
            </div>
          </div>
        </div>
        
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button onClick={handleSubmit} disabled={isSubmitting}>
            {isSubmitting ? "Saving..." : "Save Changes"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
