import { useState, useEffect } from "react";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Badge } from "@/components/ui/badge";
import { X, Tag as TagIcon, Plus } from "lucide-react";
import { useToast } from "@/hooks/use-toast.js";

export function ComicEditDialog({ comic, isOpen, onClose, onSave }) {
  const [title, setTitle] = useState("");
  const [author, setAuthor] = useState("");
  const [publisher, setPublisher] = useState("");
  const [description, setDescription] = useState("");
  const [tags, setTags] = useState([]);
  const [newTag, setNewTag] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const { toast } = useToast();

  useEffect(() => {
    if (comic) {
      setTitle(comic.title || "");
      setAuthor(comic.author || "");
      setPublisher(comic.publisher || "");
      setDescription(comic.description || "");
      setTags(comic.tags || []);
    }
  }, [comic]);

  const handleAddTag = () => {
    if (newTag.trim() && !tags.includes(newTag.trim())) {
      setTags([...tags, newTag.trim()]);
      setNewTag("");
    }
  };

  const handleRemoveTag = (tagToRemove) => {
    setTags(tags.filter(tag => tag !== tagToRemove));
  };

  const handleKeyDown = (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      handleAddTag();
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
      await onSave({
        id: comic.id,
        title,
        author,
        publisher,
        description,
        tags
      });
      
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
            <div className="flex gap-2">
              <Input
                value={newTag}
                onChange={(e) => setNewTag(e.target.value)}
                onKeyDown={handleKeyDown}
                placeholder="Add a tag"
                className="flex-1"
              />
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
