
import { Link } from "react-router-dom";
import { Card, CardContent, CardFooter } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { BookOpen, RotateCcw, Tag as TagIcon } from "lucide-react";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from "@/components/ui/dialog";
import { useState } from "react";

export function ComicCard({ comic, onResetProgress }) {
  const [isResetDialogOpen, setIsResetDialogOpen] = useState(false);

  const handleResetClick = (e) => {
    e.preventDefault();
    e.stopPropagation();
    setIsResetDialogOpen(true);
  };

  const confirmReset = () => {
    onResetProgress(comic.id);
    setIsResetDialogOpen(false);
  };
  
  return (
    <>
      <Link to={`/read/${comic.id}`} className="block group">
        <Card className="overflow-hidden transition-all duration-300 hover:shadow-lg border-2 hover:border-comic-purple">
          <div className="relative pt-[140%] bg-muted overflow-hidden">
            <img 
              src={comic.coverImage} 
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
    </>
  );
}
