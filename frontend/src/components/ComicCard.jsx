
import { Link } from "react-router-dom";
import { Card, CardContent, CardFooter } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { BookOpen, RotateCcw, Tag as TagIcon, Edit, Trash2, MoreVertical, Share2Icon } from "lucide-react";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from "@/components/ui/dialog";
import { useState } from "react";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
import { useToast } from "@/hooks/use-toast.js";

export function ComicCard({ comic, onResetProgress, onEditComic, onDeleteComic, onShareClick }) {
  const [isResetDialogOpen, setIsResetDialogOpen] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const { toast } = useToast();

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
      await onDeleteComic(comic.id);
      setIsDeleteDialogOpen(false);
      toast({
        title: "Comic deleted",
        description: "The comic has been removed from your library.",
      });
    } catch (error) {
      toast({
        title: "Error",
        description: error.message || "Failed to delete comic",
        variant: "destructive",
      });
    }
  };

  const handleEditClick = (e) => {
    e.preventDefault();
    e.stopPropagation();
    onEditComic(comic);
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
              className="absolute inset-0 w-full h-full object-cover transition-transform group-hover:scale-105"
            />
            {comic.lastReadPage !== undefined && (
              <div className="absolute bottom-0 left-0 right-0 bg-black/70 text-white p-2 text-xs flex justify-between items-center">
                <span>Page {comic.lastReadPage} / {comic.pageCount}</span>
                <Button 
                  variant="ghost" 
                  size="sm" 
                  className="h-7 w-7 p-0 text-white hover:text-red-400"
                  onClick={handleResetClick}
                >
                  <RotateCcw size={16} />
                </Button>
              </div>
            )}
          </div>
          <CardContent className="p-4">
            <h3 className="font-bold truncate">{comic.title}</h3>
            <p className="text-sm text-muted-foreground truncate">{comic.author}</p>
            
            {comic.tags && comic.tags.length > 0 && (
              <div className="flex flex-wrap gap-1 mt-2">
                {comic.tags.slice(0, 3).map((tag, index) => (
                  <Badge key={index} variant="outline" className="text-xs flex items-center gap-1">
                    <TagIcon size={10} />
                    {tag}
                  </Badge>
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
        <div className="absolute top-2 right-2 z-10">
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" className="h-8 w-8 bg-black/50 hover:bg-black/70 text-white rounded-full">
                <MoreVertical size={16} />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onClick={handleEditClick}>
                <Edit className="mr-2 h-4 w-4" />
                Edit Details
              </DropdownMenuItem>
              <DropdownMenuItem onClick={(e) => {
                e.preventDefault();
                e.stopPropagation();
                setIsDeleteDialogOpen(true);
              }}>
                <Trash2 className="mr-2 h-4 w-4" />
                Delete Comic
              </DropdownMenuItem>
              <DropdownMenuItem onClick={(e) => {
                e.preventDefault();
                e.stopPropagation();
                onShareClick(comic.id, comic.title);
              }}>
                <Share2Icon className="mr-2 h-4 w-4" />
                Share Comic
              </DropdownMenuItem>
              {comic.lastReadPage !== undefined && (
                <DropdownMenuItem onClick={handleResetClick}>
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
      
      <Dialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete Comic</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete "{comic.title}" from your library? This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setIsDeleteDialogOpen(false)}>Cancel</Button>
            <Button variant="destructive" onClick={confirmDelete}>Delete Comic</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
