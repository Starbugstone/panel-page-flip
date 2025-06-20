import { useState, useEffect, useCallback, useRef } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button.jsx";
// import { mockComics, generateComicPages } from "@/lib/mockData.js";
import { ArrowLeft, ArrowRight, Info, Maximize, ZoomIn, ZoomOut, RefreshCw } from "lucide-react";
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
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [isZoomed, setIsZoomed] = useState(false);
  const [zoomLevel, setZoomLevel] = useState(1);
  const [mousePosition, setMousePosition] = useState({ x: 0.5, y: 0.5 });
  const imageContainerRef = useRef(null);
  
  // Refs for async operations
  const progressAbortController = useRef(null);
  const reloadAbortController = useRef(null); // For aborting force reload operations
  const currentPageRef = useRef(0); // Ref to track current page for async operations
  const loadQueueRef = useRef([]); // Queue of pages to load
  const isLoadingRef = useRef(false); // Flag to track if we're currently loading a page
  const cacheCleanupTimeoutRef = useRef(null); // Timeout for cache cleanup
  
  const navigate = useNavigate();
  const { toast } = useToast();

  const CACHE_SIZE_FORWARD = 5;
  const CACHE_SIZE_BACKWARD = 5;
  
  // Debug function to log cache state - only used in debug panel
  const logCacheState = useCallback(() => {
    // Cache state info is displayed in the debug panel UI
    // No console logging needed
  }, []);

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
      // Check if component is still mounted before making the request
      if (controller.signal.aborted) {
        return;
      }
      
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
      // Don't show errors for aborted requests or network errors when component unmounts
      if (error.name === 'AbortError' || controller.signal.aborted) return;
      
      // Handle network errors more gracefully
      if (error.name === 'TypeError' && error.message.includes('Failed to fetch')) {
        console.warn("Network error when saving reading progress - will retry on next page change");
        return; // Don't show toast for network errors, as they're often transient
      }
      
      console.error("Failed to save reading progress:", error);
      toast({
        title: "Error Saving Progress",
        description: error.message || "Could not save your reading progress. Please try again.",
        variant: "destructive",
      });
    } finally {
      // Only update state if this controller is still the current one
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
          } catch (error) {
            // Error creating data URL, fallback to using the original image
            setImageCache(prev => ({
              ...prev,
              [pageIndex]: img
            }));
            // Fallback successful
          }
        }
        // Remove from loading tracker
        delete loadingPagesRef.current[pageIndex];
        resolve(img);
      };
      
      img.onerror = () => {
        // Update cache with failed status
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
        .catch(() => {/* Error handled in loadPageIntoCache */})
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
    
    if (cachedImage && cachedImage !== 'loading' && cachedImage !== 'failed') {
      // Image is in cache and fully loaded - show immediately
      setIsPageImageLoading(false);
      setImageLoadedSuccessfully(true);
      
      // Queue surrounding pages after a delay
      const queueTimer = setTimeout(() => {
        queuePagesToLoad();
      }, 100);
      
      return () => clearTimeout(queueTimer);
    } else if (cachedImage === 'failed') {
      // Image failed to load
      setIsPageImageLoading(false);
      setImageLoadedSuccessfully(false);
    } else {
      // Not in cache or still loading - show loading state
      setIsPageImageLoading(true);
      setImageLoadedSuccessfully(false);
      
      // Use the optimized loading function to avoid duplicate requests
      loadPageIntoCache(currentPage)
        .then(() => {
          // Only update UI if this is still the current page
          if (currentPageRef.current === currentPage) {
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
    
    // Cleanup function to abort any in-progress requests when component unmounts
    // or when dependencies change
    return () => {
      if (progressAbortController.current) {
        progressAbortController.current.abort();
        progressAbortController.current = null;
      }
    };
  }, [currentPage, comic, comicId, comicPages.length, updateReadingProgress]);

  const handlePreviousPage = useCallback(() => {
    if (currentPage > 0) {
      const newPage = currentPage - 1;
      
      // Check if the page is already cached - if so, update UI immediately
      const cachedImage = imageCache[newPage];
      if (cachedImage && cachedImage !== 'loading' && cachedImage !== 'failed') {
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
  
  // Function to force reload the current page from the server
  const handleForceReload = useCallback(() => {
    if (comicPages.length === 0 || currentPage < 0 || currentPage >= comicPages.length) {
      return;
    }
    
    // If there's already a reload in progress, abort it
    if (reloadAbortController.current) {
      reloadAbortController.current.abort();
      reloadAbortController.current = null;
    }
    
    // Create a new abort controller for this reload
    reloadAbortController.current = new AbortController();
    const signal = reloadAbortController.current.signal;
    
    // Store the current page to ensure we stay on it
    const pageToReload = currentPage;
    
    // Show toast to indicate reload is happening
    toast({
      title: "Reloading page",
      description: `Forcing reload of page ${pageToReload + 1}`,
    });
    
    // Clear the current page from cache
    setImageCache(prevCache => {
      const newCache = { ...prevCache };
      delete newCache[pageToReload]; // Remove from cache to force reload
      return newCache;
    });
    
    // Set loading states
    setIsPageImageLoading(true);
    setImageLoadedSuccessfully(false);
    
    // Use fetch with AbortController instead of Image directly
    const url = `${comicPages[pageToReload]}?_force_reload=${Date.now()}`;
    
    fetch(url, { signal })
      .then(response => {
        if (!response.ok) {
          throw new Error(`HTTP error! Status: ${response.status}`);
        }
        return response.blob();
      })
      .then(blob => {
        // Check if the operation was aborted
        if (signal.aborted) return;
        
        // Create a URL for the blob
        const blobUrl = URL.createObjectURL(blob);
        
        // Create an image from the blob URL
        const img = new Image();
        
        img.onload = () => {
          try {
            // Check if the operation was aborted
            if (signal.aborted) {
              URL.revokeObjectURL(blobUrl);
              return;
            }
            
            // Make sure we're still on the same page
            if (currentPage !== pageToReload) {
              // If page has changed, just update the cache but don't change UI
              setImageCache(prev => ({
                ...prev,
                [pageToReload]: img
              }));
              URL.revokeObjectURL(blobUrl);
              return;
            }
            
            // Convert to data URL to prevent network requests
            const canvas = document.createElement('canvas');
            canvas.width = img.width;
            canvas.height = img.height;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0);
            const dataUrl = canvas.toDataURL('image/jpeg');
            
            // Free the blob URL
            URL.revokeObjectURL(blobUrl);
            
            // Create a new image with the data URL
            const cachedImg = new Image();
            cachedImg.src = dataUrl;
            
            // Update cache with the new image
            setImageCache(prev => ({
              ...prev,
              [pageToReload]: cachedImg
            }));
            
            // Update UI state only if we're still on the same page
            setIsPageImageLoading(false);
            setImageLoadedSuccessfully(true);
            
            // Success toast
            toast({
              title: "Page reloaded",
              description: `Successfully reloaded page ${pageToReload + 1}`,
              variant: "success",
            });
            
            // Clear the abort controller reference
            reloadAbortController.current = null;
          } catch (error) {
            // Check if the operation was aborted
            if (signal.aborted) return;
            
            console.error("Error reloading page:", error);
            
            // Only show error if we're still on the same page
            if (currentPage === pageToReload) {
              toast({
                title: "Error reloading",
                description: "There was a problem reloading the page. Please try again.",
                variant: "destructive",
              });
              setIsPageImageLoading(false);
              setImageLoadedSuccessfully(false);
            }
            
            // Clear the abort controller reference
            reloadAbortController.current = null;
          }
        };
        
        img.onerror = () => {
          // Check if the operation was aborted
          if (signal.aborted) {
            URL.revokeObjectURL(blobUrl);
            return;
          }
          
          console.error("Failed to reload image");
          URL.revokeObjectURL(blobUrl);
          
          // Only show error if we're still on the same page
          if (currentPage === pageToReload) {
            toast({
              title: "Reload failed",
              description: "Could not reload the page. Please try again later.",
              variant: "destructive",
            });
            setIsPageImageLoading(false);
            setImageLoadedSuccessfully(false);
          }
          
          // Clear the abort controller reference
          reloadAbortController.current = null;
        };
        
        // Set the source to start loading
        img.src = blobUrl;
      })
      .catch(error => {
        // Check if the operation was aborted
        if (signal.aborted) return;
        
        // Handle fetch errors
        console.error("Fetch error:", error);
        
        // Only show error if we're still on the same page
        if (currentPage === pageToReload) {
          toast({
            title: "Reload failed",
            description: "Could not reload the page from server. Please try again later.",
            variant: "destructive",
          });
          setIsPageImageLoading(false);
          setImageLoadedSuccessfully(false);
        }
        
        // Clear the abort controller reference
        reloadAbortController.current = null;
      });
  }, [comicPages, currentPage, toast]);
  
  const handleNextPage = useCallback(() => {
    if (currentPage < comicPages.length - 1) {
      const newPage = currentPage + 1;
      
      // Check if the page is already cached - if so, update UI immediately
      const cachedImage = imageCache[newPage];
      if (cachedImage && cachedImage !== 'loading' && cachedImage !== 'failed') {
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

  // Handle fullscreen change events
  useEffect(() => {
    const handleFullscreenChange = () => {
      const isNowFullscreen = !!document.fullscreenElement;
      setIsFullscreen(isNowFullscreen);
      
      // If exiting fullscreen and currently zoomed, also exit zoom mode
      if (!isNowFullscreen && isZoomed) {
        setIsZoomed(false);
        setZoomLevel(1);
      }
    };
    
    document.addEventListener('fullscreenchange', handleFullscreenChange);
    return () => {
      document.removeEventListener('fullscreenchange', handleFullscreenChange);
    };
  }, [isZoomed]);
  
  // Ensure page navigation works in fullscreen mode
  useEffect(() => {
    const handleFullscreenKeyPress = (event) => {
      if (isFullscreen) {
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
      }
    };

    if (isFullscreen) {
      window.addEventListener("keydown", handleFullscreenKeyPress);
    }
    
    return () => {
      // Always remove the event listener on cleanup, even if isFullscreen changed
      window.removeEventListener("keydown", handleFullscreenKeyPress);
    };
  }, [isFullscreen, handlePreviousPage, handleNextPage]);
  
  // Handle zoom wheel events
  const handleWheel = useCallback((e) => {
    if (isZoomed) {
      // Prevent default to stop page scrolling
      e.preventDefault();
      
      // Adjust zoom level with mouse wheel
      const delta = e.deltaY * -0.01;
      const newZoomLevel = Math.max(1, Math.min(5, zoomLevel + delta));
      
      setZoomLevel(newZoomLevel);
    }
  }, [isZoomed, zoomLevel]);
  
  // Add wheel event listener when zoomed
  useEffect(() => {
    const container = imageContainerRef.current;
    if (container && isZoomed) {
      container.addEventListener('wheel', handleWheel, { passive: false });
    }
    
    return () => {
      if (container) {
        container.removeEventListener('wheel', handleWheel);
      }
    };
  }, [isZoomed, handleWheel]);

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
      {/* Navigation areas for clicking left/right sides of screen */}
      <div 
        className={`page-navigation left-0 ${isFullscreen ? 'z-[55]' : ''}`}
        style={{ bottom: '60px' }} // Leave space for controls to prevent overlap
        onClick={() => handleScreenNavClick('left')}
        aria-label="Previous page"
      ></div>
      
      <div 
        className={`page-navigation right-0 ${isFullscreen ? 'z-[55]' : ''}`}
        style={{ bottom: '60px' }} // Leave space for controls to prevent overlap
        onClick={() => handleScreenNavClick('right')}
        aria-label="Next page"
      ></div>
      
      {/* Main content area - adjusted height to account for the header in normal mode */}
      <div className={`max-w-4xl w-full ${isFullscreen ? 'h-[calc(100vh-8rem)]' : 'h-[calc(100vh-10rem)]'} flex items-center justify-center py-4`}>
        <div 
          ref={imageContainerRef}
          className={`relative max-h-full w-full h-full flex items-center justify-center ${isFullscreen ? 'fullscreen-container' : ''}`}
          onMouseMove={(e) => {
            if (isZoomed) {
              const rect = e.currentTarget.getBoundingClientRect();
              const x = (e.clientX - rect.left) / rect.width;
              const y = (e.clientY - rect.top) / rect.height;
              setMousePosition({ x, y });
            }
          }}
        >
          {/* Main image display */}
          {comicPages.length > 0 && imageCache[currentPage] && 
           imageCache[currentPage] !== 'loading' && 
           imageCache[currentPage] !== 'failed' && (
            <img
              key={`cached-${currentPage}`}
              src={imageCache[currentPage].src}
              alt={`Page ${currentPage + 1} of ${comic?.title || 'Comic'}`}
              className={`max-h-full max-w-full object-contain mx-auto shadow-lg block transition-transform ${isZoomed ? 'zoomed-image' : ''}`}
              style={{
                transform: isZoomed ? `scale(${zoomLevel})` : 'none',
                transformOrigin: isZoomed ? `${mousePosition.x * 100}% ${mousePosition.y * 100}%` : 'center center'
              }}
              onClick={() => {
                if (isZoomed) {
                  setIsZoomed(false);
                  setZoomLevel(1);
                }
              }}
            />
          )}
          {/* Error display for failed image load */}
          {!isPageImageLoading && !imageLoadedSuccessfully && comicPages.length > 0 && comicPages[currentPage] && (
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
          {/* Loading state only if we don't have a cached image */}
          {(!imageCache[currentPage] || imageCache[currentPage] === 'loading') && isPageImageLoading && (
            <div className="absolute inset-0 flex items-center justify-center">
              <Skeleton className="w-full h-full max-w-full object-contain mx-auto" />
            </div>
          )}
          {/* Control buttons - positioned differently in fullscreen mode */}
          <div className={isFullscreen ? "fullscreen-controls" : "absolute top-2 right-2 z-10 flex gap-2"}>
            <Button 
              variant="outline" 
              size="icon"
              className="opacity-80 hover:opacity-100 bg-card/80"
              onClick={() => {
                if (isFullscreen) {
                  document.exitFullscreen();
                } else if (imageContainerRef.current) {
                  imageContainerRef.current.requestFullscreen();
                }
              }}
              title="Toggle fullscreen"
            >
              <Maximize className="h-4 w-4" />
            </Button>
            
            {isZoomed ? (
              <Button 
                variant="outline" 
                size="icon"
                className="opacity-80 hover:opacity-100 bg-card/80"
                onClick={() => {
                  setIsZoomed(false);
                  setZoomLevel(1);
                }}
                title="Zoom out"
              >
                <ZoomOut className="h-4 w-4" />
              </Button>
            ) : (
              <Button 
                variant="outline" 
                size="icon"
                className="opacity-80 hover:opacity-100 bg-card/80"
                onClick={() => {
                  setIsZoomed(true);
                  setZoomLevel(2);
                }}
                title="Zoom in"
              >
                <ZoomIn className="h-4 w-4" />
              </Button>
            )}
            
            {/* Page navigation buttons in fullscreen mode */}
            {isFullscreen && (
              <>
                <Button
                  variant="outline"
                  size="icon"
                  className="opacity-80 hover:opacity-100 bg-card/80"
                  onClick={handlePreviousPage}
                  disabled={currentPage === 0}
                  title="Previous page"
                >
                  <ArrowLeft className="h-4 w-4" />
                </Button>
                
                <Button
                  variant="outline"
                  size="icon"
                  className="opacity-80 hover:opacity-100 bg-card/80"
                  onClick={handleNextPage}
                  disabled={currentPage === comicPages.length - 1}
                  title="Next page"
                >
                  <ArrowRight className="h-4 w-4" />
                </Button>
              </>
            )}
            
            {/* Debug button */}
            <Button 
              variant="outline" 
              size="icon"
              className="opacity-80 hover:opacity-100 bg-card/80"
              onClick={() => {
                setShowDebug(!showDebug);
                logCacheState();
              }}
              title="Debug info"
            >
              <Info className="h-4 w-4" />
            </Button>
          </div>
          {/* Debug panel */}
          {showDebug && (
            <div className="absolute bottom-2 right-2 z-10 bg-card p-4 rounded-md shadow-lg max-w-xs max-h-60 overflow-auto text-xs">
              <h3 className="font-bold mb-2">Debug Info</h3>
              <p>Current page: {currentPage + 1}</p>
              <p>Total pages: {comicPages.length}</p>
              <p>Loading: {isPageImageLoading ? 'Yes' : 'No'}</p>
              <p>Cached pages: {Object.keys(imageCache).length}</p>
              <p>Cache window: {Math.max(0, currentPage - CACHE_SIZE_BACKWARD) + 1} - {Math.min(comicPages.length, currentPage + CACHE_SIZE_FORWARD) + 1}</p>
              {isZoomed && (
                <p>Zoom level: {Math.round(zoomLevel * 100)}%</p>
              )}
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
          {/* Case where there are no pages for the comic */}
          {comicPages.length === 0 && !isLoading && (
             <div className="text-xl">This comic has no pages to display.</div>
          )}
        </div>
      </div>
      
      {/* Reader controls - different styling in fullscreen mode */}
      <div className={isFullscreen ? "reader-controls-fullscreen" : "reader-controls"}>
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            onClick={handlePreviousPage}
            disabled={currentPage === 0}
            className={isFullscreen ? "" : "bg-card"}
          >
            <ArrowLeft className="mr-2 h-4 w-4" /> Previous
          </Button>
          
          {/* Force reload button */}
          <Button
            variant="outline"
            size="icon"
            onClick={handleForceReload}
            title="Force reload current page"
            className={isFullscreen ? "" : "bg-card"}
          >
            <RefreshCw className="h-4 w-4" />
          </Button>
        </div>
        
        <div className="flex items-center gap-2">
          <div className="text-sm">
            Page {currentPage + 1} of {comicPages.length}
          </div>
          {isZoomed && (
            <div className="text-xs bg-primary/20 px-2 py-1 rounded">
              {Math.round(zoomLevel * 100)}% zoom
            </div>
          )}
        </div>
        
        <Button
          variant="outline"
          onClick={handleNextPage}
          disabled={currentPage === comicPages.length - 1}
          className={isFullscreen ? "" : "bg-card"}
        >
          Next <ArrowRight className="ml-2 h-4 w-4" />
        </Button>
      </div>
    </div>
  );
}
