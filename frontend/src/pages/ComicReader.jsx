import { useCallback, useMemo, useRef, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";

import { ReaderShell } from "@/components/reader/ReaderShell";
import { ReaderView } from "@/components/reader/ReaderView";
import { Button } from "@/components/ui/button.jsx";
import { Skeleton } from "@/components/ui/skeleton.jsx";
import { useComicLibrary } from "@/hooks/use-comic-library.jsx";
import { useComicReaderData } from "@/hooks/use-comic-reader-data";
import { usePageGeometry } from "@/hooks/use-page-geometry";
import { usePageImageCache } from "@/hooks/use-page-image-cache";
import { useReaderChromeState } from "@/hooks/use-reader-chrome-state";
import { useReaderFullscreen } from "@/hooks/use-reader-fullscreen";
import { useReaderKeyboard } from "@/hooks/use-reader-keyboard";
import { useReaderLayout } from "@/hooks/use-reader-layout";
import { useReaderNavigation } from "@/hooks/use-reader-navigation";
import { useReaderNextComic } from "@/hooks/use-reader-next-comic";
import { useReaderPageReload } from "@/hooks/use-reader-page-reload";
import { useReaderPageSupply } from "@/hooks/use-reader-page-supply";
import { useReaderPageTurns } from "@/hooks/use-reader-page-turns";
import { useReaderPointerActions } from "@/hooks/use-reader-pointer-actions";
import { useReaderPreferences } from "@/hooks/use-reader-preferences.jsx";
import { useReaderProgress } from "@/hooks/use-reader-progress";
import { useReaderSettingsActions } from "@/hooks/use-reader-settings-actions";
import { useReaderSuggestions } from "@/hooks/use-reader-suggestions";
import { useReaderTransform } from "@/hooks/use-reader-transform";
import { useReaderViewModel } from "@/hooks/use-reader-view-model";
import { useReaderWakeLock } from "@/hooks/use-reader-wake-lock";
import { useReaderZoomedWheel } from "@/hooks/use-reader-zoomed-wheel";
import { useToast } from "@/hooks/use-toast.js";
import { useViewportProfile } from "@/hooks/use-viewport-profile";
import { effectiveReaderSettings, hasReaderOverride } from "@/lib/reader-preferences";

export default function ComicReader() {
  const { comicId } = useParams();
  const navigate = useNavigate();
  const { toast } = useToast();
  const { updateComicProgress } = useComicLibrary();

  const [pageCount, setPageCount] = useState(0);
  const [showThumbnails, setShowThumbnails] = useState(false);
  const [isSettingsOpen, setIsSettingsOpen] = useState(false);
  const [isControlFocused, setIsControlFocused] = useState(false);
  const [swipeOffset, setSwipeOffset] = useState(0);
  const [isSwiping, setIsSwiping] = useState(false);

  const containerRef = useRef(null);
  const imageRef = useRef(null);

  // Destructured rather than kept as an object: the hook returns a fresh one
  // every render, and an effect that depends on it re-runs forever.
  const { imageCache, imageCacheRef, loadedVariants, loadPage, cancelLoadsExcept,
    queuePages, evictOutside, retryPage: retryCachedPage, reset: resetPageCache } = usePageImageCache({ comicId, pageCount });

  const profile = useViewportProfile();
  const viewportContext = useMemo(
    () => ({ device: profile.device, orientation: profile.orientation }),
    [profile.device, profile.orientation]
  );
  const {
    preferences, isLoaded, isSaving, hasSyncError,
    changeSettings, changeOverride, clearOverride, dismissSuggestion, resetPreferences,
  } = useReaderPreferences(toast);
  const settings = useMemo(
    () => effectiveReaderSettings(preferences, viewportContext),
    [preferences, viewportContext]
  );
  const hasContextOverride = hasReaderOverride(preferences, viewportContext);
  const { currentPage, currentPageRef, goToPage: goToLogicalPage, resetPage } = useReaderNavigation(pageCount);

  const onStart = useCallback(() => {
    setPageCount(0);
    resetPage(0, 0);
    resetPageCache();
  }, [resetPage, resetPageCache]);
  const onLoaded = useCallback((loaded, count) => {
    setPageCount(count);
    resetPage(loaded?.readingProgress?.currentPage ? loaded.readingProgress.currentPage - 1 : 0, count);
  }, [resetPage]);
  const { comic, loadError, isFetching } = useComicReaderData({ comicId, navigate, toast, onStart, onLoaded });
  useReaderProgress({ comic, comicId, pageCount, currentPage, toast, onSaved: updateComicProgress });
  const nextComic = useReaderNextComic(comic);

  const { geometry: pageGeometry } = usePageGeometry(comicId, pageCount, currentPage);
  const layout = useReaderLayout({ settings, profile, pageCount, pageGeometry, currentPage });
  const { effectiveMode, readingUnits, currentUnit } = layout;

  // Continuous mode scrolls natively and only borrows the zoom number to widen
  // its pages, so the transform is switched off there rather than handed a
  // container it must not move.
  const { transform, isZoomed, pinch, pan, doubleTapAt, setZoomLevel, zoomToFit, resetPosition,
    resetTransform } = useReaderTransform({ containerRef, imageRef, enabled: effectiveMode !== "continuous" });
  useReaderWakeLock(settings.wakeLock);
  const { variantFor, preferredZoomLevel, changeZoomLevel } = useReaderPageSupply({
    cache: { cancelLoadsExcept, evictOutside, loadPage, queuePages, resetPageCache },
    containerRef, currentUnit, currentPageRef, readingUnits,
    effectiveMode, pageCount, profile, isZoomed, zoomScale: transform.scale, setZoomLevel,
  });

  const turns = useReaderPageTurns({
    currentPage, pageCount, currentUnit, readingUnits, effectiveMode,
    imageCacheRef, goToLogicalPage, resetPosition,
  });
  const { retryPage, forceReload } = useReaderPageReload({
    currentPage, currentPageRef, pageCount, retryCachedPage, variantFor, toast,
  });

  const isFullscreen = useReaderFullscreen({ resetTransform, orientation: profile.orientation, effectiveMode });
  useReaderKeyboard({
    isZoomed, isSettingsOpen, pageCount, zoomToFit,
    onPrevious: turns.goPrevious, onNext: turns.goNext, goToPage: turns.goToReaderPage,
  });
  useReaderZoomedWheel({ containerRef, enabled: isZoomed && effectiveMode !== "continuous", pan });

  const settingsActions = useReaderSettingsActions({
    settings, viewportContext, hasContextOverride,
    changeSettings, changeOverride, clearOverride, resetPreferences, resetTransform,
  });
  const suggestions = useReaderSuggestions({
    profile, viewportContext, preferences, arePreferencesLoaded: isLoaded, settings, hasContextOverride,
    isZoomed, showThumbnails, changeSettings, changeOverride, dismissSuggestion, resetTransform,
  });

  const { controlsHeight, controlsRef, revealChrome, toggleChrome, isChromeHidden } = useReaderChromeState({
    settings, profile, isFullscreen, isSettingsOpen, isControlFocused,
  });

  const pointer = useReaderPointerActions({
    containerRef, direction: settings.direction, isZoomed,
    canGoPrevious: turns.canGoPrevious, canGoNext: turns.canGoNext,
    goPrevious: turns.goPrevious, goNext: turns.goNext,
    toggleChrome, doubleTapAt, pan, pinch, zoomToFit,
    onSwipeOffsetChange: setSwipeOffset, onSwipingChange: setIsSwiping,
  });

  const { book, view, actions } = useReaderViewModel({
    comic, comicId, pageCount, currentPage, currentPageRef, layout, pageGeometry,
    imageCache, loadedVariants, variantFor, retryPage, fallbackImages: turns.fallbackImages,
    settings, profile, transform, isZoomed, isFullscreen, isChromeHidden, isSettingsOpen, showThumbnails,
    swipeOffset, isSwiping, preferredZoomLevel, hasContextOverride,
    preferencesState: { isLoaded, isSaving, hasSyncError },
    pointer, settingsActions, turns, goToLogicalPage, toggleChrome, forceReload,
    changeZoomLevel, zoomToFit, zoomIn: () => setZoomLevel(preferredZoomLevel),
    setSettingsOpen: setIsSettingsOpen,
    toggleThumbnails: () => setShowThumbnails((shown) => !shown),
  });

  if (Boolean(comicId) && isFetching) {
    return <div className="flex min-h-[60vh] items-center justify-center bg-background"><Skeleton className="h-[60vh] w-full max-w-md" /></div>;
  }
  if (!comic) return <ComicReaderLoadFailure loadError={loadError} onLeave={() => navigate("/dashboard")} />;

  return (
    <ReaderShell
      effectiveMode={effectiveMode}
      isFullscreen={isFullscreen}
      isChromeHidden={isChromeHidden}
      touchCapable={profile.touchCapable}
      controlsHeight={controlsHeight}
      onControlFocusChange={setIsControlFocused}
      onReveal={revealChrome}
    >
      <ReaderView
        book={book}
        view={view}
        refs={{ container: containerRef, image: imageRef, controls: controlsRef }}
        actions={actions}
        suggestions={suggestions}
        nextComic={nextComic}
        onNextComic={() => navigate(`/read/${nextComic.id}`)}
      />
    </ReaderShell>
  );
}

function ComicReaderLoadFailure({ loadError, onLeave }) {
  const isMissing = loadError?.status === 404;

  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center bg-background px-4 text-center">
      <p className="mb-2 text-xl">{isMissing ? "Comic not found" : "Could not load this comic"}</p>
      <p className="mb-4 text-sm text-muted-foreground">
        {isMissing
          ? "This comic may have been deleted, or the link is wrong."
          : "Something went wrong on the way to this comic. Please try again in a moment."}
      </p>
      <Button onClick={onLeave}>Return to Library</Button>
    </div>
  );
}
