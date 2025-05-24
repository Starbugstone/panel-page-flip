import { useState, useEffect, useCallback, useRef } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button.jsx";
// import { mockComics, generateComicPages } from "@/lib/mockData.js";
import { ArrowLeft, ArrowRight, Info } from "lucide-react";
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
  const [showDebug, setShowDebug] = useState(false); // For debug panel
  
  // Refs for async operations
  const progressAbortController = useRef(null);
  const currentPageRef = useRef(0); // Ref to track current page for async operations
  const loadQueueRef = useRef([]); // Queue of pages to load
  const isLoadingRef = useRef(false); // Flag to track if we're currently loading a page
  const cacheCleanupTimeoutRef = useRef(null); // Timeout for cache cleanup
  
  const navigate = useNavigate();
  const { toast } = useToast();

  const CACHE_SIZE_FORWARD = 5;
  const CACHE_SIZE_BACKWARD = 5;
  
  // Debug function to log cache state
  const logCacheState = useCallback(() => {
    console.log(`Cache state for page ${currentPage + 1}:`, 
      Object.keys(imageCache)
        .map(key => {
          const pageNum = parseInt(key, 10);
          const status = imageCache[pageNum] === 'loading' ? 'loading' : 
                        imageCache[pageNum] === 'failed' ? 'failed' : 'loaded';
          return { page: pageNum + 1, status };
        })
        .sort((a, b) => a.page - b.page)
    );
  }, [imageCache, currentPage]);

  const updateReadingProgress = useCallback(async (pageToSave) => {
    if (!comicId || !comic) return;

    // Abort any in-progress update
    if (progressAbortController.current) {
      progressAbortController.current.abort();
    }

    // Create a new AbortController for this request
    const controller = new AbortController();
    progressAbortController.current = controller;

    setIsSavingProgress(true);

    try {
      const response = await fetch(`/api/comics/${comicId}/progress`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ currentPage: pageToSave }),
        signal: controller.signal
      });

      // If this request was aborted, just return silently
      if (controller.signal.aborted) return;

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({ message: "Failed to save progress." }));
        throw new Error(errorData.message || "Server error saving progress.");
      }
      // Optional: toast({ title: "Progress Saved", description: `Page ${pageToSave} saved.` });
    } catch (error) {
      // Don't show errors for aborted requests
      if (error.name === 'AbortError') return;
      
      console.error("Failed to save reading progress:", error);
      toast({
        title: "Error Saving Progress",
        description: error.message || "Could not save your reading progress. Please try again.",
        variant: "destructive",
      });
    } finally {
      if (progressAbortController.current === controller) {
        setIsSavingProgress(false);
        progressAbortController.current = null;
      }
    }
  }, [comicId, comic, toast]);


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

  // Function to check if a page index is within the cache window
  const isInCacheWindow = useCallback((pageIndex) => {
    return pageIndex >= Math.max(0, currentPageRef.current - CACHE_SIZE_BACKWARD) && 
           pageIndex <= Math.min(comicPages.length - 1, currentPageRef.current + CACHE_SIZE_FORWARD);
  }, [comicPages.length]);

  // Function to convert an image to a data URL to avoid network requests
  const imageToDataURL = (img) => {
    // Create a canvas element
    const canvas = document.createElement('canvas');
    canvas.width = img.width;
    canvas.height = img.height;
    
    // Draw the image on the canvas
    const ctx = canvas.getContext('2d');
    ctx.drawImage(img, 0, 0);
    
    // Convert the canvas to a data URL
    return canvas.toDataURL('image/jpeg');
  };
  
  // Object to track in-progress loads to prevent duplicate requests
  const loadingPagesRef = useRef({});
  
  // Function to load a single page and add it to the cache
  const loadPageIntoCache = useCallback((pageIndex) => {
    if (pageIndex < 0 || pageIndex >= comicPages.length) return Promise.resolve(); // Out of bounds
    
    // If already fully loaded in cache, no need to load again
    if (imageCache[pageIndex] && imageCache[pageIndex] !== 'loading' && imageCache[pageIndex] !== 'failed') {
      return Promise.resolve();
    }
    
    // If this page is already being loaded, return the existing promise
    if (loadingPagesRef.current[pageIndex]) {
      console.log(`Page ${pageIndex + 1} is already being loaded, reusing existing request`);
      return loadingPagesRef.current[pageIndex];
    }
    
    // Mark as loading in the cache
    setImageCache(prev => ({
      ...prev,
      [pageIndex]: 'loading'
    }));
    
    // Create a new promise for this load
    const loadPromise = new Promise((resolve, reject) => {
      // Create a new image object with a unique timestamp to prevent browser caching
      const img = new Image();
      const url = `${comicPages[pageIndex]}?_t=${Date.now()}`;
      
      img.crossOrigin = 'Anonymous'; // Enable CORS for the canvas operations
      
      img.onload = () => {
        // Only update cache if this page is still in the cache window
        if (isInCacheWindow(pageIndex)) {
          try {
            // Convert the image to a data URL to prevent network requests
            const dataUrl = imageToDataURL(img);
            
            // Create a new image with the data URL
            const cachedImg = new Image();
            cachedImg.src = dataUrl;
            
            setImageCache(prev => ({
              ...prev,
              [pageIndex]: cachedImg
            }));
            console.log(`Page ${pageIndex + 1} loaded and cached as data URL`);
          } catch (error) {
            console.error(`Failed to create data URL for page ${pageIndex + 1}:`, error);
            // Fallback to using the original image
            setImageCache(prev => ({
              ...prev,
              [pageIndex]: img
            }));
            console.log(`Page ${pageIndex + 1} loaded and cached as image object (fallback)`);
          }
        }
        // Remove from loading tracker
        delete loadingPagesRef.current[pageIndex];
        resolve(img);
      };
      
      img.onerror = () => {
        console.error(`Failed to load page ${pageIndex + 1}`);
        setImageCache(prev => ({
          ...prev,
          [pageIndex]: 'failed'
        }));
        // Remove from loading tracker
        delete loadingPagesRef.current[pageIndex];
        reject();
      };
      
      // Set the source with the timestamp to prevent caching
      img.src = url;
    });
    
    // Store the promise in the loading tracker
    loadingPagesRef.current[pageIndex] = loadPromise;
    
    return loadPromise;
  }, [imageCache, comicPages, isInCacheWindow]);
  
  // Function to process the load queue
  const processLoadQueue = useCallback(() => {
    if (isLoadingRef.current || loadQueueRef.current.length === 0) return;
    
    isLoadingRef.current = true;
    const pageToLoad = loadQueueRef.current.shift();
    
    // Skip current page - it's handled separately
    if (pageToLoad === currentPageRef.current) {
      isLoadingRef.current = false;
      processLoadQueue();
      return;
    }
    
    loadPageIntoCache(pageToLoad)
      .catch(() => console.error(`Failed to load page ${pageToLoad + 1}`))
      .finally(() => {
        isLoadingRef.current = false;
        // Continue processing the queue
        processLoadQueue();
      });
  }, [loadPageIntoCache]);
  
  // Function to queue pages for loading in priority order
  const queuePagesToLoad = useCallback(() => {
    if (comicPages.length === 0) return;
    
    // Clear the current queue
    loadQueueRef.current = [];
    
    // Get the current page
    const currentPageIndex = currentPageRef.current;
    
    // Calculate range of pages to cache
    const startPage = Math.max(0, currentPageIndex - CACHE_SIZE_BACKWARD);
    const endPage = Math.min(comicPages.length - 1, currentPageIndex + CACHE_SIZE_FORWARD);
    
    // Priority 1: Next page
    if (currentPageIndex + 1 <= endPage && 
        (!imageCache[currentPageIndex + 1] || imageCache[currentPageIndex + 1] === 'failed')) {
      loadQueueRef.current.push(currentPageIndex + 1);
    }
    
    // Priority 2: Previous page
    if (currentPageIndex - 1 >= startPage && 
        (!imageCache[currentPageIndex - 1] || imageCache[currentPageIndex - 1] === 'failed')) {
      loadQueueRef.current.push(currentPageIndex - 1);
    }
    
    // Priority 3: Pages ahead of current
    for (let i = currentPageIndex + 2; i <= endPage; i++) {
      if (!imageCache[i] || imageCache[i] === 'failed') {
        loadQueueRef.current.push(i);
      }
    }
    
    // Priority 4: Pages before current
    for (let i = currentPageIndex - 2; i >= startPage; i--) {
      if (!imageCache[i] || imageCache[i] === 'failed') {
        loadQueueRef.current.push(i);
      }
    }
    
    // Start processing the queue if there are pages to load
    if (loadQueueRef.current.length > 0) {
      processLoadQueue();
    }
  }, [processLoadQueue, imageCache, comicPages.length]);
  
  // Function to clean up the cache (remove pages outside the window)
  const cleanupCache = useCallback(() => {
    setImageCache(prev => {
      const newCache = { ...prev };
      const startPage = Math.max(0, currentPageRef.current - CACHE_SIZE_BACKWARD);
      const endPage = Math.min(comicPages.length - 1, currentPageRef.current + CACHE_SIZE_FORWARD);
      
      // Remove pages outside the window
      Object.keys(newCache).forEach(key => {
        const pageKey = parseInt(key, 10);
        if (pageKey < startPage || pageKey > endPage) {
          delete newCache[pageKey];
        }
      });
      
      return newCache;
    });
  }, [comicPages.length]);
  
  // Effect to handle page changes and update UI state - only runs when page actually changes
  useEffect(() => {
    // Update the ref to ensure async operations have the latest value
    currentPageRef.current = currentPage;
    
    if (comicPages.length === 0) return;
    
    // Check if current page is available in cache
    const cachedImage = imageCache[currentPage];
    
    // Add a debug log to track network requests
    console.log(`Current page URL: ${comicPages[currentPage] || 'undefined'}`);
    console.log(`Cache status for page ${currentPage + 1}: ${cachedImage ? (cachedImage === 'loading' ? 'loading' : cachedImage === 'failed' ? 'failed' : 'cached') : 'not cached'}`);
    
    if (cachedImage && cachedImage !== 'loading' && cachedImage !== 'failed') {
      // Image is in cache and fully loaded - show immediately
      setIsPageImageLoading(false);
      setImageLoadedSuccessfully(true);
      console.log(`Page ${currentPage + 1} displayed from cache`);
      
      // Queue surrounding pages after a delay
      const queueTimer = setTimeout(() => {
        queuePagesToLoad();
      }, 100);
      
      return () => clearTimeout(queueTimer);
    } else if (cachedImage === 'failed') {
      // Image failed to load
      setIsPageImageLoading(false);
      setImageLoadedSuccessfully(false);
      console.log(`Page ${currentPage + 1} failed to load`);
    } else {
      // Not in cache or still loading - show loading state
      setIsPageImageLoading(true);
      setImageLoadedSuccessfully(false);
      console.log(`Page ${currentPage + 1} not in cache, loading from network`);
      
      // Use the optimized loading function to avoid duplicate requests
      loadPageIntoCache(currentPage)
        .then(() => {
          // Only update UI if this is still the current page
          if (currentPageRef.current === currentPage) {
            console.log(`Successfully loaded current page ${currentPage + 1}`);
            setIsPageImageLoading(false);
            setImageLoadedSuccessfully(true);
            
            // Queue surrounding pages after a delay
            setTimeout(() => {
              queuePagesToLoad();
            }, 100);
          }
        })
        .catch(() => {
          // Only update UI if this is still the current page
          if (currentPageRef.current === currentPage) {
            console.error(`Failed to load current page ${currentPage + 1}`);
            setIsPageImageLoading(false);
            setImageLoadedSuccessfully(false);
          }
        });
    }
    
    // Schedule cache cleanup after a delay
    const cleanupTimer = setTimeout(() => {
      cleanupCache();
      logCacheState();
    }, 2000); // Delay cleanup to avoid unnecessary operations
    
    return () => {
      clearTimeout(cleanupTimer);
    };
  // Include loadPageIntoCache but not imageCache to prevent infinite loop
  }, [currentPage, comicPages, queuePagesToLoad, cleanupCache, logCacheState, loadPageIntoCache]);



  // Effect to save reading progress when currentPage changes
  // We don't need to run this on every render, only when the page changes
  const lastSavedPage = useRef(null);
  
  useEffect(() => {
    // Only update if the page has actually changed and we have all the required data
    if (comic && comicId && typeof currentPage === 'number' && currentPage >= 0 && 
        comicPages.length > 0 && lastSavedPage.current !== currentPage) {
      // We add 1 because currentPage is 0-indexed, but backend expects 1-indexed.
      lastSavedPage.current = currentPage;
      updateReadingProgress(currentPage + 1);
    }
  }, [currentPage, comic, comicId, comicPages.length, updateReadingProgress]);

  const handlePreviousPage = useCallback(() => {
    if (currentPage > 0) {
      const newPage = currentPage - 1;
      console.log(`Navigating to previous page: ${currentPage + 1} -> ${newPage + 1}`);
      
      // Check if the page is already cached - if so, update UI immediately
      const cachedImage = imageCache[newPage];
      if (cachedImage && cachedImage !== 'loading' && cachedImage !== 'failed') {
        console.log(`Page ${newPage + 1} already in cache, displaying immediately`);
        // Update UI state before changing page to ensure immediate display
        setIsPageImageLoading(false);
        setImageLoadedSuccessfully(true);
      } else {
        // Not in cache, will need to load
        setIsPageImageLoading(true);
        setImageLoadedSuccessfully(false);
      }
      
      // Update page
      setCurrentPage(newPage);
    }
  }, [currentPage, imageCache]);
  
  const handleNextPage = useCallback(() => {
    if (currentPage < comicPages.length - 1) {
      const newPage = currentPage + 1;
      console.log(`Navigating to next page: ${currentPage + 1} -> ${newPage + 1}`);
      
      // Check if the page is already cached - if so, update UI immediately
      const cachedImage = imageCache[newPage];
      if (cachedImage && cachedImage !== 'loading' && cachedImage !== 'failed') {
        console.log(`Page ${newPage + 1} already in cache, displaying immediately`);
        // Update UI state before changing page to ensure immediate display
        setIsPageImageLoading(false);
        setImageLoadedSuccessfully(true);
      } else {
        // Not in cache, will need to load
        setIsPageImageLoading(true);
        setImageLoadedSuccessfully(false);
      }
      
      // Update page
      setCurrentPage(newPage);
    }
  }, [currentPage, imageCache, comicPages.length]);

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
          {comicPages.length > 0 && (
            <div className="relative w-full h-full flex items-center justify-center">
              {/* Show cached image immediately if available */}
              {imageCache[currentPage] && 
               imageCache[currentPage] !== 'loading' && 
               imageCache[currentPage] !== 'failed' && (
                <img
                  key={`cached-${currentPage}`}
                  src={imageCache[currentPage].src}
                  alt={`Page ${currentPage + 1} of ${comic?.title || 'Comic'}`}
                  className="max-h-full max-w-full object-contain mx-auto shadow-lg block"
                />
              )}
              
              {/* Show loading state only if we don't have a cached image */}
              {(!imageCache[currentPage] || imageCache[currentPage] === 'loading') && isPageImageLoading && (
                <div className="absolute inset-0 flex items-center justify-center">
                  <Skeleton className="w-full h-full max-w-full object-contain mx-auto" />
                </div>
              )}
              
              {/* Show error state */}
              {(!imageCache[currentPage] || imageCache[currentPage] === 'failed') && !isPageImageLoading && !imageLoadedSuccessfully && (
                <div className="flex flex-col items-center justify-center text-destructive p-4 bg-destructive-foreground rounded-md">
                  <p className="mb-2">Error loading page {currentPage + 1}.</p>
                  <Button
                    variant="outline"
                    onClick={() => {
                      // Retry logic: Clear from cache and set to loading to trigger reload
                      setImageCache(prevCache => {
                        const newCache = { ...prevCache };
                        delete newCache[currentPage];
                        return newCache;
                      });
                      setIsPageImageLoading(true);
                      setImageLoadedSuccessfully(false);
                    }}
                  >
                    Retry
                  </Button>
                </div>
              )}
              
              {/* No hidden loader - all loading is handled in the useEffect */}
              
              {/* Debug button */}
              <Button 
                variant="outline" 
                size="icon"
                className="absolute top-2 right-2 z-10 opacity-50 hover:opacity-100"
                onClick={() => {
                  setShowDebug(!showDebug);
                  logCacheState();
                }}
              >
                <Info className="h-4 w-4" />
              </Button>
              
              {/* Debug panel */}
              {showDebug && (
                <div className="absolute bottom-2 right-2 z-10 bg-card p-4 rounded-md shadow-lg max-w-xs max-h-60 overflow-auto text-xs">
                  <h3 className="font-bold mb-2">Debug Info</h3>
                  <p>Current page: {currentPage + 1}</p>
                  <p>Total pages: {comicPages.length}</p>
                  <p>Loading: {isPageImageLoading ? 'Yes' : 'No'}</p>
                  <p>Cached pages: {Object.keys(imageCache).length}</p>
                  <p>Cache window: {Math.max(0, currentPage - CACHE_SIZE_BACKWARD) + 1} - {Math.min(comicPages.length, currentPage + CACHE_SIZE_FORWARD) + 1}</p>
                  <div className="mt-2">
                    <p className="font-semibold">Cache status:</p>
                    <ul className="mt-1">
                      {Object.keys(imageCache)
                        .map(Number)
                        .sort((a, b) => a - b)
                        .map(pageNum => (
                          <li key={pageNum} className={pageNum === currentPage ? 'font-bold' : ''}>
                            Page {pageNum + 1}: {' '}
                            {imageCache[pageNum] === 'loading' ? '🔄 Loading' : 
                             imageCache[pageNum] === 'failed' ? '❌ Failed' : '✅ Loaded'}
                            {pageNum === currentPage ? ' (current)' : ''}
                          </li>
                        ))
                      }
                    </ul>
                  </div>
                  <div className="mt-2">
                    <p className="font-semibold">Queue status:</p>
                    <p>Pages to load: {loadQueueRef.current.length}</p>
                    {loadQueueRef.current.length > 0 && (
                      <p>Next in queue: {loadQueueRef.current[0] + 1}</p>
                    )}
                  </div>
                </div>
              )}
            </div>
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
