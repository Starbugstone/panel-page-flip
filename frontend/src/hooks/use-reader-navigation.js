import { useCallback, useRef, useState } from "react";

export function clampPageIndex(pageIndex, pageCount) {
  if (!Number.isInteger(pageCount) || pageCount <= 0) return 0;
  if (!Number.isFinite(pageIndex)) return 0;
  return Math.min(pageCount - 1, Math.max(0, Math.trunc(pageIndex)));
}

/**
 * One logical-page contract shared by buttons, keyboard shortcuts, page jump,
 * and later visual modes. It intentionally knows nothing about left/right
 * placement or source file formats.
 */
export function useReaderNavigation(pageCount) {
  const [currentPage, setCurrentPageState] = useState(0);
  const currentPageRef = useRef(0);

  const setCurrentPage = useCallback((pageIndex) => {
    currentPageRef.current = pageIndex;
    setCurrentPageState(pageIndex);
  }, []);

  const goToPage = useCallback((pageIndex) => {
    if (!Number.isInteger(pageIndex) || pageIndex < 0 || pageIndex >= pageCount) return false;
    setCurrentPage(pageIndex);
    return true;
  }, [pageCount, setCurrentPage]);

  const resetPage = useCallback((pageIndex, nextPageCount) => {
    const nextPage = clampPageIndex(pageIndex, nextPageCount);
    setCurrentPage(nextPage);
    return nextPage;
  }, [setCurrentPage]);

  const goPrevious = useCallback(() => goToPage(currentPageRef.current - 1), [goToPage]);
  const goNext = useCallback(() => goToPage(currentPageRef.current + 1), [goToPage]);

  return {
    currentPage,
    currentPageRef,
    goToPage,
    goPrevious,
    goNext,
    resetPage,
    canGoPrevious: currentPage > 0,
    canGoNext: pageCount > 0 && currentPage < pageCount - 1,
  };
}
