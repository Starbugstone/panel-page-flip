import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { ArrowLeft, ArrowRight, LayoutGrid, Maximize, Minimize, RefreshCw, ZoomIn, ZoomOut } from "lucide-react";

import { ContinuousPageReader } from "@/components/reader/ContinuousPageReader";
import { ReaderFitSuggestion } from "@/components/reader/ReaderFitSuggestion";
import { ReaderSettings } from "@/components/reader/ReaderSettings";
import { ReaderThumbnailStrip } from "@/components/reader/ReaderThumbnailStrip";
import { SinglePageReader } from "@/components/reader/SinglePageReader";
import { SpreadPageReader } from "@/components/reader/SpreadPageReader";
import { Button } from "@/components/ui/button.jsx";
import { Progress } from "@/components/ui/progress.jsx";
import { Skeleton } from "@/components/ui/skeleton.jsx";
import { useComicLibrary } from "@/hooks/use-comic-library.jsx";
import { usePageGeometry } from "@/hooks/use-page-geometry";
import { usePageVariant } from "@/hooks/use-page-variant";
import { usePreloadWindow } from "@/hooks/use-preload-window";
import { useReaderChrome } from "@/hooks/use-reader-chrome";
import { useReaderNavigation } from "@/hooks/use-reader-navigation";
import { useReaderPreferences } from "@/hooks/use-reader-preferences.jsx";
import { useReaderTransform } from "@/hooks/use-reader-transform";
import { useReaderWakeLock } from "@/hooks/use-reader-wake-lock";
import { useToast } from "@/hooks/use-toast.js";
import { useViewportProfile } from "@/hooks/use-viewport-profile";
import { api } from "@/lib/api";
import { parsePageNumber } from "@/lib/comic-progress";
import { toggleFullscreen } from "@/lib/fullscreen";
import { isTypingTarget } from "@/lib/keyboard";
import { logger } from "@/lib/logger";
import { mouseClickAction, tapZone } from "@/lib/reader-gestures";
import {
  adjacentReadingPage,
  buildReadingUnits,
  displayOrderFor,
  pageRangeLabel,
  readingUnitForPage,
} from "@/lib/reader-layout";
import {
  createReaderPageUrl,
  isPageVariantAtLeast,
  withForcedReload,
} from "@/lib/reader-pages";
import {
  DEFAULT_READER_PREFERENCES,
  OVERRIDABLE_SETTINGS,
  READER_FITS,
  effectiveReaderSettings,
  hasReaderOverride,
} from "@/lib/reader-preferences";
import { describeViewportContext, suggestedFitFor, viewportContextKey } from "@/lib/reader-viewport";

const SWIPE_FOLLOW = 0.35;
const MODE_SUGGESTION_ID = "mode:tablet:landscape";
const isUsableImage = (value) => Boolean(value) && value !== "loading" && value !== "failed";

export default function ComicReader() {
  const { comicId } = useParams();
  const navigate = useNavigate();
  const { toast } = useToast();
  const { updateComicProgress } = useComicLibrary();
  const [comic, setComic] = useState(null);
  const [pageCount, setPageCount] = useState(0);
  const [loadError, setLoadError] = useState(null);
  const [isFetchingComic, setIsFetchingComic] = useState(true);
  const [imageCache, setImageCache] = useState({});
  const [loadedVariants, setLoadedVariants] = useState({});
  const [showThumbnails, setShowThumbnails] = useState(false);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [isSettingsOpen, setIsSettingsOpen] = useState(false);
  const [isControlFocused, setIsControlFocused] = useState(false);
  const [swipeOffset, setSwipeOffset] = useState(0);
  const [isSwiping, setIsSwiping] = useState(false);
  const [fallbackImages, setFallbackImages] = useState([]);

  const readerRootRef = useRef(null);
  const imageContainerRef = useRef(null);
  const imageRef = useRef(null);
  const imageCacheRef = useRef({});
  const pageInputRef = useRef(null);
  const progressAbortController = useRef(null);
  const progressRevisionRef = useRef(0);
  const lastSavedPage = useRef(null);
  const isMountedRef = useRef(true);
  const loadedVariantsRef = useRef({});
  const loadingPagesRef = useRef({});
  const loadQueueRef = useRef([]);
  const isLoadingBackgroundRef = useRef(false);
  const updateImageCache = useCallback((update) => {
    setImageCache((previous) => {
      const next = typeof update === "function" ? update(previous) : update;
      imageCacheRef.current = next;
      return next;
    });
  }, []);
  const updateLoadedVariants = useCallback((update) => {
    setLoadedVariants((previous) => typeof update === "function" ? update(previous) : update);
  }, []);

  const profile = useViewportProfile();
  const viewportContext = useMemo(
    () => ({ device: profile.device, orientation: profile.orientation }),
    [profile.device, profile.orientation]
  );
  const {
    preferences,
    isLoaded: arePreferencesLoaded,
    isSaving: arePreferencesSaving,
    hasSyncError: readerPreferencesHaveSyncError,
    changeSettings,
    changeOverride,
    clearOverride,
    dismissSuggestion,
    resetPreferences,
  } = useReaderPreferences(toast);
  const settings = useMemo(
    () => effectiveReaderSettings(preferences, viewportContext),
    [preferences, viewportContext]
  );
  const hasContextOverride = hasReaderOverride(preferences, viewportContext);
  const { currentPage, currentPageRef, goToPage: goToLogicalPage, resetPage } = useReaderNavigation(pageCount);
  const { geometry: pageGeometry } = usePageGeometry(comicId, pageCount, currentPage);

  const effectiveMode = settings.mode === "continuous"
    ? "continuous"
    : settings.mode === "double" && profile.orientation === "landscape" && profile.device !== "phone"
      ? "double"
      : "single";
  const readingUnits = useMemo(
    () => buildReadingUnits(pageCount, pageGeometry, { coverAlone: settings.coverAlone }),
    [pageCount, pageGeometry, settings.coverAlone]
  );
  const currentUnit = useMemo(
    () => effectiveMode === "double" ? readingUnitForPage(readingUnits, currentPage) : [currentPage],
    [currentPage, effectiveMode, readingUnits]
  );
  const visiblePages = useMemo(
    () => displayOrderFor(currentUnit, settings.direction),
    [currentUnit, settings.direction]
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
  const basePageVariant = usePageVariant(imageContainerRef, { zoomLevel: 1 });
  const zoomPageVariant = usePageVariant(imageContainerRef, { zoomLevel: transform.scale });
  const preloadWindow = usePreloadWindow(profile);
  useReaderWakeLock(settings.wakeLock);

  const desiredVariantFor = useCallback(
    (pageIndex) => isZoomed && currentUnit.includes(pageIndex) ? zoomPageVariant : basePageVariant,
    [basePageVariant, currentUnit, isZoomed, zoomPageVariant]
  );
  const isPageReady = useCallback((pageIndex, variant = desiredVariantFor(pageIndex)) => (
    isUsableImage(imageCacheRef.current[pageIndex])
      && isPageVariantAtLeast(loadedVariantsRef.current[pageIndex], variant)
  ), [desiredVariantFor]);

  const updateReadingProgress = useCallback(async (pageToSave) => {
    if (!comicId || !comic) return;
    progressAbortController.current?.abort();
    const controller = new AbortController();
    progressAbortController.current = controller;
    const revision = ++progressRevisionRef.current;
    try {
      const response = await api.post(
        `/api/comics/${comicId}/progress`,
        { currentPage: pageToSave, revision },
        { signal: controller.signal, keepalive: true }
      );
      if (typeof response?.progress?.revision === "number" && response.progress.revision > progressRevisionRef.current) {
        progressRevisionRef.current = response.progress.revision;
      }
      if (response?.progress) updateComicProgress(comicId, response.progress);
    } catch (error) {
      if (error.name === "AbortError" || controller.signal.aborted) return;
      if (error.name === "TypeError" && error.message.includes("Failed to fetch")) {
        logger.warn("Network error when saving reading progress - will retry on next page change");
        return;
      }
      logger.error("Failed to save reading progress:", error);
      if (isMountedRef.current) {
        toast({ title: "Error saving progress", description: error.message || "Could not save your reading progress. Please try again.", variant: "destructive" });
      }
    } finally {
      if (progressAbortController.current === controller) progressAbortController.current = null;
    }
  }, [comic, comicId, toast, updateComicProgress]);

  useEffect(() => {
    isMountedRef.current = true;
    return () => {
      isMountedRef.current = false;
      loadQueueRef.current = [];
      Object.values(loadingPagesRef.current).forEach((entry) => entry.cancel?.());
    };
  }, []);

  useEffect(() => {
    let active = true;
    const loadComic = async () => {
      setIsFetchingComic(true);
      setLoadError(null);
      setComic(null);
      setPageCount(0);
      lastSavedPage.current = null;
      resetPage(0, 0);
      updateImageCache({});
      loadedVariantsRef.current = {};
      updateLoadedVariants({});
      Object.values(loadingPagesRef.current).forEach((entry) => entry.cancel?.());
      loadingPagesRef.current = {};
      loadQueueRef.current = [];
      try {
        const data = await api.get(`/api/comics/${comicId}`);
        if (!active) return;
        setComic(data.comic);
        const count = data.comic?.pageCount ?? 0;
        setPageCount(count);
        progressRevisionRef.current = data.comic?.readingProgress?.revision || 0;
        resetPage(data.comic?.readingProgress?.currentPage ? data.comic.readingProgress.currentPage - 1 : 0, count);
        if (count <= 0) {
          toast({ title: "Comic has no pages", description: "This comic cannot be displayed as it has no pages.", variant: "destructive" });
        }
      } catch (error) {
        if (!active) return;
        logger.error("Failed to load comic:", error);
        setLoadError(error);
        if (error.status !== 404) {
          toast({
            title: "Error loading comic",
            description: error.status >= 500 ? "The server had a problem loading this comic. Please try again in a moment." : "There was a problem loading the comic. Please try again.",
            variant: "destructive",
          });
        }
      } finally {
        if (active) setIsFetchingComic(false);
      }
    };
    if (comicId) void loadComic();
    else navigate("/dashboard");
    return () => { active = false; };
  }, [comicId, navigate, resetPage, toast, updateImageCache, updateLoadedVariants]);

  const cancelLoadsExcept = useCallback((keepPages) => {
    const keep = new Set(keepPages);
    Object.entries(loadingPagesRef.current).forEach(([key, entry]) => {
      if (!keep.has(Number(key))) entry.cancel?.();
    });
  }, []);

  const loadPageIntoCache = useCallback((pageIndex, variant = desiredVariantFor(pageIndex), { force = false } = {}) => {
    if (pageIndex < 0 || pageIndex >= pageCount) return Promise.resolve(null);
    if (!force && isPageReady(pageIndex, variant)) return Promise.resolve(imageCacheRef.current[pageIndex]);
    const existing = loadingPagesRef.current[pageIndex];
    if (existing?.variant === variant && !force) return existing.promise;
    existing?.cancel?.();

    let settle = () => {};
    const img = new Image();
    const entry = {
      variant,
      img,
      promise: null,
      cancel: () => {
        if (loadingPagesRef.current[pageIndex] !== entry) return;
        img.onload = null;
        img.onerror = null;
        try { img.src = ""; } catch { /* Some image shims expose a read-only src. */ }
        delete loadingPagesRef.current[pageIndex];
        settle(null);
      },
    };
    const isCurrent = () => loadingPagesRef.current[pageIndex] === entry;
    entry.promise = new Promise((resolve) => { settle = resolve; });
    loadingPagesRef.current[pageIndex] = entry;
    updateImageCache((previous) => isUsableImage(previous[pageIndex]) ? previous : { ...previous, [pageIndex]: "loading" });
    img.onload = () => {
      if (!isCurrent()) return settle(null);
      loadedVariantsRef.current[pageIndex] = variant;
      updateLoadedVariants((previous) => ({ ...previous, [pageIndex]: variant }));
      updateImageCache((previous) => ({ ...previous, [pageIndex]: img }));
      delete loadingPagesRef.current[pageIndex];
      settle(img);
    };
    img.onerror = () => {
      if (!isCurrent()) return settle(null);
      updateImageCache((previous) => isUsableImage(previous[pageIndex]) ? previous : { ...previous, [pageIndex]: "failed" });
      delete loadingPagesRef.current[pageIndex];
      settle(null);
    };
    const stableUrl = createReaderPageUrl(comicId, pageIndex + 1, variant);
    img.src = force ? withForcedReload(stableUrl) : stableUrl;
    return entry.promise;
  }, [comicId, desiredVariantFor, isPageReady, pageCount, updateImageCache, updateLoadedVariants]);

  const processBackgroundQueue = useCallback(() => {
    const drain = () => {
      if (isLoadingBackgroundRef.current || loadQueueRef.current.length === 0) return;
      isLoadingBackgroundRef.current = true;
      const pageIndex = loadQueueRef.current.shift();
      loadPageIntoCache(pageIndex, basePageVariant).finally(() => {
        isLoadingBackgroundRef.current = false;
        drain();
      });
    };
    drain();
  }, [basePageVariant, loadPageIntoCache]);

  const queueBackgroundPages = useCallback(() => {
    const anchor = currentPageRef.current;
    const start = Math.max(0, anchor - preloadWindow.backward);
    const end = Math.min(pageCount - 1, anchor + preloadWindow.forward);
    const visible = new Set(currentUnit);
    const ordered = [];
    if (effectiveMode === "double") {
      const unitIndex = readingUnits.findIndex((unit) => unit.includes(anchor));
      ordered.push(...(readingUnits[unitIndex + 1] ?? []), ...(readingUnits[unitIndex - 1] ?? []));
    }
    for (let distance = 1; distance <= Math.max(preloadWindow.forward, preloadWindow.backward); distance += 1) {
      if (anchor + distance <= end && !ordered.includes(anchor + distance)) ordered.push(anchor + distance);
      if (anchor - distance >= start && !ordered.includes(anchor - distance)) ordered.push(anchor - distance);
    }
    loadQueueRef.current = ordered.filter((pageIndex) => !visible.has(pageIndex)
      && !isPageReady(pageIndex, basePageVariant)
      && !loadingPagesRef.current[pageIndex]);
    processBackgroundQueue();
  }, [basePageVariant, currentPageRef, currentUnit, effectiveMode, isPageReady, pageCount, preloadWindow, processBackgroundQueue, readingUnits]);

  useEffect(() => {
    if (effectiveMode === "continuous" || pageCount <= 0) return undefined;
    cancelLoadsExcept(currentUnit);
    let cancelled = false;
    Promise.all(currentUnit.map((pageIndex) => loadPageIntoCache(pageIndex, desiredVariantFor(pageIndex))))
      .then(() => { if (!cancelled) queueBackgroundPages(); });
    const cleanupTimer = setTimeout(() => {
      const start = Math.max(0, currentPageRef.current - preloadWindow.backward);
      const end = Math.min(pageCount - 1, currentPageRef.current + preloadWindow.forward);
      updateImageCache((previous) => {
        const next = { ...previous };
        let changed = false;
        Object.keys(next).forEach((key) => {
          const pageIndex = Number(key);
          if (pageIndex < start || pageIndex > end) {
            delete next[key];
            delete loadedVariantsRef.current[key];
            changed = true;
          }
        });
        return changed ? next : previous;
      });
      updateLoadedVariants((previous) => {
        const next = { ...previous };
        let changed = false;
        Object.keys(next).forEach((key) => {
          const pageIndex = Number(key);
          if (pageIndex < start || pageIndex > end) {
            delete next[key];
            changed = true;
          }
        });
        return changed ? next : previous;
      });
    }, 1500);
    return () => {
      cancelled = true;
      clearTimeout(cleanupTimer);
    };
  }, [cancelLoadsExcept, currentPage, currentPageRef, currentUnit, desiredVariantFor, effectiveMode, loadPageIntoCache, pageCount, preloadWindow, queueBackgroundPages, updateImageCache, updateLoadedVariants]);

  useEffect(() => {
    if (effectiveMode === "continuous") {
      cancelLoadsExcept([]);
      loadQueueRef.current = [];
      updateImageCache({});
      loadedVariantsRef.current = {};
    }
  }, [cancelLoadsExcept, effectiveMode, updateImageCache, updateLoadedVariants]);

  useEffect(() => {
    if (comic && comicId && pageCount > 0 && lastSavedPage.current !== currentPage) {
      lastSavedPage.current = currentPage;
      void updateReadingProgress(currentPage + 1);
    }
  }, [comic, comicId, currentPage, pageCount, updateReadingProgress]);
  const goToReaderPage = useCallback((pageIndex) => {
    if (effectiveMode !== "continuous") {
      const images = currentUnit.map((index) => imageCacheRef.current[index]);
      if (images.length > 0 && images.every(isUsableImage)) setFallbackImages(images);
      resetTransform();
    }
    return goToLogicalPage(pageIndex);
  }, [currentUnit, effectiveMode, goToLogicalPage, resetTransform]);
  const previousTarget = effectiveMode === "double"
    ? adjacentReadingPage(readingUnits, currentPage, "previous")
    : currentPage > 0 ? currentPage - 1 : null;
  const nextTarget = effectiveMode === "double"
    ? adjacentReadingPage(readingUnits, currentPage, "next")
    : currentPage < pageCount - 1 ? currentPage + 1 : null;
  const canGoPrevious = previousTarget !== null;
  const canGoNext = nextTarget !== null;
  const handlePreviousPage = useCallback(() => {
    if (previousTarget !== null) goToReaderPage(previousTarget);
  }, [goToReaderPage, previousTarget]);
  const handleNextPage = useCallback(() => {
    if (nextTarget !== null) goToReaderPage(nextTarget);
  }, [goToReaderPage, nextTarget]);

  const [pageDraft, setPageDraft] = useState({ forPage: 0, text: "1" });
  const pageInput = pageDraft.forPage === currentPage ? pageDraft.text : String(currentPage + 1);
  const setPageInput = useCallback((text) => setPageDraft({ forPage: currentPageRef.current, text }), [currentPageRef]);
  const commitPageInput = useCallback(() => {
    const requestedPage = parsePageNumber(pageInput, pageCount);
    if (requestedPage === null) {
      setPageInput(String(currentPageRef.current + 1));
      return;
    }
    setPageInput(String(requestedPage + 1));
    if (requestedPage !== currentPageRef.current) goToReaderPage(requestedPage);
  }, [currentPageRef, goToReaderPage, pageCount, pageInput, setPageInput]);

  const retryPage = useCallback((pageIndex) => {
    loadingPagesRef.current[pageIndex]?.cancel?.();
    delete loadedVariantsRef.current[pageIndex];
    updateLoadedVariants((previous) => {
      const next = { ...previous };
      delete next[pageIndex];
      return next;
    });
    updateImageCache((previous) => {
      const next = { ...previous };
      delete next[pageIndex];
      return next;
    });
    void loadPageIntoCache(pageIndex, desiredVariantFor(pageIndex), { force: true });
  }, [desiredVariantFor, loadPageIntoCache, updateImageCache, updateLoadedVariants]);
  const handleForceReload = useCallback(() => {
    if (currentPage < 0 || currentPage >= pageCount) return;
    const pageToReload = currentPage;
    loadingPagesRef.current[pageToReload]?.cancel?.();
    updateImageCache((previous) => {
      const next = { ...previous };
      delete next[pageToReload];
      return next;
    });
    toast({ title: "Reloading page", description: `Forcing reload of page ${pageToReload + 1}` });
    loadPageIntoCache(pageToReload, desiredVariantFor(pageToReload), { force: true }).then((image) => {
      if (currentPageRef.current !== pageToReload) return;
      toast(image
        ? { title: "Page reloaded", description: `Successfully reloaded page ${pageToReload + 1}`, variant: "success" }
        : { title: "Reload failed", description: "Could not reload the page. Please try again later.", variant: "destructive" });
    });
  }, [currentPage, currentPageRef, desiredVariantFor, loadPageIntoCache, pageCount, toast, updateImageCache]);

  useEffect(() => {
    const handleKeyPress = (event) => {
      if (isTypingTarget(event.target)) return;
      // Only while there is a zoom to leave and nothing else is claiming the
      // key. Escape closes the settings sheet first — taking the zoom off at
      // the same time would be two things happening for one press. Leaving
      // fullscreen resets the transform anyway, so those two cannot disagree.
      if (event.key === "Escape" && isZoomed && !isSettingsOpen) {
        event.preventDefault();
        zoomToFit();
        return;
      }
      if (["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) event.preventDefault();
      if (event.key === "ArrowLeft") handlePreviousPage();
      if (event.key === "ArrowRight") handleNextPage();
      if (event.key === "Home") goToReaderPage(0);
      if (event.key === "End") goToReaderPage(pageCount - 1);
    };
    window.addEventListener("keydown", handleKeyPress);
    return () => window.removeEventListener("keydown", handleKeyPress);
  }, [goToReaderPage, handleNextPage, handlePreviousPage, isSettingsOpen, isZoomed, pageCount, zoomToFit]);
  useEffect(() => {
    const handleFullscreenChange = () => {
      const next = Boolean(document.fullscreenElement);
      setIsFullscreen(next);
      if (!next) resetTransform();
    };
    document.addEventListener("fullscreenchange", handleFullscreenChange);
    return () => document.removeEventListener("fullscreenchange", handleFullscreenChange);
  }, [resetTransform]);
  useEffect(() => {
    resetTransform();
  }, [profile.orientation, resetTransform]);
  useEffect(() => resetTransform(), [effectiveMode, resetTransform]);

  const handleWheel = useCallback((event) => {
    if (!isZoomed || effectiveMode === "continuous") return;
    event.preventDefault();
    const rect = imageContainerRef.current?.getBoundingClientRect();
    if (!rect) return;
    pinch({ scale: Math.exp(event.deltaY * -0.002), focal: { x: event.clientX - rect.left, y: event.clientY - rect.top } });
  }, [effectiveMode, isZoomed, pinch]);
  useEffect(() => {
    const container = imageContainerRef.current;
    if (!container || !isZoomed || effectiveMode === "continuous") return undefined;
    container.addEventListener("wheel", handleWheel, { passive: false });
    return () => container.removeEventListener("wheel", handleWheel);
  }, [effectiveMode, handleWheel, isZoomed]);

  const handleReaderSettingsChange = useCallback((patch) => {
    if ((patch.fit && patch.fit !== settings.fit) || patch.mode || patch.direction) resetTransform();
    if (hasContextOverride && Object.keys(patch).every((key) => OVERRIDABLE_SETTINGS.includes(key))) {
      changeOverride(viewportContext, patch);
      return;
    }
    changeSettings(patch);
  }, [changeOverride, changeSettings, hasContextOverride, resetTransform, settings.fit, viewportContext]);
  const handleContextOverrideChange = useCallback((enabled) => {
    if (enabled) changeOverride(viewportContext, { fit: settings.fit });
    else {
      resetTransform();
      clearOverride(viewportContext);
    }
  }, [changeOverride, clearOverride, resetTransform, settings.fit, viewportContext]);
  const handleResetReaderSettings = useCallback(() => {
    resetTransform();
    resetPreferences();
  }, [resetPreferences, resetTransform]);

  const suggestedFit = suggestedFitFor(profile);
  const suggestedFitLabel = READER_FITS.find(({ value }) => value === suggestedFit)?.label;
  const fitSuggestionId = `fit:${viewportContextKey(viewportContext)}`;
  const suggestionWasDismissed = (id) => preferences.dismissedSuggestions.includes(id);
  const isSuggestingMode = arePreferencesLoaded
    && !isZoomed
    && !showThumbnails
    && profile.device === "tablet"
    && profile.orientation === "landscape"
    && settings.mode === "single"
    && !suggestionWasDismissed(MODE_SUGGESTION_ID);
  const isSuggestingFit = !isSuggestingMode
    && !isZoomed
    && !showThumbnails
    && arePreferencesLoaded
    && !hasContextOverride
    && preferences.settings.fit === DEFAULT_READER_PREFERENCES.settings.fit
    && suggestedFit !== settings.fit
    && !suggestionWasDismissed(fitSuggestionId);
  const acceptFitSuggestion = useCallback(() => {
    resetTransform();
    changeOverride(viewportContext, { fit: suggestedFit });
    dismissSuggestion(fitSuggestionId);
  }, [changeOverride, dismissSuggestion, fitSuggestionId, resetTransform, suggestedFit, viewportContext]);
  const acceptModeSuggestion = useCallback(() => {
    changeSettings({ mode: "double" });
    dismissSuggestion(MODE_SUGGESTION_ID);
  }, [changeSettings, dismissSuggestion]);

  const autoHideChrome = settings.autoHideControls && (isFullscreen || profile.touchCapable);
  const { chromeVisible, revealChrome, toggleChrome } = useReaderChrome({ enabled: autoHideChrome, pinned: isSettingsOpen || isControlFocused });
  useEffect(() => revealChrome(), [profile.orientation, revealChrome]);
  const isChromeHidden = autoHideChrome && !chromeVisible;
  const physicalLeft = settings.direction === "rtl" ? handleNextPage : handlePreviousPage;
  const physicalRight = settings.direction === "rtl" ? handlePreviousPage : handleNextPage;
  const gestures = useMemo(() => ({
    onTap: ({ x }) => {
      const zone = tapZone(x, imageContainerRef.current?.clientWidth ?? 0);
      if (isZoomed || zone === "center") return toggleChrome();
      if (zone === "left") physicalLeft();
      else physicalRight();
    },
    onDoubleTap: ({ x, y }) => doubleTapAt({ x, y }),
    onSwipeMove: ({ dx }) => {
      setIsSwiping(true);
      setSwipeOffset(dx * SWIPE_FOLLOW);
    },
    onSwipe: ({ direction }) => {
      setIsSwiping(false);
      setSwipeOffset(0);
      if (direction === "right") physicalLeft();
      else physicalRight();
    },
    onSwipeCancel: () => {
      setIsSwiping(false);
      setSwipeOffset(0);
    },
    onPan: ({ dx, dy }) => pan({ dx, dy }),
    onPinch: ({ scale, focal, dx, dy }) => pinch({ scale, focal, dx, dy }),
  }), [doubleTapAt, isZoomed, pan, physicalLeft, physicalRight, pinch, toggleChrome]);
  const handleMousePageClick = useCallback((event) => {
    const rect = imageContainerRef.current?.getBoundingClientRect();
    if (!rect) return;

    const action = mouseClickAction({
      x: event.clientX - rect.left,
      width: rect.width,
      onArtwork: Boolean(event.target.closest?.("[data-reader-artwork]")),
      zoomed: isZoomed,
    });

    if (action === "zoomOut") zoomToFit();
    else if (action === "chrome") toggleChrome();
    else if (action === "left") physicalLeft();
    else physicalRight();
  }, [isZoomed, physicalLeft, physicalRight, toggleChrome, zoomToFit]);

  if (Boolean(comicId) && isFetchingComic) {
    return <div className="flex min-h-[60vh] items-center justify-center bg-background"><Skeleton className="h-[60vh] w-full max-w-md" /></div>;
  }
  if (!comic) {
    const isMissing = loadError?.status === 404;
    return (
      <div className="flex min-h-[60vh] flex-col items-center justify-center bg-background px-4 text-center">
        <p className="mb-2 text-xl">{isMissing ? "Comic not found" : "Could not load this comic"}</p>
        <p className="mb-4 text-sm text-muted-foreground">
          {isMissing ? "This comic may have been deleted, or the link is wrong." : "Something went wrong on the way to this comic. Please try again in a moment."}
        </p>
        <Button onClick={() => navigate("/dashboard")}>Return to Library</Button>
      </div>
    );
  }

  const requestedImages = currentUnit.map((pageIndex, slot) => {
    const exact = imageCache[pageIndex];
    const fallback = fallbackImages[slot];
    // Old artwork is useful while a request is unresolved, but once the target
    // has definitively failed it becomes misleading. Show the retry state on
    // its own instead of presenting the previous page behind an error for this
    // one.
    const image = isUsableImage(exact)
      ? exact
      : exact !== "failed" && isUsableImage(fallback) ? fallback : null;
    return {
      pageIndex,
      image,
      isStale: !isUsableImage(exact) && Boolean(image),
      isLoading: exact !== "failed" && !(isUsableImage(exact)
        && isPageVariantAtLeast(loadedVariants[pageIndex], desiredVariantFor(pageIndex))),
      hasFailed: exact === "failed",
      onRetry: () => retryPage(pageIndex),
    };
  });
  const pageStatesByIndex = new Map(requestedImages.map((state) => [state.pageIndex, state]));
  const orderedPageStates = visiblePages.map((pageIndex) => pageStatesByIndex.get(pageIndex));
  const unitLabel = pageRangeLabel(currentUnit);
  const progressLabel = currentUnit.length > 1 ? `Pages ${unitLabel} of ${pageCount}` : `Page ${currentPage + 1} of ${pageCount}`;

  return (
    <div
      ref={readerRootRef}
      className="reader-root relative flex flex-col items-center overflow-hidden bg-background"
      data-touch-capable={profile.touchCapable ? "true" : "false"}
      data-effective-reader-mode={effectiveMode}
      data-fullscreen={isFullscreen ? "true" : "false"}
      data-reader-chrome={isChromeHidden ? "hidden" : "visible"}
      onFocus={() => setIsControlFocused(true)}
      onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setIsControlFocused(false); }}
      onPointerDownCapture={(event) => {
        if (!event.target.closest?.("[data-page-fit], [data-reader-mode]")) return;
        const active = document.activeElement;
        if (active instanceof HTMLElement && readerRootRef.current?.contains(active)) active.blur();
      }}
      onPointerMove={(event) => { if (event.pointerType === "mouse") revealChrome(); }}
    >
      <div className={`${effectiveMode === "continuous" || settings.fit === "width" || settings.fit === "original" ? "max-w-none" : "max-w-4xl"} ${isFullscreen ? "reader-stage-fullscreen" : "reader-stage"} ${effectiveMode !== "continuous" && !isChromeHidden ? "reader-stage-controls-visible" : ""} flex w-full items-center justify-center pt-4`}>
        {pageCount === 0 ? (
          <div className="text-xl">This comic has no pages to display.</div>
        ) : effectiveMode === "continuous" ? (
          <ContinuousPageReader
            containerRef={imageContainerRef}
            comicId={comicId}
            pageCount={pageCount}
            currentPage={currentPage}
            title={comic.title}
            geometry={pageGeometry}
            resetToken={`${profile.orientation}:${effectiveMode}`}
            onCurrentPageChange={goToLogicalPage}
            onActivity={toggleChrome}
          />
        ) : effectiveMode === "double" ? (
          <SpreadPageReader
            containerRef={imageContainerRef}
            contentRef={imageRef}
            pages={orderedPageStates}
            title={comic.title}
            fit={settings.fit}
            transform={transform}
            swipeOffset={swipeOffset}
            isSwiping={isSwiping}
            gestures={gestures}
            onSurfaceClick={handleMousePageClick}
          />
        ) : (
          <SinglePageReader
            containerRef={imageContainerRef}
            imageRef={imageRef}
            image={requestedImages[0]?.image}
            isStale={requestedImages[0]?.isStale}
            isLoading={requestedImages[0]?.isLoading}
            hasFailed={requestedImages[0]?.hasFailed}
            pageNumber={currentPage + 1}
            title={comic.title}
            fit={settings.fit}
            transform={transform}
            swipeOffset={swipeOffset}
            isSwiping={isSwiping}
            gestures={gestures}
            onSurfaceClick={handleMousePageClick}
            onRetry={() => retryPage(currentPage)}
          />
        )}
      </div>

      <div
        role="group"
        aria-label="Reader view controls"
        className={`${isFullscreen ? "fullscreen-controls" : "reader-view-controls absolute z-20 flex gap-2 transition-opacity duration-300 motion-reduce:transition-none"} ${isChromeHidden ? "reader-chrome-hidden" : ""}`}
      >
        <ReaderSettings
          settings={settings}
          isLoaded={arePreferencesLoaded}
          isSaving={arePreferencesSaving}
          hasSyncError={readerPreferencesHaveSyncError}
          contextLabel={describeViewportContext(profile)}
          hasOverride={hasContextOverride}
          modeNotice={settings.mode === "double" && effectiveMode !== "double"
            ? "Two-page mode uses one page on narrow or portrait screens."
            : null}
          onChange={handleReaderSettingsChange}
          onOverrideChange={handleContextOverrideChange}
          onOpenChange={setIsSettingsOpen}
          onReset={handleResetReaderSettings}
        />
        <Button variant="outline" size="icon" className="bg-card/80 opacity-80 hover:opacity-100" onClick={() => toggleFullscreen(document)} aria-label={isFullscreen ? "Exit fullscreen" : "Enter fullscreen"} title={isFullscreen ? "Exit fullscreen" : "Enter fullscreen"}>
          {isFullscreen ? <Minimize className="h-4 w-4" /> : <Maximize className="h-4 w-4" />}
        </Button>
        {effectiveMode !== "continuous" && (isZoomed ? (
          <Button variant="outline" size="icon" className="bg-card/80 opacity-80 hover:opacity-100" onClick={zoomToFit} aria-label="Zoom out" title="Zoom out"><ZoomOut className="h-4 w-4" /></Button>
        ) : (
          <Button variant="outline" size="icon" className="bg-card/80 opacity-80 hover:opacity-100" onClick={() => stepZoomBy(2)} aria-label="Zoom in" title="Zoom in"><ZoomIn className="h-4 w-4" /></Button>
        ))}
        <Button variant="outline" size="icon" className="bg-card/80 opacity-80 hover:opacity-100" onClick={() => setShowThumbnails((shown) => !shown)} aria-label={showThumbnails ? "Hide page thumbnails" : "Show page thumbnails"} aria-expanded={showThumbnails} aria-controls="reader-thumbnail-strip" title="Page thumbnails">
          <LayoutGrid className="h-4 w-4" />
        </Button>
      </div>

      {showThumbnails && pageCount > 0 && (
        <ReaderThumbnailStrip key={`${comicId}-${pageCount}`} comicId={comicId} pageCount={pageCount} currentPage={currentPage} geometry={pageGeometry} onSelect={goToReaderPage} />
      )}
      {isSuggestingMode && (
        <ReaderFitSuggestion message={<><span className="font-medium">Two pages</span> can make better use of this wide screen.</>} acceptLabel="Use two pages" onAccept={acceptModeSuggestion} onDismiss={() => dismissSuggestion(MODE_SUGGESTION_ID)} />
      )}
      {isSuggestingFit && (
        <ReaderFitSuggestion fitLabel={suggestedFitLabel} contextLabel={describeViewportContext(profile)} onAccept={acceptFitSuggestion} onDismiss={() => dismissSuggestion(fitSuggestionId)} />
      )}

      <div role="group" aria-label="Reader page controls" className={`reader-controls ${isFullscreen ? "reader-controls-fullscreen" : ""} ${isChromeHidden ? "reader-chrome-hidden" : ""}`}>
        {settings.showProgress && pageCount > 0 && (
          <Progress value={((Math.max(...currentUnit) + 1) / pageCount) * 100} aria-label={progressLabel} className="h-1 w-full rounded-none bg-muted/60" />
        )}
        <div className="flex w-full items-center justify-between gap-2">
          <div className="flex items-center gap-2">
            <Button variant="outline" onClick={handlePreviousPage} disabled={!canGoPrevious} aria-label="Previous page" className={isFullscreen ? "" : "bg-card"}>
              <ArrowLeft className="h-4 w-4 min-[360px]:mr-2" /><span className="hidden min-[360px]:inline">Previous</span>
            </Button>
            {effectiveMode !== "continuous" && (
              <Button variant="outline" size="icon" onClick={handleForceReload} aria-label="Force reload current page" title="Force reload current page" className={isFullscreen ? "" : "bg-card"}><RefreshCw className="h-4 w-4" /></Button>
            )}
          </div>
          <div className="flex min-w-0 flex-col items-center gap-1">
            <form className="flex items-center gap-1.5 text-sm" onSubmit={(event) => { event.preventDefault(); commitPageInput(); pageInputRef.current?.blur(); }}>
              <label htmlFor="reader-page-input" className="sr-only">Go to page</label>
              <input
                id="reader-page-input"
                ref={pageInputRef}
                type="number"
                inputMode="numeric"
                min={1}
                max={pageCount || 1}
                value={pageInput}
                onChange={(event) => setPageInput(event.target.value)}
                onBlur={commitPageInput}
                disabled={pageCount === 0}
                title="Go to page"
                className="h-8 w-14 rounded-md border border-input bg-background px-2 text-center text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:opacity-50"
              />
              <span className="whitespace-nowrap">of {pageCount}</span>
            </form>
            {currentUnit.length > 1 && <span className="text-xs text-muted-foreground">Showing pages {unitLabel}</span>}
            {isZoomed && <span className="rounded bg-primary/20 px-2 py-0.5 text-xs">{Math.round(transform.scale * 100)}% zoom</span>}
          </div>
          <Button variant="outline" onClick={handleNextPage} disabled={!canGoNext} aria-label="Next page" className={isFullscreen ? "" : "bg-card"}>
            <span className="hidden min-[360px]:inline">Next</span><ArrowRight className="h-4 w-4 min-[360px]:ml-2" />
          </Button>
        </div>
      </div>
    </div>
  );
}
