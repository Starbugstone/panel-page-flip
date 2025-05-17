
import { useState, useEffect } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { mockComics, generateComicPages } from "@/lib/mockData";
import { Comic } from "@/types";
import { ArrowLeft, ArrowRight } from "lucide-react";
import { useToast } from "@/hooks/use-toast";
import { Skeleton } from "@/components/ui/skeleton";

export default function ComicReader() {
  const { comicId } = useParams<{ comicId: string }>();
  const [comic, setComic] = useState<Comic | null>(null);
  const [comicPages, setComicPages] = useState<string[]>([]);
  const [currentPage, setCurrentPage] = useState(0);
  const [isLoading, setIsLoading] = useState(true);
  const navigate = useNavigate();
  const { toast } = useToast();

  useEffect(() => {
    const loadComic = async () => {
      try {
        // In a real app, this would fetch from your backend API
        // For now, we'll use our mock data
        await new Promise(resolve => setTimeout(resolve, 800)); // Simulate network delay
        
        const foundComic = mockComics.find(c => c.id === comicId);
        
        if (foundComic) {
          setComic(foundComic);
          setComicPages(generateComicPages(comicId!, foundComic.totalPages));
          
          // If the comic has been read before, start from the last read page
          if (foundComic.lastReadPage !== undefined) {
            setCurrentPage(foundComic.lastReadPage - 1);
          }
        } else {
          toast({
            title: "Comic not found",
            description: "The requested comic could not be found.",
            variant: "destructive",
          });
          navigate("/dashboard");
        }
      } catch (error) {
        console.error("Failed to load comic:", error);
        toast({
          title: "Error loading comic",
          description: "There was a problem loading the comic. Please try again.",
          variant: "destructive",
        });
      } finally {
        setIsLoading(false);
      }
    };

    loadComic();
  }, [comicId, navigate, toast]);

  const handlePreviousPage = () => {
    if (currentPage > 0) {
      setCurrentPage(currentPage - 1);
    }
  };

  const handleNextPage = () => {
    if (currentPage < comicPages.length - 1) {
      setCurrentPage(currentPage + 1);
      
      // In a real app, this would be an API call to update the reading progress
      console.log(`Updating reading progress: Page ${currentPage + 2} of ${comicPages.length}`);
    }
  };

  // Click on left side of screen to go back, right side to go forward
  const handleScreenNavClick = (direction: 'left' | 'right') => {
    if (direction === 'left') {
      handlePreviousPage();
    } else {
      handleNextPage();
    }
  };

  // Handle keyboard navigation
  useEffect(() => {
    const handleKeyPress = (event: KeyboardEvent) => {
      switch (event.key) {
        case "ArrowLeft":
          handlePreviousPage();
          break;
        case "ArrowRight":
          handleNextPage();
          break;
        default:
          break;
      }
    };

    window.addEventListener("keydown", handleKeyPress);
    return () => window.removeEventListener("keydown", handleKeyPress);
  }, [currentPage, comicPages.length]);

  if (isLoading) {
    return (
      <div className="min-h-screen flex justify-center items-center bg-background">
        <Skeleton className="w-full max-w-md h-[60vh] mx-auto" />
      </div>
    );
  }

  if (!comic) {
    return (
      <div className="min-h-screen flex flex-col justify-center items-center bg-background">
        <p className="text-xl mb-4">Comic not found</p>
        <Button onClick={() => navigate("/dashboard")}>Return to Library</Button>
      </div>
    );
  }

  return (
    <div className="min-h-screen flex flex-col items-center bg-background overflow-hidden">
      {/* Left side navigation area - click to go back */}
      <div 
        className="page-navigation left-0" 
        onClick={() => handleScreenNavClick('left')}
        aria-label="Previous page"
      ></div>
      
      {/* Right side navigation area - click to go forward */}
      <div 
        className="page-navigation right-0" 
        onClick={() => handleScreenNavClick('right')}
        aria-label="Next page"
      ></div>
      
      {/* Comic page display */}
      <div className="max-w-4xl w-full h-[calc(100vh-8rem)] flex items-center justify-center py-8">
        <div className="relative max-h-full">
          {comicPages[currentPage] && (
            <img
              src={comicPages[currentPage]}
              alt={`Page ${currentPage + 1} of ${comic.title}`}
              className="max-h-full max-w-full object-contain mx-auto shadow-lg"
            />
          )}
        </div>
      </div>
      
      {/* Bottom controls */}
      <div className="reader-controls">
        <Button
          variant="outline"
          onClick={handlePreviousPage}
          disabled={currentPage === 0}
          className="bg-card"
        >
          <ArrowLeft className="mr-2 h-4 w-4" /> Previous
        </Button>
        
        <div className="text-sm">
          Page {currentPage + 1} of {comicPages.length}
        </div>
        
        <Button
          variant="outline"
          onClick={handleNextPage}
          disabled={currentPage === comicPages.length - 1}
          className="bg-card"
        >
          Next <ArrowRight className="ml-2 h-4 w-4" />
        </Button>
      </div>
    </div>
  );
}
