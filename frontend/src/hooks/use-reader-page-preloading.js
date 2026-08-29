import { useCallback, useEffect } from "react";

/**
 * What to hold ready around the page on screen, and what to let go of.
 *
 * The order matters: the visible unit is loaded first and everything else is
 * queued only once it has arrived, so a reader waiting for the page in front of
 * them is never behind a queue of pages they have not asked for. Eviction is
 * deferred because the pages just turned away from are the ones most likely to
 * be turned back to.
 */
export function useReaderPagePreloading({
  cancelLoadsExcept, evictOutside, loadPage, queuePages, resetPageCache,
  currentUnit, currentPageRef, variantFor, effectiveMode,
  pageCount, preloadWindow, readingUnits, basePageVariant,
}) {
  const queueBackgroundPages = useCallback(() => {
    const anchor = currentPageRef.current;
    const start = Math.max(0, anchor - preloadWindow.backward);
    const end = Math.min(pageCount - 1, anchor + preloadWindow.forward);
    const visible = new Set(currentUnit);
    const ordered = [];

    // The facing spreads either side come first: in two-page mode they are what
    // a single page turn actually reveals.
    if (effectiveMode === "double") {
      const unitIndex = readingUnits.findIndex((unit) => unit.includes(anchor));
      ordered.push(...(readingUnits[unitIndex + 1] ?? []), ...(readingUnits[unitIndex - 1] ?? []));
    }
    for (let distance = 1; distance <= Math.max(preloadWindow.forward, preloadWindow.backward); distance += 1) {
      if (anchor + distance <= end && !ordered.includes(anchor + distance)) ordered.push(anchor + distance);
      if (anchor - distance >= start && !ordered.includes(anchor - distance)) ordered.push(anchor - distance);
    }
    queuePages(ordered.filter((pageIndex) => !visible.has(pageIndex)), basePageVariant);
  }, [basePageVariant, currentPageRef, currentUnit, effectiveMode, pageCount, preloadWindow, queuePages, readingUnits]);

  useEffect(() => {
    if (effectiveMode === "continuous" || pageCount <= 0) return undefined;
    cancelLoadsExcept(currentUnit);

    let cancelled = false;
    Promise.all(currentUnit.map((pageIndex) => loadPage(pageIndex, variantFor(pageIndex))))
      .then(() => { if (!cancelled) queueBackgroundPages(); });

    const cleanupTimer = setTimeout(() => evictOutside(
      Math.max(0, currentPageRef.current - preloadWindow.backward),
      Math.min(pageCount - 1, currentPageRef.current + preloadWindow.forward)
    ), 1500);

    return () => {
      cancelled = true;
      clearTimeout(cleanupTimer);
    };
  }, [cancelLoadsExcept, currentPageRef, currentUnit, effectiveMode, evictOutside, loadPage, pageCount, preloadWindow, queueBackgroundPages, variantFor]);

  // Continuous mode renders its own <img> per page, so nothing this cache holds
  // is on screen and holding it would be a second copy of the whole comic.
  useEffect(() => {
    if (effectiveMode === "continuous") resetPageCache();
  }, [effectiveMode, resetPageCache]);
}
