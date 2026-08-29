import { usePreloadWindow } from "@/hooks/use-preload-window";
import { useReaderPagePreloading } from "@/hooks/use-reader-page-preloading";
import { useReaderPageVariants } from "@/hooks/use-reader-page-variants";

/**
 * Getting pages onto the screen: which size to ask for, how far to read ahead,
 * and when to let go.
 *
 * One hook because the three answers are the same decision seen from different
 * sides — the window that decides what is fetched early is the window that
 * decides what may be released, and both are bounded by what this device can
 * afford to hold.
 */
export function useReaderPageSupply({
  cache, containerRef, currentUnit, currentPageRef, readingUnits,
  effectiveMode, pageCount, profile, isZoomed, zoomScale, setZoomLevel,
}) {
  const { basePageVariant, variantFor, preferredZoomLevel, changeZoomLevel } = useReaderPageVariants({
    containerRef, currentUnit, isZoomed, zoomScale, setZoomLevel,
  });
  const preloadWindow = usePreloadWindow(profile);

  useReaderPagePreloading({
    ...cache,
    currentUnit, currentPageRef, variantFor, effectiveMode,
    pageCount, preloadWindow, readingUnits, basePageVariant,
  });

  return { variantFor, preferredZoomLevel, changeZoomLevel };
}
