
import { Link } from "react-router-dom";
import { Card, CardContent, CardFooter } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { TagBadge } from "@/components/TagBadge";
import {
  BookOpen,
  RotateCcw,
  Tag as TagIcon,
  Edit,
  Trash2,
  MoreVertical,
  Share2Icon,
  Users,
} from "lucide-react";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from "@/components/ui/dialog";
import { useState } from "react";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
import { useToast } from "@/hooks/use-toast.js";
import { cn } from "@/lib/utils";
import { PAGE_LAYER_CLASSES } from "@/lib/overlay-layers";
import { describeShareImpactOfDeletion } from "@/lib/sharing";

export function ComicCard({
  comic,
  coverPriority = false,
  onResetProgress,
  onEditComic,
  onDeleteComic,
  onShareClick,
  onRemoveSharedComic,
}) {
  const [isResetDialogOpen, setIsResetDialogOpen] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [isRemoveDialogOpen, setIsRemoveDialogOpen] = useState(false);
  const [isOrphaned, setIsOrphaned] = useState(false);
  // A cover served from the browser cache decodes in the same frame, so this
  // only ever fades in a genuine first load rather than every revisit. A comic
  // with no cover has nothing to wait for.
  const [coverLoaded, setCoverLoaded] = useState(!comic.coverImagePath);
  const { toast } = useToast();

  // The server decides what this viewer may do; the card only reflects it. A
  // recipient sees no owner actions at all — the comic is not theirs to edit,
  // delete or pass on.
  const isSharedWithMe = Boolean(comic.isShared);
  const canEdit = comic.canEdit !== false && !isSharedWithMe;
  const canDelete = comic.canDelete !== false && !isSharedWithMe;
  const canShare = comic.canShare !== false && !isSharedWithMe;
  const shareImpact = describeShareImpactOfDeletion(comic);

  const handleResetClick = (e) => {
    e.preventDefault();
    e.stopPropagation();
    setIsResetDialogOpen(true);
  };

  const confirmReset = async () => {
    try {
      await onResetProgress(comic.id);
      setIsResetDialogOpen(false);
      toast({
        title: "Reading progress reset",
        description: "Your reading progress has been reset successfully.",
      });
    } catch (error) {
      toast({
        title: "Error",
        description: error.message || "Failed to reset reading progress",
        variant: "destructive",
      });
    }
  };

  const confirmDelete = async () => {
    try {
      await onDeleteComic(comic.id, { confirmOrphaned: isOrphaned });
      setIsDeleteDialogOpen(false);
      setIsOrphaned(false);
      toast({
        title: "Comic deleted",
        description: isOrphaned
          ? "The orphaned comic record has been removed from your library."
          : "The comic has been removed from your library.",
      });
    } catch (error) {
      if (error.data?.code === "orphaned_comics_confirmation_required") {
        setIsOrphaned(true);
        return;
      }
      toast({
        title: "Error",
        description: error.message || "Failed to delete comic",
        variant: "destructive",
      });
    }
  };

  const confirmRemoveShared = async () => {
    try {
      await onRemoveSharedComic(comic);
      setIsRemoveDialogOpen(false);
      toast({
        title: "Removed from your collection",
        description: "You can restore it from the Sharing page while it is still shared with you.",
      });
    } catch (error) {
      toast({
        title: "Error",
        description: error.message || "Could not remove the comic from your collection",
        variant: "destructive",
      });
    }
  };

  return (
    <>
      <div className="relative">
        <Link to={`/read/${comic.id}`} className="block group">
        <Card className="overflow-hidden transition-all duration-300 hover:shadow-lg border-2 hover:border-comic-purple">
          <div className="relative pt-[140%] bg-muted overflow-hidden">
            <img
              src={comic.coverImagePath}
              alt={comic.title}
              decoding="async"
              loading={coverPriority ? "eager" : "lazy"}
              fetchpriority={coverPriority ? "high" : "auto"}
              onLoad={() => setCoverLoaded(true)}
              // A cover that fails still has to reveal its alt text.
              onError={() => setCoverLoaded(true)}
              // One transition property covering both: separate transition-*
              // classes would be merged down to whichever came last.
              className={cn(
                "absolute inset-0 w-full h-full object-cover",
                "transition-[transform,opacity] duration-150 group-hover:scale-105",
                coverLoaded ? "opacity-100" : "opacity-0"
              )}
            />
            {isSharedWithMe && (
              <Badge className="absolute left-2 top-2 bg-comic-purple text-white">
                Shared by {comic.sharedBy?.name || "another user"}
              </Badge>
            )}
            {comic.lastReadPage !== undefined && (
              <div className="absolute bottom-0 left-0 right-0 bg-black/70 text-white p-2 text-xs flex justify-between items-center">
                <span>Page {comic.lastReadPage} / {comic.pageCount}</span>
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-7 w-7 p-0 text-white hover:text-red-400"
                  onClick={handleResetClick}
                  aria-label={`Reset reading progress for ${comic.title}`}
                >
                  <RotateCcw size={16} />
                </Button>
              </div>
            )}
          </div>
          <CardContent className="p-4">
            <h3 className="font-bold truncate">{comic.title}</h3>
            <p className="text-sm text-muted-foreground truncate">{comic.author}</p>

            {comic.sharedWithCount > 0 && (
              <p className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
                <Users size={12} />
                Shared with {comic.sharedWithCount}{" "}
                {comic.sharedWithCount === 1 ? "person" : "people"}
              </p>
            )}

            {comic.tags && comic.tags.length > 0 && (
              <div className="flex flex-wrap gap-1 mt-2">
                {comic.tags.slice(0, 3).map((tag, index) => (
                  <TagBadge key={index} tag={tag} hideFromLibrary={comic.hiddenTagNames?.includes(tag)} className="text-xs flex items-center gap-1">
                    <TagIcon size={10} />
                    {tag}
                  </TagBadge>
                ))}
                {comic.tags.length > 3 && (
                  <Badge variant="outline" className="text-xs">
                    +{comic.tags.length - 3}
                  </Badge>
                )}
              </div>
            )}
          </CardContent>
          <CardFooter className="px-4 pb-4 pt-0">
            <Button variant="secondary" className="w-full">
              <BookOpen className="mr-2 h-4 w-4" />
              {comic.lastReadPage !== undefined ? "Continue Reading" : "Start Reading"}
            </Button>
          </CardFooter>
        </Card>
      </Link>
        <div className={cn("absolute top-2 right-2", PAGE_LAYER_CLASSES.cardAction)}>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="ghost"
                size="icon"
                className="h-8 w-8 bg-black/50 hover:bg-black/70 text-white rounded-full"
                aria-label={`Actions for ${comic.title}`}
              >
                <MoreVertical size={16} />
              </Button>
            </DropdownMenuTrigger>
            {/* onSelect, not onClick: Radix closes the menu from its own click
                handler, and preventDefault() on the click event suppressed it —
                which is how the menu ended up sitting over the dialog it had
                just opened. The menu is outside the card's Link, so there is no
                navigation left to cancel. */}
            <DropdownMenuContent align="end">
              {canEdit && (
                <DropdownMenuItem onSelect={() => onEditComic(comic)}>
                  <Edit className="mr-2 h-4 w-4" />
                  Edit Details
                </DropdownMenuItem>
              )}
              {canDelete && (
                <DropdownMenuItem onSelect={() => setIsDeleteDialogOpen(true)}>
                  <Trash2 className="mr-2 h-4 w-4" />
                  Delete Comic
                </DropdownMenuItem>
              )}
              {canShare && (
                <DropdownMenuItem onSelect={() => onShareClick(comic.id, comic.title)}>
                  <Share2Icon className="mr-2 h-4 w-4" />
                  Share Comic
                </DropdownMenuItem>
              )}
              {/* Removing hides the owner's comic from this collection; it
                  deletes nothing, which is why it is worded differently. */}
              {isSharedWithMe && (
                <DropdownMenuItem onSelect={() => setIsRemoveDialogOpen(true)}>
                  <Trash2 className="mr-2 h-4 w-4" />
                  Remove from my collection
                </DropdownMenuItem>
              )}
              {comic.lastReadPage !== undefined && (
                <DropdownMenuItem onSelect={() => setIsResetDialogOpen(true)}>
                  <RotateCcw className="mr-2 h-4 w-4" />
                  Reset Progress
                </DropdownMenuItem>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      <Dialog open={isResetDialogOpen} onOpenChange={setIsResetDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Reset Reading Progress</DialogTitle>
            <DialogDescription>
              Are you sure you want to reset your reading progress for "{comic.title}"? This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setIsResetDialogOpen(false)}>Cancel</Button>
            <Button variant="destructive" onClick={confirmReset}>Reset Progress</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog
        open={isDeleteDialogOpen}
        onOpenChange={(open) => {
          setIsDeleteDialogOpen(open);
          if (!open) setIsOrphaned(false);
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{isOrphaned ? "Delete orphaned comic record?" : `Delete “${comic.title}”?`}</DialogTitle>
            <DialogDescription>
              {isOrphaned
                ? `The CBZ file for “${comic.title}” is no longer present. Do you want to remove only its orphaned library record?`
                : `Delete “${comic.title}” from your library? Its existing files will be moved to recoverable quarantine storage.`}
            </DialogDescription>
          </DialogHeader>
          {/* A deletion that also cuts other people off is a bigger decision
              than a deletion that does not, and has to say so before the
              destructive button is pressed. */}
          {!isOrphaned && shareImpact && (
            <p className="rounded border border-destructive/40 bg-destructive/10 p-3 text-sm">
              {shareImpact}
            </p>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setIsDeleteDialogOpen(false)}>Cancel</Button>
            <Button variant="destructive" onClick={confirmDelete}>
              {isOrphaned
                ? "Delete orphaned record"
                : shareImpact ? "Delete for everyone" : "Delete Comic"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={isRemoveDialogOpen} onOpenChange={setIsRemoveDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Remove “{comic.title}” from your collection?</DialogTitle>
            <DialogDescription>
              This hides the comic from your collection. It is not deleted —{" "}
              {comic.sharedBy?.name || "the owner"} keeps it, and you can restore it from the
              Sharing page for as long as they keep sharing it.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setIsRemoveDialogOpen(false)}>Cancel</Button>
            <Button variant="destructive" onClick={confirmRemoveShared}>
              Remove from my collection
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
