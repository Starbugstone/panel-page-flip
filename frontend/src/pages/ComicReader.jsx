import { useState, useEffect } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button.jsx";
import { mockComics, generateComicPages } from "@/lib/mockData.js";
import { ArrowLeft, ArrowRight } from "lucide-react";
import { useToast } from "@/hooks/use-toast.js";
import { Skeleton } from "@/components/ui/skeleton.jsx";

export default function ComicReader() {
  const { comicId } = useParams();
  const [comic, setComic] = useState(null);
  const [comicPages, setComicPages] = useState([]);
  const [currentPage, setCurrentPage] = useState(0);
  const [isLoading, setIsLoading] = useState(true);
  const navigate = useNavigate();
  const { toast } = useToast();

  useEffect(() => {
    const loadComic = async () => {
      try {
        await new Promise(resolve => setTimeout(resolve, 800));
        
        const foundComic = mockComics.find(c => c.id === comicId);
        
        if (foundComic) {
          setComic(foundComic);
          setComicPages(generateComicPages(comicId, foundComic.totalPages));
          
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

    if (comicId) {
        loadComic();
    } else {
        toast({
            title: "Error",
            description: "Comic ID is missing.",
            variant: "destructive",
        });
        navigate("/dashboard");
        setIsLoading(false);
    }
  }, [comicId, navigate, toast]);

  const handlePreviousPage = () => {
    if (currentPage > 0) {
      setCurrentPage(currentPage - 1);
    }
  };

  const handleNextPage = () => {
    if (currentPage < comicPages.length - 1) {
      setCurrentPage(currentPage + 1);
      console.log(`Updating reading progress: Page ${currentPage + 2} of ${comicPages.length}`);
    }
  };

  const handleScreenNavClick = (direction) => {
    if (direction === 'left') {
      handlePreviousPage();
    } else {
      handleNextPage();
    }
  };

  useEffect(() => {
    const handleKeyPress = (event) => {
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
  }, [currentPage, comicPages.length, handlePreviousPage, handleNextPage]);

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
      <div 
        className="page-navigation left-0" 
        onClick={() => handleScreenNavClick('left')}
        aria-label="Previous page"
      ></div>
      
      <div 
        className="page-navigation right-0" 
        onClick={() => handleScreenNavClick('right')}
        aria-label="Next page"
      ></div>
      
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
