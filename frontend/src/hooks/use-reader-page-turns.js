import { useCallback } from "react";

import { adjacentReadingPage } from "@/lib/reader-layout";

/**
 * Where "previous" and "next" go.
 *
 * In two-page mode a turn moves by reading unit rather than by one page, so the
 * spread does not walk out of step with itself.
 */
export function useReaderPageTurns({
  currentPage, pageCount, readingUnits, effectiveMode,
  goToLogicalPage, resetPosition,
}) {
  const goToReaderPage = useCallback((pageIndex) => {
    if (effectiveMode !== "continuous") {
      resetPosition();
    }
    return goToLogicalPage(pageIndex);
  }, [effectiveMode, goToLogicalPage, resetPosition]);

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
    goToReaderPage,
    goPrevious,
    goNext,
    canGoPrevious: previousTarget !== null,
    canGoNext: nextTarget !== null,
  };
}
