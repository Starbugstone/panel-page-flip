import { useCallback, useRef, useState } from "react";

import { Button } from "@/components/ui/button.jsx";
import { parsePageNumber } from "@/lib/comic-progress";

/**
 * The jump-to-page box, and what the reader is currently looking at.
 *
 * The typed page is draft state keyed on the page it was typed for, so a page
 * turn from anywhere else — a swipe, an arrow key, a thumbnail — replaces what
 * is in the box instead of fighting a half-typed number for it.
 */
export function ReaderPageJump({
  currentPage, currentPageRef, pageCount, unitLabel, unitLength, isZoomed, zoomScale, onGoToPage,
}) {
  const pageInputRef = useRef(null);
  const [draft, setDraft] = useState({ forPage: 0, text: "1" });
  const pageInput = draft.forPage === currentPage ? draft.text : String(currentPage + 1);

  const setPageInput = useCallback(
    (text) => setDraft({ forPage: currentPageRef.current, text }),
    [currentPageRef]
  );

  const commitPageInput = useCallback(() => {
    const requestedPage = parsePageNumber(pageInput, pageCount);
    if (requestedPage === null) {
      setPageInput(String(currentPageRef.current + 1));
      return;
    }
    setPageInput(String(requestedPage + 1));
    if (requestedPage !== currentPageRef.current) onGoToPage(requestedPage);
  }, [currentPageRef, onGoToPage, pageCount, pageInput, setPageInput]);

  return (
        <div className="flex min-w-0 flex-col items-center gap-1">
          <form
            className="flex min-w-0 items-center justify-center gap-1.5 text-sm"
            onSubmit={(event) => { event.preventDefault(); commitPageInput(); pageInputRef.current?.blur(); }}
          >
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
              className="h-8 w-12 shrink-0 rounded-md border border-input bg-background px-1 text-center text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:opacity-50 sm:w-14 sm:px-2"
            />
            <span className="shrink-0 whitespace-nowrap">of {pageCount}</span>
            <Button type="submit" variant="outline" size="sm" disabled={pageCount === 0} aria-label="Go to typed page" className="h-8 shrink-0 px-2">
              Go
            </Button>
          </form>

          {unitLength > 1 && <span className="text-xs text-muted-foreground">Showing pages {unitLabel}</span>}
          {isZoomed && <span className="rounded bg-primary/20 px-2 py-0.5 text-xs">{Math.round(zoomScale * 100)}% zoom</span>}
        </div>
  );
}
