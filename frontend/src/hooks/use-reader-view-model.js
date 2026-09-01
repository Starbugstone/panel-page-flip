import { useMemo } from "react";

import { pageRangeLabel } from "@/lib/reader-layout";
import { orderPageStates, readerPageStates } from "@/lib/reader-pages";

/**
 * The three things the reader's markup is given: what is being read, how it is
 * currently shown, and what can be done to either.
 *
 * Assembled here rather than in the component so the view takes three arguments
 * instead of forty, and so that "which of these is state and which is an
 * action" stays a decision made in one place.
 */
export function useReaderViewModel({
  comic, comicId, pageCount, currentPage, currentPageRef, layout, pageGeometry,
  imageCache, loadedVariants, variantFor, retryPage, fallbackImages,
  settings, profile, transform, isZoomed, isFullscreen, isChromeHidden, isSettingsOpen, showThumbnails,
  swipeOffset, isSwiping, preferredZoomLevel, hasContextOverride, preferencesState,
  pointer, settingsActions, turns, goToLogicalPage, toggleChrome, forceReload,
  changeZoomLevel, zoomToFit, zoomIn, setSettingsOpen, toggleThumbnails,
}) {
  const { effectiveMode, currentUnit, visiblePages } = layout;

  const pageStates = useMemo(
    () => readerPageStates({ unit: currentUnit, imageCache, fallbackImages, loadedVariants, variantFor, onRetry: retryPage }),
    [currentUnit, fallbackImages, imageCache, loadedVariants, retryPage, variantFor]
  );

  const book = {
    comic, comicId, pageCount, currentPage, currentPageRef, currentUnit, effectiveMode, pageGeometry,
    pageStates,
    orderedPageStates: orderPageStates(pageStates, visiblePages),
    unitLabel: pageRangeLabel(currentUnit),
  };

  const view = {
    settings, profile, transform, isZoomed, isFullscreen, isChromeHidden, isSettingsOpen, showThumbnails,
    swipeOffset, isSwiping, preferredZoomLevel, hasContextOverride, preferences: preferencesState,
  };

  const actions = {
    ...pointer,
    canGoPrevious: turns.canGoPrevious,
    canGoNext: turns.canGoNext,
    goPrevious: turns.goPrevious,
    goNext: turns.goNext,
    goToReaderPage: turns.goToReaderPage,
    goToLogicalPage,
    toggleChrome,
    retryPage,
    forceReload,
    changeZoomLevel,
    zoomToFit,
    zoomIn,
    changeSettings: settingsActions.change,
    changeContextOverride: settingsActions.changeContextOverride,
    resetSettings: settingsActions.reset,
    setSettingsOpen,
    toggleThumbnails,
  };

  return { book, view, actions };
}
