import { useCallback, useState } from "react";

import { adjacentReadingPage } from "@/lib/reader-layout";
import { isUsableImage } from "@/lib/reader-pages";

/**
 * Where "previous" and "next" go, and what the screen shows on the way.
 *
 * In two-page mode a turn moves by reading unit rather than by one page, so the
 * spread does not walk out of step with itself. The pages being left are kept
 * as fallback artwork until the new ones decode, which is what stops a turn
 * being a flash of empty reader.
 */
export function useReaderPageTurns({
  currentPage, pageCount, currentUnit, readingUnits, effectiveMode,
  imageCacheRef, goToLogicalPage, resetPosition,
}) {
  const [fallbackImages, setFallbackImages] = useState([]);

  const goToReaderPage = useCallback((pageIndex) => {
    if (effectiveMode !== "continuous") {
      const images = currentUnit.map((index) => imageCacheRef.current[index]);
      if (images.length > 0 && images.every(isUsableImage)) setFallbackImages(images);
      resetPosition();
    }
    return goToLogicalPage(pageIndex);
  }, [currentUnit, effectiveMode, goToLogicalPage, imageCacheRef, resetPosition]);

  const previousTarget = effectiveMode === "double"
    ? adjacentReadingPage(readingUnits, currentPage, "previous")
    : currentPage > 0 ? currentPage - 1 : null;
  const nextTarget = effectiveMode === "double"
    ? adjacentReadingPage(readingUnits, currentPage, "next")
    : currentPage < pageCount - 1 ? currentPage + 1 : null;

  const goPrevious = useCallback(() => {
    if (previousTarget !== null) goToReaderPage(previousTarget);
  }, [goToReaderPage, previousTarget]);
  const goNext = useCallback(() => {
    if (nextTarget !== null) goToReaderPage(nextTarget);
  }, [goToReaderPage, nextTarget]);

  return {
    fallbackImages,
    goToReaderPage,
    goPrevious,
    goNext,
    canGoPrevious: previousTarget !== null,
    canGoNext: nextTarget !== null,
  };
}
