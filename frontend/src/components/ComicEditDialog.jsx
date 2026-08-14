import { useState } from "react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Checkbox } from "@/components/ui/checkbox";
import { X, Tag as TagIcon, Plus } from "lucide-react";
import { useToast } from "@/hooks/use-toast.js";
import { useTags } from "@/hooks/use-tags.jsx";
import { TagBadge } from "@/components/TagBadge";
import { TagCombobox } from "@/components/TagCombobox";
import { describeTagSubmission } from "@/lib/tag-suggestions.js";
import { EXPLICIT_FLAG_DESCRIPTION, EXPLICIT_FLAG_LABEL } from "@/lib/sharing.js";
import { MetadataSuggestions } from "@/components/MetadataSuggestions";

/**
 * Editing a different comic means a different set of fields, so the form is
 * remounted per comic rather than being reset field-by-field from an effect.
 * Keying here rather than at the call sites keeps that guarantee with the
 * component: a caller cannot forget it and get another comic's draft.
 */
export function ComicEditDialog({ comic, ...props }) {
  // Keyed on the opening, not just the comic: the dialog stays mounted while it
  // is closed, so a cancelled edit of a comic would otherwise still be sitting
  // in the fields the next time that same comic was opened.
  return (
    <ComicEditDialogForm
      key={`${comic?.id ?? "none"}:${props.isOpen ? "open" : "closed"}`}
      comic={comic}
      {...props}
    />
  );
}

function ComicEditDialogForm({ comic, isOpen, onClose, onSave }) {
  const [title, setTitle] = useState(comic?.title || "");
  const [author, setAuthor] = useState(comic?.author || "");
  const [publisher, setPublisher] = useState(comic?.publisher || "");
  const [description, setDescription] = useState(comic?.description || "");
  const [tags, setTags] = useState(comic?.tags || []);
  // Taken from the comic like every other field, and never inferred from one:
  // adding or removing a tag — including one that hides the comic from the
  // library — must leave this exactly as the owner set it.
  const [explicitContent, setExplicitContent] = useState(comic?.explicitContent === true);
  const [structured, setStructured] = useState({
    series: comic?.series ?? "",
    issueNumber: comic?.issueNumber ?? "",
    volume: comic?.volume ?? "",
    publishedAt: comic?.publishedAt ?? "",
  });
  const [newTag, setNewTag] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const { toast } = useToast();
  const { tags: availableTags, addTagToCache } = useTags();

  // Resolves what is typed against the tags this user already has, so a
  // differently cased entry reuses the existing tag instead of adding a variant.
  const tagSubmission = describeTagSubmission(availableTags, newTag, tags);
  const canAddTag = tagSubmission.status === "existing" || tagSubmission.status === "new";

  const handleAddTag = (name) => {
    const resolved = name ?? tagSubmission.name;
    if (!resolved || tags.some((tag) => tag.toLowerCase() === resolved.toLowerCase())) return;

    setTags([...tags, resolved]);
    setNewTag("");
  };

  const setStructuredField = (field, value) => setStructured((current) => ({ ...current, [field]: value }));

  // A suggestion lands in the field it belongs to, so the user sees what they
  // are about to save and can still change it or cancel.
  const handleAcceptSuggestion = (patch) => {
    Object.entries(patch).forEach(([field, value]) => {
      if (field === "publisher") setPublisher(String(value));
      else if (field === "description") setDescription(String(value));
      else setStructuredField(field, String(value));
    });
  };

  const handleRemoveTag = (tagToRemove) => {
    setTags(tags.filter(tag => tag !== tagToRemove));
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
        tags,
        explicitContent,
        series: structured.series.trim() === "" ? null : structured.series.trim(),
        issueNumber: structured.issueNumber.trim() === "" ? null : structured.issueNumber.trim(),
        volume: String(structured.volume).trim() === "" ? null : String(structured.volume).trim(),
        publishedAt: structured.publishedAt.trim() === "" ? null : structured.publishedAt.trim(),
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
          {/* Radix wires this to the dialog as its accessible description, so a
              screen reader announces what the form is for rather than reading
              the title and dropping the user into an unexplained set of fields.
              Without one it also warns on every mount. */}
          <DialogDescription>
            Change the details stored for this comic, including whether it is classified 18+.
          </DialogDescription>
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
          
          <div className="grid grid-cols-2 gap-3">
            <div className="grid gap-2">
              <Label htmlFor="series">Series</Label>
              <Input
                id="series"
                value={structured.series}
                onChange={(e) => setStructuredField("series", e.target.value)}
                placeholder="Series name"
              />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="issueNumber">Issue</Label>
              <Input
                id="issueNumber"
                value={structured.issueNumber}
                onChange={(e) => setStructuredField("issueNumber", e.target.value)}
                placeholder="7"
              />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="volume">Volume</Label>
              <Input
                id="volume"
                value={structured.volume}
                onChange={(e) => setStructuredField("volume", e.target.value)}
                placeholder="1996"
              />
            </div>
            <div className="grid gap-2">
              <Label htmlFor="publishedAt">Published</Label>
              <Input
                id="publishedAt"
                type="date"
                value={structured.publishedAt}
                onChange={(e) => setStructuredField("publishedAt", e.target.value)}
              />
            </div>
          </div>

          <MetadataSuggestions comicId={comic?.id} onAccept={handleAcceptSuggestion} />

          <div className="grid gap-2">
            <Label>Tags</Label>
            <div className="flex flex-wrap gap-2 mb-2">
              {tags.map((tag, index) => (
                <TagBadge key={index} tag={availableTags.find((item) => item.name === tag) || tag} className="flex items-center gap-1">
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
                </TagBadge>
              ))}
            </div>
            <div className="flex gap-2 relative">
              <TagCombobox
                value={newTag}
                onChange={setNewTag}
                onSubmit={handleAddTag}
                applied={tags}
                className="flex-1"
              />
              <Button
                type="button"
                size="sm"
                onClick={() => handleAddTag()}
                disabled={!canAddTag}
              >
                <Plus size={16} className="mr-1" />
                Add
              </Button>
            </div>
          </div>

          {/* Below the tags, and deliberately not among them. Hiding a comic
              from the library is a shelving choice; this is a statement about
              what is inside it, and the two must not be read as one setting. */}
          <div className="flex items-start gap-3 rounded-md border p-3">
            <Checkbox
              id="explicit-content"
              checked={explicitContent}
              onCheckedChange={(checked) => setExplicitContent(checked === true)}
              className="mt-0.5"
            />
            <div className="grid gap-1">
              <Label htmlFor="explicit-content" className="cursor-pointer">
                {EXPLICIT_FLAG_LABEL}
              </Label>
              <p className="text-xs text-muted-foreground">{EXPLICIT_FLAG_DESCRIPTION}</p>
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
