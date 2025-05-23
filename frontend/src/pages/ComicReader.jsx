import { useState, useEffect, useCallback } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button.jsx";
// import { mockComics, generateComicPages } from "@/lib/mockData.js";
import { ArrowLeft, ArrowRight } from "lucide-react";
import { useToast } from "@/hooks/use-toast.js";
import { Skeleton } from "@/components/ui/skeleton.jsx";

export default function ComicReader() {
  const { comicId } = useParams();
  const [comic, setComic] = useState(null);
  const [comicPages, setComicPages] = useState([]);
  const [currentPage, setCurrentPage] = useState(0);
  const [isLoading, setIsLoading] = useState(true); // For overall comic data
  const [isPageImageLoading, setIsPageImageLoading] = useState(true); // For individual page images
  const [imageLoadedSuccessfully, setImageLoadedSuccessfully] = useState(true); // To track if image loaded
  const [isSavingProgress, setIsSavingProgress] = useState(false); // For UI feedback on saving
  const [imageCache, setImageCache] = useState({});
  const navigate = useNavigate();
  const { toast } = useToast();

  const CACHE_SIZE_FORWARD = 3;
  const CACHE_SIZE_BACKWARD = 2;

  const updateReadingProgress = useCallback(async (pageToSave) => {
    if (!comicId || !comic || isSavingProgress) return;

    // console.log(`Attempting to save progress: Page ${pageToSave} for comic ${comicId}`);
    setIsSavingProgress(true);

    try {
      const response = await fetch(`/api/comics/${comicId}/progress`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ currentPage: pageToSave }),
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({ message: "Failed to save progress." }));
        throw new Error(errorData.message || "Server error saving progress.");
      }
      // Optional: toast({ title: "Progress Saved", description: `Page ${pageToSave} saved.` });
    } catch (error) {
      console.error("Failed to save reading progress:", error);
      toast({
        title: "Error Saving Progress",
        description: error.message || "Could not save your reading progress. Please try again.",
        variant: "destructive",
      });
    } finally {
      setIsSavingProgress(false);
    }
  }, [comicId, comic, toast, isSavingProgress]);


  useEffect(() => {
    const loadComic = async () => {
      setIsLoading(true);
      try {
        const response = await fetch(`/api/comics/${comicId}`);
        if (!response.ok) {
          const errorData = await response.json().catch(() => ({ message: "Failed to load comic details." }));
          toast({
            title: "Error loading comic",
            description: errorData.message || "The comic could not be loaded.",
            variant: "destructive",
          });
          navigate("/dashboard");
          return;
        }
        const data = await response.json();
        setComic(data.comic);
        // Reset image loading states for the new comic
        setIsPageImageLoading(true);
        setImageLoadedSuccessfully(false);

        if (data.comic && data.comic.pageCount > 0) {
          setComicPages(
            Array.from({ length: data.comic.pageCount }, (_, i) => `/api/comics/${comicId}/pages/${i + 1}`)
          );
          if (data.comic.readingProgress && data.comic.readingProgress.currentPage) {
            setCurrentPage(data.comic.readingProgress.currentPage - 1);
          } else {
            setCurrentPage(0); // Default to first page
          }
        } else {
          toast({
            title: "Comic has no pages", // Or "Comic data loaded, but no pages found"
            description: "This comic cannot be displayed as it has no pages.",
            variant: "destructive",
          });
          setComicPages([]);
          // Potentially navigate away or show a different message
        }

      } catch (error) {
        console.error("Failed to load comic:", error);
        toast({
          title: "Error loading comic",
          description: "There was a problem loading the comic. Please try again.",
          variant: "destructive",
        });
        // navigate("/dashboard"); // Optional: navigate away on general error
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

  // Effect to handle per-page image loading state based on JS imageCache
  useEffect(() => {
    if (comicPages.length > 0 && comicPages[currentPage]) {
      const cachedImage = imageCache[currentPage];
      if (cachedImage && cachedImage !== 'loading' && cachedImage !== 'failed' && cachedImage.complete) {
        // Image is in our JS cache and fully loaded (already decoded)
        setIsPageImageLoading(false);
        setImageLoadedSuccessfully(true);
      } else if (cachedImage === 'failed') {
        setIsPageImageLoading(false);
        setImageLoadedSuccessfully(false);
      } else {
        // Image not in JS cache, or is an Image object still loading, or just URL.
        // Rely on the <img> tag's onLoad/onError for these cases.
        // Ensure isPageImageLoading is true if we don't have it readily available.
        setIsPageImageLoading(true);
        setImageLoadedSuccessfully(false); // Will be set by img.onLoad or if cache hit occurs before render.
      }
    } else if (comicPages.length > 0 && !comicPages[currentPage]) {
        // Current page is out of bounds of comicPages (should not happen with proper navigation)
        setIsPageImageLoading(false);
        setImageLoadedSuccessfully(false);
        // console.warn("Current page index is out of bounds for comicPages array.");
    } else {
        // No comicPages available yet, or comicPages is empty
        setIsPageImageLoading(true); // Default to loading if no pages.
        setImageLoadedSuccessfully(false);
    }
  }, [currentPage, comicPages, imageCache]);

  // Effect for pre-loading images
  useEffect(() => {
    if (!comicPages || comicPages.length === 0) return;

    const preloadPage = (pageIndex) => {
      if (pageIndex < 0 || pageIndex >= comicPages.length) return; // Out of bounds

      // Check cache: if it's an Image object, or 'loading', or 'failed', skip
      if (imageCache[pageIndex] && (imageCache[pageIndex] instanceof Image || imageCache[pageIndex] === 'loading' || imageCache[pageIndex] === 'failed')) {
        return;
      }

      // Mark as "being cached" to avoid re-fetching in quick succession
      setImageCache(prevCache => ({ ...prevCache, [pageIndex]: 'loading' }));
      
      const img = new Image();
      img.src = comicPages[pageIndex];
      img.onload = () => {
        setImageCache(prevCache => ({ ...prevCache, [pageIndex]: img }));
        // console.log(`Page ${pageIndex + 1} pre-loaded and cached.`);
      };
      img.onerror = () => {
        setImageCache(prevCache => ({ ...prevCache, [pageIndex]: 'failed' }));
        // console.error(`Failed to pre-load page ${pageIndex + 1}.`);
      };
    };

    // Preload forward
    for (let i = 1; i <= CACHE_SIZE_FORWARD; i++) {
      preloadPage(currentPage + i);
    }
    // Preload backward
    for (let i = 1; i <= CACHE_SIZE_BACKWARD; i++) {
      preloadPage(currentPage - i);
    }
    // Ensure current page is also in cache (could be missed if navigation is too fast or first load)
    // preloadPage(currentPage); // This might be redundant due to <img> onLoad, but can ensure cache consistency.

  }, [currentPage, comicPages, imageCache, CACHE_SIZE_FORWARD, CACHE_SIZE_BACKWARD]); // imageCache added as dependency

  // Effect to save reading progress when currentPage changes
  useEffect(() => {
    // Consider debouncing this for production to avoid rapid API calls.
    if (comic && comicId && typeof currentPage === 'number' && currentPage >= 0 && comicPages.length > 0) {
      // We add 1 because currentPage is 0-indexed, but backend expects 1-indexed.
      updateReadingProgress(currentPage + 1);
    }
  }, [currentPage, comic, comicId, comicPages.length, updateReadingProgress]);

  const handlePreviousPage = () => {
    if (currentPage > 0) {
      setCurrentPage(currentPage - 1);
    }
  };

  const handleNextPage = () => {
    if (currentPage < comicPages.length - 1) {
      setCurrentPage(currentPage + 1);
      // The useEffect hook watching currentPage will handle the progress update.
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
        <div className="relative max-h-full w-full h-full flex items-center justify-center">
          {isPageImageLoading && comicPages.length > 0 && comicPages[currentPage] && (
            <Skeleton className="w-full h-full max-w-full object-contain mx-auto" />
          )}
          {/* Error display for failed image load */}
          {!isPageImageLoading && !imageLoadedSuccessfully && comicPages.length > 0 && comicPages[currentPage] && (
            <div className="flex flex-col items-center justify-center text-destructive p-4 bg-destructive-foreground rounded-md">
              <p className="mb-2">Error loading page {currentPage + 1}.</p>
              <Button
                variant="outline"
                onClick={() => {
                  // Retry logic: Clear from cache and set to loading to trigger reload by main <img>
                  // And also ensure the preloader might pick it up again if needed.
                  setImageCache(prevCache => {
                    const newCache = { ...prevCache };
                    delete newCache[currentPage]; // Remove 'failed' or old Image object
                    return newCache;
                  });
                  setIsPageImageLoading(true);
                  setImageLoadedSuccessfully(false);
                  // The main img tag's src will attempt to reload.
                  // The preloader might also try again if it's in its range.
                }}
              >
                Retry
              </Button>
            </div>
          )}
          {/* Main image display */}
          {comicPages.length > 0 && comicPages[currentPage] && (
            <img
              key={comicPages[currentPage]} // Helps React differentiate if src string itself is identical but needs re-eval
              src={comicPages[currentPage]}
              alt={`Page ${currentPage + 1} of ${comic.title}`}
              className={`max-h-full max-w-full object-contain mx-auto shadow-lg ${
                isPageImageLoading || !imageLoadedSuccessfully ? 'hidden' : 'block'
              }`}
              onLoad={() => {
                setIsPageImageLoading(false);
                setImageLoadedSuccessfully(true);
                // Update JS cache if this image wasn't preloaded or was 'loading'
                if (!imageCache[currentPage] || imageCache[currentPage] === 'loading') {
                  const img = new Image();
                  img.src = comicPages[currentPage]; // Browser should serve from its cache
                  img.onload = () => { // Ensure it's fully loaded before storing the Image object
                     setImageCache(prevCache => ({ ...prevCache, [currentPage]: img }));
                  };
                  // No explicit img.onerror here as the main <img> tag's onError handles failure for the current page.
                }
              }}
              onError={() => {
                setIsPageImageLoading(false);
                setImageLoadedSuccessfully(false);
                // Also mark as failed in our JS cache
                setImageCache(prevCache => ({ ...prevCache, [currentPage]: 'failed' }));
                toast({
                  title: "Error loading page image",
                  description: `Could not load image for page ${currentPage + 1}.`,
                  variant: "destructive",
                });
              }}
            />
          )}
          {/* Case where there are no pages for the comic */}
          {comicPages.length === 0 && !isLoading && (
             <div className="text-xl">This comic has no pages to display.</div>
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
