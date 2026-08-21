import { useState, useEffect, useCallback, useMemo, useRef } from "react";
import { useParams, useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button.jsx";
import { ArrowLeft, ArrowRight, Info, LayoutGrid, Maximize, ZoomIn, ZoomOut, RefreshCw } from "lucide-react";
import { useToast } from "@/hooks/use-toast.js";
import { Skeleton } from "@/components/ui/skeleton.jsx";
import { Progress } from "@/components/ui/progress.jsx";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { isTypingTarget } from "@/lib/keyboard";
import { parsePageNumber } from "@/lib/comic-progress";
import { toggleFullscreen } from "@/lib/fullscreen";
import { useComicLibrary } from "@/hooks/use-comic-library.jsx";
import { useReaderNavigation } from "@/hooks/use-reader-navigation";
import { useReaderPreferences } from "@/hooks/use-reader-preferences.jsx";
import { useReaderWakeLock } from "@/hooks/use-reader-wake-lock";
import { useReaderChrome } from "@/hooks/use-reader-chrome";
import { useReaderTransform } from "@/hooks/use-reader-transform";
import { useViewportProfile } from "@/hooks/use-viewport-profile";
import { usePageVariant } from "@/hooks/use-page-variant";
import { usePageGeometry } from "@/hooks/use-page-geometry";
import { createComicPageUrls, withForcedReload } from "@/lib/reader-pages";
import { tapZone } from "@/lib/reader-gestures";
import { preloadWindowFor, readNetworkHints } from "@/lib/reader-preload";
import {
  OVERRIDABLE_SETTINGS,
  READER_FITS,
  effectiveReaderSettings,
  hasReaderOverride,
} from "@/lib/reader-preferences";
import { describeViewportContext, suggestedFitFor, viewportContextKey } from "@/lib/reader-viewport";
import { ReaderFitSuggestion } from "@/components/reader/ReaderFitSuggestion";
import { ReaderSettings } from "@/components/reader/ReaderSettings";
import { ReaderThumbnailStrip } from "@/components/reader/ReaderThumbnailStrip";
import { SinglePageReader } from "@/components/reader/SinglePageReader";

// How far the page follows a finger that is mid-swipe. Not one-to-one: there is
// no next page rendered behind it to slide in, so a full-width drag would pull
// the artwork off a blank screen.
const SWIPE_FOLLOW = 0.35;

export default function ComicReader() {
  const { comicId } = useParams();
  const [comic, setComic] = useState(null);
  const [loadError, setLoadError] = useState(null);
  const [pageCount, setPageCount] = useState(0);
  const [isFetchingComic, setIsFetchingComic] = useState(true); // For overall comic data
  const [imageCache, setImageCache] = useState({});
  const [showDebug, setShowDebug] = useState(false); // For debug panel
  const [showThumbnails, setShowThumbnails] = useState(false);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [swipeOffset, setSwipeOffset] = useState(0);
  const [isSwiping, setIsSwiping] = useState(false);
  const [isSettingsOpen, setIsSettingsOpen] = useState(false);
  const [isControlFocused, setIsControlFocused] = useState(false);
  const [dismissedSuggestions, setDismissedSuggestions] = useState({});
  const imageContainerRef = useRef(null);
  const imageRef = useRef(null);
  const pageInputRef = useRef(null);
  const navigate = useNavigate();
  const { toast } = useToast();
  const { updateComicProgress } = useComicLibrary();
  // What kind of screen this is being read on. Everything device-shaped below —
  // how far to preload, which fit suits, whether taps have to stand in for
  // hover — comes from here rather than from a user-agent string.
  const profile = useViewportProfile();
  const viewportContext = useMemo(
    () => ({ device: profile.device, orientation: profile.orientation }),
    [profile.device, profile.orientation]
  );
  const {
    transform,
    isZoomed,
    pinch,
    pan,
    doubleTapAt,
    stepZoomBy,
    zoomToFit,
    resetTransform,
  } = useReaderTransform({ containerRef: imageContainerRef, imageRef });
  // Which size of page this screen is worth asking for. Zoom raises it a rung;
  // nothing here ever reaches for the source scan.
  const pageVariant = usePageVariant(imageContainerRef, { zoomLevel: transform.scale });
  const comicPages = useMemo(
    () => createComicPageUrls(comicId, pageCount, pageVariant),
    [comicId, pageCount, pageVariant]
  );
  const {
    currentPage,
    currentPageRef,
    goToPage,
    goPrevious: handlePreviousPage,
    goNext: handleNextPage,
    resetPage,
    canGoPrevious,
    canGoNext,
  } = useReaderNavigation(comicPages.length);
  const {
    preferences,
    isLoaded: arePreferencesLoaded,
    isSaving: arePreferencesSaving,
    changeSettings,
    changeOverride,
    clearOverride,
    resetPreferences,
  } = useReaderPreferences(toast);
  // The account's settings, with anything this device and orientation has been
  // told for itself laid over them.
  const settings = useMemo(
    () => effectiveReaderSettings(preferences, viewportContext),
    [preferences, viewportContext]
  );
  const hasContextOverride = hasReaderOverride(preferences, viewportContext);
  useReaderWakeLock(settings.wakeLock);
  const { geometry: pageGeometry } = usePageGeometry(comicId, pageCount, currentPage);

  // Refs for async operations
  const progressAbortController = useRef(null);
  const loadQueueRef = useRef([]); // Queue of pages to load
  const isLoadingRef = useRef(false); // Flag to track if we're currently loading a page
  const isMountedRef = useRef(true); // Progress saves outlive the component; used to suppress late toasts
  const progressRevisionRef = useRef(0); // Orders progress saves that may reach the server out of order
  // Which variant each cached page was fetched at. A page is only "there" if it
  // is there at the size currently being asked for; after an upgrade the old
  // image stays on screen and is replaced when the larger one arrives.
  const loadedVariantsRef = useRef({});

  // How many pages either side of this one are worth holding decoded, decided
  // once from what this device can afford rather than from a constant that has
  // to suit both a desktop and a phone on a train.
  const preloadWindow = useMemo(() => preloadWindowFor(profile, readNetworkHints()), [profile]);

  // The cache entry for the current page already says everything these used to
  // be told: a value means it is ready, 'failed' means it is not coming, and
  // anything else means it is still on its way. Keeping them as separate state
  // meant every path that touched the cache had to remember to set them too,
  // and a missed one left a spinner over a page that had already arrived.
  const currentPageImage = imageCache[currentPage];
  const isPageImageLoading = comicPages.length > 0
    && (!currentPageImage || currentPageImage === 'loading');
  const imageLoadedSuccessfully = Boolean(currentPageImage)
    && currentPageImage !== 'loading'
    && currentPageImage !== 'failed';
  // Without a comic id the reader redirects instead of loading anything, so it
  // is not waiting for a request that was never made.
  const isLoading = Boolean(comicId) && isFetchingComic;

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

  // The debug panel wants to show the load queue, which lives in a ref because
  // the loader mutates it constantly and none of that should cause a render.
  // Sampling it on a timer while the panel is open keeps it out of the render
  // path entirely, and costs nothing when the panel is closed.
  const [queueSnapshot, setQueueSnapshot] = useState([]);
  useEffect(() => {
    if (!showDebug) return undefined;
    const id = setInterval(() => setQueueSnapshot([...loadQueueRef.current]), 250);
    return () => clearInterval(id);
  }, [showDebug]);

  // Track mount state so an in-flight progress save that resolves after the
  // reader closes does not try to toast onto the next screen.
  useEffect(() => {
    isMountedRef.current = true;
    return () => {
      isMountedRef.current = false;
    };
  }, []);

  useEffect(() => {
    // The route param can change without remounting, so the previous comic has
    // to be cleared and its in-flight request disowned. Otherwise a reader that
    // moves from a comic that loaded to one that 404s keeps rendering the first
    // one's pages under the second one's id — and saves progress against it.
    let active = true;

    const loadComic = async () => {
      setIsFetchingComic(true);
      setLoadError(null);
      setComic(null);
      setPageCount(0);
      resetPage(0, 0);
      setImageCache({});
      loadedVariantsRef.current = {};
      try {
        const data = await api.get(`/api/comics/${comicId}`);
        if (!active) return;
        setComic(data.comic);

        if (data.comic && data.comic.pageCount > 0) {
          setPageCount(data.comic.pageCount);
          // Continue the server's revision sequence, otherwise a reopened
          // reader would start below the stored value and every save would
          // look stale.
          progressRevisionRef.current = data.comic.readingProgress?.revision || 0;

          if (data.comic.readingProgress && data.comic.readingProgress.currentPage) {
            resetPage(data.comic.readingProgress.currentPage - 1, data.comic.pageCount);
          } else {
            resetPage(0, data.comic.pageCount);
          }
        } else {
          toast({
            title: "Comic has no pages", // Or "Comic data loaded, but no pages found"
            description: "This comic cannot be displayed as it has no pages.",
            variant: "destructive",
          });
          setPageCount(0);
          // Potentially navigate away or show a different message
        }

      } catch (error) {
        if (!active) return;
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
        if (active) setIsFetchingComic(false);
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
    }

    return () => { active = false; };
  }, [comicId, navigate, resetPage, toast]);

  // Function to check if a page index is within the cache window
  const isInCacheWindow = useCallback((pageIndex) => {
    return pageIndex >= Math.max(0, currentPageRef.current - preloadWindow.backward) &&
           pageIndex <= Math.min(comicPages.length - 1, currentPageRef.current + preloadWindow.forward);
  }, [comicPages.length, currentPageRef, preloadWindow]);

  // Object to track in-progress loads to prevent duplicate requests
  const loadingPagesRef = useRef({});

  // A page counts as ready only at the size currently being asked for. After a
  // resize or a zoom, the image on screen is still shown — it is simply no
  // longer the one this reader wants, so it is fetched again in the background.
  const isPageReady = useCallback((pageIndex) => {
    const cached = imageCache[pageIndex];

    return Boolean(cached)
      && cached !== 'loading'
      && cached !== 'failed'
      && loadedVariantsRef.current[pageIndex] === pageVariant;
  }, [imageCache, pageVariant]);

  // Function to load a single page and add it to the cache
  const loadPageIntoCache = useCallback((pageIndex) => {
    if (pageIndex < 0 || pageIndex >= comicPages.length) return Promise.resolve(); // Out of bounds

    if (isPageReady(pageIndex)) return Promise.resolve();

    // A load already running for this exact size is the one to wait on. One
    // running for a smaller size is not: settling on it would hand back the
    // image the upgrade exists to replace.
    const inFlight = loadingPagesRef.current[pageIndex];
    if (inFlight && inFlight.variant === pageVariant) return inFlight.promise;

    const entry = { variant: pageVariant, promise: null };
    // Only the newest load for a page may write to the cache. A slow small
    // image landing after a fast large one would otherwise put the small one
    // back and the reader would never settle on the size it asked for.
    const isCurrentLoad = () => loadingPagesRef.current[pageIndex] === entry;

    // The request goes out now; saying so is a render, and one render inside
    // another's commit is what the loading effect would otherwise cause on
    // every page turn. Nothing waits on the flag - the image is already on its
    // way, and the tracker above is what stops a second request for it.
    queueMicrotask(() => {
      if (!isCurrentLoad()) return;

      setImageCache(prev => {
        const showing = prev[pageIndex];
        // Upgrading in place leaves the smaller page up: blanking the reader to
        // a skeleton because the window grew would be a worse picture, not a
        // better one.
        return showing && showing !== 'loading' && showing !== 'failed'
          ? prev
          : { ...prev, [pageIndex]: 'loading' };
      });
    });

    entry.promise = new Promise((resolve, reject) => {
      // The plain page URL, deliberately: the endpoint is cacheable, so asking
      // for it again is answered by the browser without touching the network.
      // A cache-busting parameter here would make every page a fresh download.
      const img = new Image();
      const url = comicPages[pageIndex];

      img.onload = () => {
        // Only update cache if this page is still in the cache window
        if (isCurrentLoad()) {
          if (isInCacheWindow(pageIndex)) {
            loadedVariantsRef.current[pageIndex] = entry.variant;
            setImageCache(prev => ({ ...prev, [pageIndex]: img }));
          }
          delete loadingPagesRef.current[pageIndex];
        }
        resolve(img);
      };

      img.onerror = () => {
        if (isCurrentLoad()) {
          // A failed upgrade keeps whatever is already on screen: the reader
          // can still read the page, just not at the larger size.
          setImageCache(prev => (
            prev[pageIndex] && prev[pageIndex] !== 'loading'
              ? prev
              : { ...prev, [pageIndex]: 'failed' }
          ));
          delete loadingPagesRef.current[pageIndex];
        }
        reject();
      };

      img.src = url;
    });

    // Store the promise in the loading tracker
    loadingPagesRef.current[pageIndex] = entry;

    return entry.promise;
  }, [comicPages, isInCacheWindow, isPageReady, pageVariant]);

  // Function to process the load queue
  //
  // The drain step is a local function rather than the callback calling itself.
  // Recursing through the useCallback binding means each step reaches for the
  // identity the closure was created with, which is not necessarily the one a
  // later render is using.
  const processLoadQueue = useCallback(() => {
    const drain = () => {
      if (isLoadingRef.current || loadQueueRef.current.length === 0) return;

      isLoadingRef.current = true;
      const pageToLoad = loadQueueRef.current.shift();

      // Skip current page - it's handled separately
      if (pageToLoad === currentPageRef.current) {
        isLoadingRef.current = false;
        drain();
        return;
      }

      loadPageIntoCache(pageToLoad)
        .catch(() => {/* Error handled in loadPageIntoCache */})
        .finally(() => {
          isLoadingRef.current = false;
          // Continue processing the queue
          drain();
        });
    };

    drain();
  }, [currentPageRef, loadPageIntoCache]);
  
  // Function to queue pages for loading in priority order
  const queuePagesToLoad = useCallback(() => {
    if (comicPages.length === 0) return;
    
    // Clear the current queue
    loadQueueRef.current = [];
    
    // Get the current page
    const currentPageIndex = currentPageRef.current;
    
    // Calculate range of pages to cache
    const startPage = Math.max(0, currentPageIndex - preloadWindow.backward);
    const endPage = Math.min(comicPages.length - 1, currentPageIndex + preloadWindow.forward);

    // A page already in flight is left alone; anything else that is not ready
    // at the current size is worth queueing, including one cached at a smaller
    // size that a resize has since outgrown.
    const shouldQueue = (pageIndex) => !isPageReady(pageIndex) && imageCache[pageIndex] !== 'loading';

    // Priority 1: Next page
    if (currentPageIndex + 1 <= endPage && shouldQueue(currentPageIndex + 1)) {
      loadQueueRef.current.push(currentPageIndex + 1);
    }

    // Priority 2: Previous page
    if (currentPageIndex - 1 >= startPage && shouldQueue(currentPageIndex - 1)) {
      loadQueueRef.current.push(currentPageIndex - 1);
    }

    // Priority 3: Pages ahead of current
    for (let i = currentPageIndex + 2; i <= endPage; i++) {
      if (shouldQueue(i)) {
        loadQueueRef.current.push(i);
      }
    }

    // Priority 4: Pages before current
    for (let i = currentPageIndex - 2; i >= startPage; i--) {
      if (shouldQueue(i)) {
        loadQueueRef.current.push(i);
      }
    }

    // Start processing the queue if there are pages to load
    if (loadQueueRef.current.length > 0) {
      processLoadQueue();
    }
  }, [processLoadQueue, imageCache, isPageReady, comicPages.length, currentPageRef, preloadWindow]);

  // Function to clean up the cache (remove pages outside the window)
  const cleanupCache = useCallback(() => {
    const startPage = Math.max(0, currentPageRef.current - preloadWindow.backward);
    const endPage = Math.min(comicPages.length - 1, currentPageRef.current + preloadWindow.forward);
    const isOutsideWindow = (pageIndex) => pageIndex < startPage || pageIndex > endPage;

    setImageCache(prev => {
      const stale = Object.keys(prev).filter(key => isOutsideWindow(parseInt(key, 10)));

      // Returning a new object when nothing was evicted would change the cache
      // identity, re-run the effect that schedules this cleanup, and spin the
      // component in a permanent 2-second loop. Keep the same reference instead.
      if (stale.length === 0) return prev;

      const newCache = { ...prev };
      stale.forEach(key => delete newCache[key]);
      return newCache;
    });

    // An evicted page has to forget what size it was, or coming back to it
    // would count as ready with nothing in the cache to show.
    Object.keys(loadedVariantsRef.current).forEach(key => {
      if (isOutsideWindow(parseInt(key, 10))) delete loadedVariantsRef.current[key];
    });
  }, [comicPages.length, currentPageRef, preloadWindow]);
  
  // Effect to handle page changes and update UI state - only runs when page actually changes
  useEffect(() => {
    if (comicPages.length === 0) return;
    
    // Check if current page is available in cache
    const cachedImage = imageCache[currentPage];
    
    let queueTimer;
    // The promise below can resolve after this effect has been cleaned up, and
    // the cleanup can only clear a timer that already exists. Without this flag
    // every page turn during a load leaves a timer behind that fires against a
    // page the reader has moved on from.
    let cancelled = false;
    if (isPageReady(currentPage)) {
      // Already cached at the size being asked for, so the render above is
      // showing it. Fill in the pages around it once this one has settled.
      queueTimer = setTimeout(() => { queuePagesToLoad(); }, 100);
    } else if (cachedImage !== 'failed') {
      // Not cached yet, or cached at a size this reader has outgrown. The cache
      // entry is what the view reads, so putting the page in it is the whole
      // job - there is no separate flag to raise.
      //
      loadPageIntoCache(currentPage)
        .then(() => {
          if (cancelled || currentPageRef.current !== currentPage) return;
          queueTimer = setTimeout(() => { queuePagesToLoad(); }, 100);
        })
        .catch(() => {/* the cache records the failure; see above */});
    }

    // Schedule cache cleanup after a delay
    const cleanupTimer = setTimeout(() => {
      cleanupCache();
    }, 2000); // Delay cleanup to avoid unnecessary operations

    return () => {
      cancelled = true;
      clearTimeout(cleanupTimer);
      clearTimeout(queueTimer);
    };
  }, [currentPage, comicPages, imageCache, isPageReady, queuePagesToLoad, cleanupCache, loadPageIntoCache, currentPageRef]);



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

  // The jump-to-page box holds raw text, not a page number: it has to survive
  // the empty and half-typed states an input passes through. It is reconciled
  // with the reader whenever the page changes by any other means.
  // The draft is tagged with the page it was typed against, so turning the page
  // by any other means simply makes it stale and the box falls back to showing
  // the real page. Copying the page in from an effect meant a render went out
  // with the previous page's number still in the box.
  const [pageDraft, setPageDraft] = useState({ forPage: 0, text: "1" });
  const pageInput = pageDraft.forPage === currentPage
    ? pageDraft.text
    : String(currentPage + 1);
  const setPageInput = useCallback(
    (text) => setPageDraft({ forPage: currentPageRef.current, text }),
    [currentPageRef]
  );


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
  }, [pageInput, comicPages.length, currentPageRef, goToPage, setPageInput]);

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

    const img = new Image();
    let settleForcedLoad = () => {};
    let failForcedLoad = () => {};

    img.onload = () => {
      delete loadingPagesRef.current[pageToReload];
      loadedVariantsRef.current[pageToReload] = pageVariant;
      setImageCache(prev => ({ ...prev, [pageToReload]: img }));
      settleForcedLoad(img);

      // The reader may have moved on while this was loading; the cache above is
      // still worth keeping, but the loading state belongs to another page now.
      if (currentPageRef.current !== pageToReload) return;

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
      // Record the failure, because the reload deleted the cache entry and what
      // the view shows is read from it: without this the page is indistinguish-
      // able from one still on its way and the spinner never comes down. Only
      // when nothing better has arrived in the meantime, though — the plain URL
      // may have been fetched successfully while this busted one failed.
      setImageCache(prev => (
        prev[pageToReload] && prev[pageToReload] !== 'loading'
          ? prev
          : { ...prev, [pageToReload]: 'failed' }
      ));
      if (currentPageRef.current !== pageToReload) return;

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
    loadingPagesRef.current[pageToReload] = { variant: pageVariant, promise: forcedLoad };

    img.src = withForcedReload(comicPages[pageToReload]);
  }, [comicPages, currentPage, currentPageRef, pageVariant, toast]);

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
      
      // The page is about to be laid out in a different amount of room, and a
      // pan measured against the old one would point somewhere else.
      if (!isNowFullscreen && isZoomed) resetTransform();
    };

    document.addEventListener('fullscreenchange', handleFullscreenChange);
    return () => {
      document.removeEventListener('fullscreenchange', handleFullscreenChange);
    };
  }, [isZoomed, resetTransform]);

  // Rotating the device keeps the page — that is the reading position, and
  // losing it is unforgivable — but not the zoom, which was framed against a
  // viewport that no longer exists.
  useEffect(() => {
    resetTransform();
  }, [profile.orientation, resetTransform]);

  // A turned page starts at natural scale and at the top, rather than inheriting
  // wherever the last page happened to be panned to.
  useEffect(() => {
    resetTransform();
  }, [currentPage, resetTransform]);

  // Wheel zoom, around the pointer. Deliberately only once already zoomed: a
  // wheel over an unzoomed page is somebody scrolling the page, not zooming it.
  const handleWheel = useCallback((event) => {
    if (!isZoomed) return;

    event.preventDefault();
    const rect = imageContainerRef.current?.getBoundingClientRect();
    if (!rect) return;

    pinch({
      // Exponential, so a wheel notch is the same proportional step at every
      // scale rather than a large one at 1x and an imperceptible one at 4x.
      scale: Math.exp(event.deltaY * -0.002),
      focal: { x: event.clientX - rect.left, y: event.clientY - rect.top },
    });
  }, [isZoomed, pinch]);
  
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

  const handleReaderSettingsChange = useCallback((patch) => {
    // A fit choice describes the untransformed page. Leaving an old zoom
    // active makes that choice look broken, so return to natural scale first.
    if (patch.fit && patch.fit !== settings.fit) resetTransform();

    // Once this screen has a page size of its own, the settings edit that and
    // not the account default — otherwise changing the fit here would appear to
    // do nothing while quietly changing how every other device reads.
    if (hasContextOverride && Object.keys(patch).every((key) => OVERRIDABLE_SETTINGS.includes(key))) {
      changeOverride(viewportContext, patch);
      return;
    }

    changeSettings(patch);
  }, [changeOverride, changeSettings, hasContextOverride, resetTransform, settings.fit, viewportContext]);

  const handleContextOverrideChange = useCallback((enabled) => {
    if (enabled) {
      changeOverride(viewportContext, { fit: settings.fit });
      return;
    }
    clearOverride(viewportContext);
  }, [changeOverride, clearOverride, settings.fit, viewportContext]);

  const handleResetReaderSettings = useCallback(() => {
    resetTransform();
    resetPreferences();
  }, [resetPreferences, resetTransform]);

  const suggestedFit = suggestedFitFor(profile);
  const suggestedFitLabel = READER_FITS.find(({ value }) => value === suggestedFit)?.label;
  // Offered once per context per session, and never while this screen already
  // has a page size somebody chose for it.
  const isSuggestingFit = arePreferencesLoaded
    && !hasContextOverride
    && suggestedFit !== settings.fit
    && !dismissedSuggestions[viewportContextKey(viewportContext)];

  const dismissFitSuggestion = useCallback(() => {
    setDismissedSuggestions((dismissed) => ({ ...dismissed, [viewportContextKey(viewportContext)]: true }));
  }, [viewportContext]);

  const acceptFitSuggestion = useCallback(() => {
    resetTransform();
    changeOverride(viewportContext, { fit: suggestedFit });
    dismissFitSuggestion();
  }, [changeOverride, dismissFitSuggestion, resetTransform, suggestedFit, viewportContext]);

  // Controls fade out on their own where there is no pointer to bring them
  // back on hover, and in fullscreen as they always have.
  const autoHideChrome = settings.autoHideControls && (isFullscreen || profile.coarsePointer);
  const { chromeVisible, revealChrome, toggleChrome } = useReaderChrome({
    enabled: autoHideChrome,
    pinned: isSettingsOpen || isControlFocused,
  });
  const isChromeHidden = autoHideChrome && !chromeVisible;

  const gestures = useMemo(() => ({
    onTap: ({ x }) => {
      const zone = tapZone(x, imageContainerRef.current?.clientWidth ?? 0);
      if (zone === "center") {
        toggleChrome();
        return;
      }
      // Direction of travel, not page order: the day a comic reads
      // right-to-left, this is the one place that has to know.
      if (zone === "left") handlePreviousPage();
      else handleNextPage();
    },
    onDoubleTap: ({ x, y }) => doubleTapAt({ x, y }),
    onSwipeMove: ({ dx }) => {
      setIsSwiping(true);
      setSwipeOffset(dx * SWIPE_FOLLOW);
    },
    onSwipe: ({ direction }) => {
      setIsSwiping(false);
      setSwipeOffset(0);
      if (direction === "right") handlePreviousPage();
      else handleNextPage();
    },
    onSwipeCancel: () => {
      setIsSwiping(false);
      setSwipeOffset(0);
    },
    onPan: ({ dx, dy }) => pan({ dx, dy }),
    onPinch: ({ scale, focal }) => pinch({ scale, focal }),
  }), [doubleTapAt, handleNextPage, handlePreviousPage, pan, pinch, toggleChrome]);

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
    <div
      className="reader-root flex flex-col items-center bg-background overflow-hidden"
      // Focus anywhere in the reader pins the controls open: fading out the
      // button somebody has just tabbed to would strand them on it.
      onFocus={() => setIsControlFocused(true)}
      onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget)) setIsControlFocused(false);
      }}
      // A moving mouse is a reader who is still there. Touch has no equivalent,
      // which is why a tap in the middle of the page toggles instead.
      onPointerMove={(event) => {
        if (event.pointerType === "mouse") revealChrome();
      }}
    >
      {/* Click zones, for a pointer that has no gestures. Touch reaches the same
          two actions through the gesture machine's tap zones, and rendering
          these as well would give every tap two chances to turn the page. */}
      {!profile.coarsePointer && (
        <>
          <div
            className={`page-navigation left-0 ${isFullscreen ? 'z-[55]' : ''}`}
            style={{ bottom: 'calc(88px + env(safe-area-inset-bottom))' }} // Leave space for controls to prevent overlap
            onClick={() => handleScreenNavClick('left')}
            aria-hidden="true"
          ></div>

          <div
            className={`page-navigation right-0 ${isFullscreen ? 'z-[55]' : ''}`}
            style={{ bottom: 'calc(88px + env(safe-area-inset-bottom))' }} // Leave space for controls to prevent overlap
            onClick={() => handleScreenNavClick('right')}
            aria-hidden="true"
          ></div>
        </>
      )}

      {/* Main content area - adjusted height to account for the header in normal mode */}
      <div className={`${settings.fit === "contain" || settings.fit === "height" ? "max-w-4xl" : "max-w-none"} w-full ${isFullscreen ? 'reader-stage-fullscreen' : 'reader-stage'} flex items-center justify-center py-4`}>
        <SinglePageReader
          containerRef={imageContainerRef}
          imageRef={imageRef}
          image={imageLoadedSuccessfully ? currentPageImage : null}
          isLoading={isPageImageLoading}
          hasFailed={!isPageImageLoading && !imageLoadedSuccessfully && comicPages.length > 0 && Boolean(comicPages[currentPage])}
          pageNumber={currentPage + 1}
          title={comic?.title}
          fit={settings.fit}
          isFullscreen={isFullscreen}
          transform={transform}
          swipeOffset={swipeOffset}
          isSwiping={isSwiping}
          gestures={gestures}
          onImageClick={() => {
            if (isZoomed) zoomToFit();
          }}
          onRetry={() => {
            setImageCache((previousCache) => {
              const nextCache = { ...previousCache };
              delete nextCache[currentPage];
              return nextCache;
            });
          }}
        >
          {/* Control buttons - positioned differently in fullscreen mode */}
          {/* In fullscreen this cluster duplicates Previous/Next from the bar
              below, so both groups are named: without that, a screen reader
              reads two identical "Next page" buttons with nothing to tell them
              apart. */}
          <div
            role="group"
            aria-label="Reader view controls"
            className={`${isFullscreen ? "fullscreen-controls" : "absolute top-2 right-2 z-10 flex gap-2 transition-opacity duration-300 motion-reduce:transition-none"} ${isChromeHidden ? "reader-chrome-hidden" : ""}`}
          >
            <ReaderSettings
              settings={settings}
              isLoaded={arePreferencesLoaded}
              isSaving={arePreferencesSaving}
              contextLabel={describeViewportContext(profile)}
              hasOverride={hasContextOverride}
              onChange={handleReaderSettingsChange}
              onOverrideChange={handleContextOverrideChange}
              onOpenChange={setIsSettingsOpen}
              onReset={handleResetReaderSettings}
            />

            <Button
              variant="outline" 
              size="icon"
              className="opacity-80 hover:opacity-100 bg-card/80"
              onClick={() => toggleFullscreen(document)}
              aria-label={isFullscreen ? "Exit fullscreen" : "Enter fullscreen"}
              title={isFullscreen ? "Exit fullscreen" : "Enter fullscreen"}
            >
              <Maximize className="h-4 w-4" />
            </Button>
            
            {isZoomed ? (
              <Button
                variant="outline"
                size="icon"
                className="opacity-80 hover:opacity-100 bg-card/80"
                disabled={!arePreferencesLoaded}
                onClick={zoomToFit}
                aria-label="Zoom out"
                title="Zoom out"
              >
                <ZoomOut className="h-4 w-4" />
              </Button>
            ) : (
              <Button
                variant="outline"
                size="icon"
                className="opacity-80 hover:opacity-100 bg-card/80"
                disabled={!arePreferencesLoaded}
                onClick={() => stepZoomBy(2)}
                aria-label="Zoom in"
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
                  disabled={!canGoPrevious}
                  aria-label="Previous page"
                  title="Previous page"
                >
                  <ArrowLeft className="h-4 w-4" />
                </Button>
                
                <Button
                  variant="outline"
                  size="icon"
                  className="opacity-80 hover:opacity-100 bg-card/80"
                  onClick={handleNextPage}
                  disabled={!canGoNext}
                  aria-label="Next page"
                  title="Next page"
                >
                  <ArrowRight className="h-4 w-4" />
                </Button>
              </>
            )}
            
            <Button
              variant="outline"
              size="icon"
              className="opacity-80 hover:opacity-100 bg-card/80"
              onClick={() => setShowThumbnails((shown) => !shown)}
              aria-label={showThumbnails ? "Hide page thumbnails" : "Show page thumbnails"}
              aria-expanded={showThumbnails}
              aria-controls="reader-thumbnail-strip"
              title="Page thumbnails"
            >
              <LayoutGrid className="h-4 w-4" />
            </Button>

            {/* Debug button */}
            <Button
              variant="outline"
              size="icon"
              className="opacity-80 hover:opacity-100 bg-card/80"
              onClick={() => setShowDebug(!showDebug)}
              aria-label="Debug info"
              aria-expanded={showDebug}
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
              <p>Variant: {pageVariant}</p>
              <p>
                Page size: {pageGeometry[currentPage + 1]
                  ? `${pageGeometry[currentPage + 1].width}×${pageGeometry[currentPage + 1].height}`
                  : 'unknown'}
              </p>
              <p>Loading: {isPageImageLoading ? 'Yes' : 'No'}</p>
              <p>Cached pages: {Object.keys(imageCache).length}</p>
              <p>Cache window: {Math.max(0, currentPage - preloadWindow.backward) + 1} - {Math.min(comicPages.length - 1, currentPage + preloadWindow.forward) + 1}</p>
              <p>Screen: {profile.device} {profile.orientation} ({profile.coarsePointer ? 'touch' : 'pointer'}, {profile.memory} memory)</p>
              {isZoomed && (
                <p>Zoom level: {Math.round(transform.scale * 100)}%</p>
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
                <p>Pages to load: {queueSnapshot.length}</p>
                {queueSnapshot.length > 0 && (
                  <p>Next in queue: {queueSnapshot[0] + 1}</p>
                )}
              </div>
            </div>
          )}
          {/* Case where there are no pages for the comic */}
          {comicPages.length === 0 && !isLoading && (
             <div className="text-xl">This comic has no pages to display.</div>
          )}
        </SinglePageReader>
      </div>

      {/* Page navigator. Same derivative pipeline as the page itself, one rung
          down: a thumbnail is a page, so it is behind the same authorization. */}
      {showThumbnails && comicPages.length > 0 && (
        <ReaderThumbnailStrip
          key={`${comicId}-${comicPages.length}`}
          comicId={comicId}
          pageCount={comicPages.length}
          currentPage={currentPage}
          geometry={pageGeometry}
          onSelect={goToPage}
        />
      )}

      {isSuggestingFit && (
        <ReaderFitSuggestion
          fitLabel={suggestedFitLabel}
          contextLabel={describeViewportContext(profile)}
          onAccept={acceptFitSuggestion}
          onDismiss={dismissFitSuggestion}
        />
      )}

      {/* Reader controls - different styling in fullscreen mode */}
      <div
        role="group"
        aria-label="Reader page controls"
        className={`reader-controls ${isFullscreen ? "reader-controls-fullscreen" : ""} ${isChromeHidden ? "reader-chrome-hidden" : ""}`}
      >
        {/* How far through the comic this page is, at a glance */}
        {settings.showProgress && comicPages.length > 0 && (
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
              disabled={!canGoPrevious}
              aria-label="Previous page"
              className={isFullscreen ? "" : "bg-card"}
            >
              <ArrowLeft className="h-4 w-4 min-[360px]:mr-2" />
              <span className="hidden min-[360px]:inline">Previous</span>
            </Button>

            {/* Force reload button */}
            <Button
              variant="outline"
              size="icon"
              onClick={handleForceReload}
              aria-label="Force reload current page"
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
                {Math.round(transform.scale * 100)}% zoom
              </div>
            )}
          </div>

          <Button
            variant="outline"
            onClick={handleNextPage}
            disabled={!canGoNext}
            aria-label="Next page"
            className={isFullscreen ? "" : "bg-card"}
          >
            <span className="hidden min-[360px]:inline">Next</span>
            <ArrowRight className="h-4 w-4 min-[360px]:ml-2" />
          </Button>
        </div>
      </div>
    </div>
  );
}
