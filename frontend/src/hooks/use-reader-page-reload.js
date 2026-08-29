import { useCallback } from "react";

/**
 * The two ways a page gets asked for again: a failed page's Retry, and the
 * toolbar's deliberate "fetch this one from the server".
 *
 * Only the manual reload says anything out loud, and only while the reader is
 * still on the page it was asked about — a toast about a page they have already
 * turned away from is noise about something they can no longer see.
 */
export function useReaderPageReload({ currentPage, currentPageRef, pageCount, retryCachedPage, variantFor, toast }) {
  const retryPage = useCallback(
    (pageIndex) => { void retryCachedPage(pageIndex, variantFor(pageIndex)); },
    [retryCachedPage, variantFor]
  );

  const forceReload = useCallback(() => {
    if (currentPage < 0 || currentPage >= pageCount) return;
    const pageToReload = currentPage;
    toast({ title: "Reloading page", description: `Forcing reload of page ${pageToReload + 1}` });

    retryCachedPage(pageToReload, variantFor(pageToReload)).then((image) => {
      if (currentPageRef.current !== pageToReload) return;
      toast(image
        ? { title: "Page reloaded", description: `Successfully reloaded page ${pageToReload + 1}`, variant: "success" }
        : { title: "Reload failed", description: "Could not reload the page. Please try again later.", variant: "destructive" });
    });
  }, [currentPage, currentPageRef, pageCount, retryCachedPage, toast, variantFor]);

  return { retryPage, forceReload };
}
