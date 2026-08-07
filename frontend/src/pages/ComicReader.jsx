import { useState, useEffect, useCallback, useRef } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button.jsx";
import { ArrowLeft, ArrowRight, Info, Maximize, ZoomIn, ZoomOut, RefreshCw } from "lucide-react";
import { useToast } from "@/hooks/use-toast.js";
import { Skeleton } from "@/components/ui/skeleton.jsx";
import { Progress } from "@/components/ui/progress.jsx";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { isTypingTarget } from "@/lib/keyboard";
import { parsePageNumber } from "@/lib/comic-progress";
import { toggleFullscreen } from "@/lib/fullscreen";
import { useComicLibrary } from "@/hooks/use-comic-library.jsx";

export default function ComicReader() {
  const { comicId } = useParams();
  const [comic, setComic] = useState(null);
  const [loadError, setLoadError] = useState(null);
  const [comicPages, setComicPages] = useState([]);
  const [currentPage, setCurrentPage] = useState(0);
  const [isLoading, setIsLoading] = useState(true); // For overall comic data
  const [isPageImageLoading, setIsPageImageLoading] = useState(true); // For individual page images
  const [imageLoadedSuccessfully, setImageLoadedSuccessfully] = useState(true); // To track if image loaded
  const [imageCache, setImageCache] = useState({});
  const [showDebug, setShowDebug] = useState(false); // For debug panel
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [isZoomed, setIsZoomed] = useState(false);
  const [zoomLevel, setZoomLevel] = useState(1);
  const [mousePosition, setMousePosition] = useState({ x: 0.5, y: 0.5 });
  const imageContainerRef = useRef(null);
  const pageInputRef = useRef(null);
  
  // Refs for async operations
  const progressAbortController = useRef(null);
  const currentPageRef = useRef(0); // Ref to track current page for async operations
  const loadQueueRef = useRef([]); // Queue of pages to load
  const isLoadingRef = useRef(false); // Flag to track if we're currently loading a page
  const isMountedRef = useRef(true); // Progress saves outlive the component; used to suppress late toasts
  const progressRevisionRef = useRef(0); // Orders progress saves that may reach the server out of order
  
  const navigate = useNavigate();
  const { toast } = useToast();
  const { updateComicProgress } = useComicLibrary();

  const CACHE_SIZE_FORWARD = 5;
  const CACHE_SIZE_BACKWARD = 5;

  const updateReadingProgress = useCallback(async (pageToSave) => {
    if (!comicId || !comic) return;

    // Supersede the previous save: only the latest page matters, so a rapid run
    // of page turns collapses to one request rather than a queue of them.
    if (progressAbortController.current) {
      progressAbortController.current.abort();
    }

    // Create a new AbortController for this request
    const controller = new AbortController();
    progressAbortController.current = controller;

    // Aborting only stops the browser waiting for the reply; a superseded save
    // may already be on its way to the server. The revision tells the server
    // which save is newer, and page numbers cannot: reading backwards is normal.
    const revision = ++progressRevisionRef.current;

    try {
      // keepalive lets the browser finish this request even if the reader is
      // being torn down (closing the tab, navigating away). Without it the
      // final page of a reading session is silently lost.
      const response = await api.post(
        `/api/comics/${comicId}/progress`,
        { currentPage: pageToSave, revision },
        { signal: controller.signal, keepalive: true }
      );

      const storedRevision = response?.progress?.revision;
      if (typeof storedRevision === 'number' && storedRevision > progressRevisionRef.current) {
        progressRevisionRef.current = storedRevision;
      }

      // Keep the library card in step with what was just stored, so going back
      // shows the new page straight away instead of after another /api/comics.
      if (response?.progress) {
        updateComicProgress(comicId, response.progress);
      }
    } catch (error) {
      // A superseded save is expected, not a failure
      if (error.name === 'AbortError' || controller.signal.aborted) return;

      // Handle network errors more gracefully
      if (error.name === 'TypeError' && error.message.includes('Failed to fetch')) {
        logger.warn("Network error when saving reading progress - will retry on next page change");
        return; // Don't show toast for network errors, as they're often transient
      }

      logger.error("Failed to save reading progress:", error);

      // The reader may already be gone by the time a save fails; a toast about
      // it would surface on whatever page the user moved on to.
      if (!isMountedRef.current) return;

      toast({
        title: "Error Saving Progress",
        description: error.message || "Could not save your reading progress. Please try again.",
        variant: "destructive",
      });
    } finally {
      // Only clear the ref if this controller is still the current one
      if (progressAbortController.current === controller) {
        progressAbortController.current = null;
      }
    }
  }, [comicId, comic, toast, updateComicProgress]);

  // Track mount state so an in-flight progress save that resolves after the
  // reader closes does not try to toast onto the next screen.
  useEffect(() => {
    isMountedRef.current = true;
    return () => {
      isMountedRef.current = false;
    };
  }, []);

  useEffect(() => {
    const loadComic = async () => {
      setIsLoading(true);
      setLoadError(null);
      try {
        const data = await api.get(`/api/comics/${comicId}`);
        setComic(data.comic);
        // Reset image loading states for the new comic
        setIsPageImageLoading(true);
        setImageLoadedSuccessfully(false);

        if (data.comic && data.comic.pageCount > 0) {
          setComicPages(
            Array.from({ length: data.comic.pageCount }, (_, i) => `/api/comics/${comicId}/pages/${i + 1}`)
          );
          // Continue the server's revision sequence, otherwise a reopened
          // reader would start below the stored value and every save would
          // look stale.
          progressRevisionRef.current = data.comic.readingProgress?.revision || 0;

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
        logger.error("Failed to load comic:", error);
        setLoadError(error);
        // A 404 is not retryable and the inline panel below already spells it
        // out, so it gets no toast at all — telling the user to "try again"
        // sends them back for a comic that will never be there.
        if (error.status !== 404) {
          toast({
            title: "Error loading comic",
            description: error.status >= 500
              ? "The server had a problem loading this comic. Please try again in a moment."
              : "There was a problem loading the comic. Please try again.",
            variant: "destructive",
          });
        }
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
      // The plain page URL, deliberately: the endpoint is cacheable, so asking
      // for it again is answered by the browser without touching the network.
      // A cache-busting parameter here would make every page a fresh download.
      const img = new Image();
      const url = comicPages[pageIndex];

      img.onload = () => {
        // Only update cache if this page is still in the cache window
        if (isInCacheWindow(pageIndex)) {
          setImageCache(prev => ({ ...prev, [pageIndex]: img }));
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
      const startPage = Math.max(0, currentPageRef.current - CACHE_SIZE_BACKWARD);
      const endPage = Math.min(comicPages.length - 1, currentPageRef.current + CACHE_SIZE_FORWARD);

      const stale = Object.keys(prev).filter(key => {
        const pageKey = parseInt(key, 10);
        return pageKey < startPage || pageKey > endPage;
      });

      // Returning a new object when nothing was evicted would change the cache
      // identity, re-run the effect that schedules this cleanup, and spin the
      // component in a permanent 2-second loop. Keep the same reference instead.
      if (stale.length === 0) return prev;

      const newCache = { ...prev };
      stale.forEach(key => delete newCache[key]);
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
    }, 2000); // Delay cleanup to avoid unnecessary operations

    return () => {
      clearTimeout(cleanupTimer);
    };
  }, [currentPage, comicPages, imageCache, queuePagesToLoad, cleanupCache, loadPageIntoCache]);



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

    // Deliberately no cleanup here. Aborting on unmount would cancel the save
    // for the page the user just finished on, losing it; and aborting when
    // currentPage changes is unnecessary because updateReadingProgress already
    // supersedes its own previous request.
  }, [currentPage, comic, comicId, comicPages.length, updateReadingProgress]);

  // Move by one page, priming the loading state from the cache so an already
  // cached page renders without a skeleton flash.
  const goToPage = useCallback((newPage) => {
    if (newPage < 0 || newPage > comicPages.length - 1) return;

    const cachedImage = imageCache[newPage];
    const isCached = cachedImage && cachedImage !== 'loading' && cachedImage !== 'failed';
    setIsPageImageLoading(!isCached);
    setImageLoadedSuccessfully(Boolean(isCached));

    setCurrentPage(newPage);
  }, [imageCache, comicPages.length]);

  const handlePreviousPage = useCallback(() => {
    goToPage(currentPage - 1);
  }, [goToPage, currentPage]);

  const handleNextPage = useCallback(() => {
    goToPage(currentPage + 1);
  }, [goToPage, currentPage]);

  // The jump-to-page box holds raw text, not a page number: it has to survive
  // the empty and half-typed states an input passes through. It is reconciled
  // with the reader whenever the page changes by any other means.
  const [pageInput, setPageInput] = useState("1");

  useEffect(() => {
    setPageInput(String(currentPage + 1));
  }, [currentPage]);

  const commitPageInput = useCallback(() => {
    const requestedPage = parsePageNumber(pageInput, comicPages.length);

    if (requestedPage === null) {
      setPageInput(String(currentPageRef.current + 1));
      return;
    }

    // Echo the clamped value back, so typing 500 in a 40-page comic settles on
    // 40 rather than leaving a number that does not match the page shown.
    setPageInput(String(requestedPage + 1));
    if (requestedPage !== currentPageRef.current) {
      goToPage(requestedPage);
    }
  }, [pageInput, comicPages.length, goToPage]);

  // Force a page to come from the server again, bypassing the browser cache.
  // A unique URL is what does the bypassing: the page endpoint is cacheable, so
  // re-requesting the plain URL would simply be answered locally.
  const handleForceReload = useCallback(() => {
    if (comicPages.length === 0 || currentPage < 0 || currentPage >= comicPages.length) {
      return;
    }

    const pageToReload = currentPage;

    toast({
      title: "Reloading page",
      description: `Forcing reload of page ${pageToReload + 1}`,
    });

    setImageCache(prevCache => {
      const newCache = { ...prevCache };
      delete newCache[pageToReload];
      return newCache;
    });
    setIsPageImageLoading(true);
    setImageLoadedSuccessfully(false);

    const img = new Image();
    let settleForcedLoad = () => {};
    let failForcedLoad = () => {};

    img.onload = () => {
      delete loadingPagesRef.current[pageToReload];
      setImageCache(prev => ({ ...prev, [pageToReload]: img }));
      settleForcedLoad(img);

      // The reader may have moved on while this was loading; the cache above is
      // still worth keeping, but the loading state belongs to another page now.
      if (currentPageRef.current !== pageToReload) return;

      setIsPageImageLoading(false);
      setImageLoadedSuccessfully(true);
      toast({
        title: "Page reloaded",
        description: `Successfully reloaded page ${pageToReload + 1}`,
        variant: "success",
      });
    };

    img.onerror = () => {
      logger.error("Failed to reload image");
      delete loadingPagesRef.current[pageToReload];
      failForcedLoad();
      if (currentPageRef.current !== pageToReload) return;

      setIsPageImageLoading(false);
      setImageLoadedSuccessfully(false);
      toast({
        title: "Reload failed",
        description: "Could not reload the page. Please try again later.",
        variant: "destructive",
      });
    };

    // Dropping the page from the cache above leaves the loading effect wanting
    // it back, and it would ask for the plain URL - the browser-cached copy
    // this reload exists to get past. Publishing the forced load through the
    // tracker every other loader already consults hands that effect this
    // request instead: no second download, and no stale image that can land
    // last and take the cache entry back.
    const forcedLoad = new Promise((resolve, reject) => {
      settleForcedLoad = resolve;
      failForcedLoad = reject;
    });
    // A caller is not guaranteed; without this a failed reload would surface as
    // an unhandled rejection on top of the toast that already reports it.
    forcedLoad.catch(() => {});
    loadingPagesRef.current[pageToReload] = forcedLoad;

    img.src = `${comicPages[pageToReload]}?_force_reload=${Date.now()}`;
  }, [comicPages, currentPage, toast]);

  const handleScreenNavClick = (direction) => {
    if (direction === 'left') {
      handlePreviousPage();
    } else {
      handleNextPage();
    }
  };

  // One listener on window covers both normal and fullscreen mode, since the
  // reader keeps focus in the document either way.
  useEffect(() => {
    const handleKeyPress = (event) => {
      // Arrow keys belong to the jump-to-page box while it has focus; turning
      // the page under someone editing a page number would be maddening.
      if (isTypingTarget(event.target)) return;

      switch (event.key) {
        case "ArrowLeft":
          handlePreviousPage();
          break;
        case "ArrowRight":
          handleNextPage();
          break;
        case "Home":
          event.preventDefault();
          goToPage(0);
          break;
        case "End":
          event.preventDefault();
          goToPage(comicPages.length - 1);
          break;
        default:
          break;
      }
    };

    window.addEventListener("keydown", handleKeyPress);
    return () => window.removeEventListener("keydown", handleKeyPress);
  }, [handlePreviousPage, handleNextPage, goToPage, comicPages.length]);

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
    const isMissing = loadError?.status === 404;
    return (
      <div className="min-h-screen flex flex-col justify-center items-center bg-background px-4 text-center">
        <p className="text-xl mb-2">{isMissing ? "Comic not found" : "Could not load this comic"}</p>
        <p className="text-sm text-muted-foreground mb-4">
          {isMissing
            ? "This comic may have been deleted, or the link is wrong."
            : "Something went wrong on the way to this comic. Please try again in a moment."}
        </p>
        <Button onClick={() => navigate("/dashboard")}>Return to Library</Button>
      </div>
    );
  }

  return (
    <div className="min-h-screen flex flex-col items-center bg-background overflow-hidden">
      {/* Navigation areas for clicking left/right sides of screen */}
      <div 
        className={`page-navigation left-0 ${isFullscreen ? 'z-[55]' : ''}`}
        style={{ bottom: '88px' }} // Leave space for controls to prevent overlap
        onClick={() => handleScreenNavClick('left')}
        aria-label="Previous page"
      ></div>
      
      <div 
        className={`page-navigation right-0 ${isFullscreen ? 'z-[55]' : ''}`}
        style={{ bottom: '88px' }} // Leave space for controls to prevent overlap
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
              onClick={() => toggleFullscreen(document)}
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
              onClick={() => setShowDebug(!showDebug)}
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
              <p>Cache window: {Math.max(0, currentPage - CACHE_SIZE_BACKWARD) + 1} - {Math.min(comicPages.length - 1, currentPage + CACHE_SIZE_FORWARD) + 1}</p>
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
                        {/* Escape sequences, not literals: these icons went through a bad
                            re-encoding once and came back as mojibake. */}
                        {imageCache[pageNum] === 'loading' ? '\u{1F504} Loading' :
                         imageCache[pageNum] === 'failed' ? '\u274C Failed' : '\u2705 Loaded'}
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
        {/* How far through the comic this page is, at a glance */}
        {comicPages.length > 0 && (
          <Progress
            value={((currentPage + 1) / comicPages.length) * 100}
            aria-label={`Page ${currentPage + 1} of ${comicPages.length}`}
            className="h-1 w-full rounded-none bg-muted/60"
          />
        )}

        <div className="flex w-full items-center justify-between gap-2">
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
            <form
              className="flex items-center gap-1.5 text-sm"
              onSubmit={(event) => {
                event.preventDefault();
                commitPageInput();
                pageInputRef.current?.blur();
              }}
            >
              <label htmlFor="reader-page-input" className="sr-only">Go to page</label>
              <input
                id="reader-page-input"
                ref={pageInputRef}
                type="number"
                inputMode="numeric"
                min={1}
                max={comicPages.length || 1}
                value={pageInput}
                onChange={(event) => setPageInput(event.target.value)}
                onBlur={commitPageInput}
                disabled={comicPages.length === 0}
                title="Go to page"
                className="h-8 w-16 rounded-md border border-input bg-background px-2 text-center text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:opacity-50"
              />
              <span className="whitespace-nowrap">of {comicPages.length}</span>
            </form>
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
    </div>
  );
}
