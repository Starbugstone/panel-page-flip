import { useCallback, useState } from "react";

import { usePageVariant } from "@/hooks/use-page-variant";

/**
 * Which size of each page to ask for, and the zoom level a reader keeps coming
 * back to.
 *
 * Only the pages actually on screen are promoted to the zoomed variant: the
 * ones being held ready around them are still going to be met at fitted size,
 * and fetching those larger would spend a reader's data on sharpness nobody has
 * asked to see.
 */
export function useReaderPageVariants({ containerRef, currentUnit, isZoomed, zoomScale, setZoomLevel }) {
  const [preferredZoomLevel, setPreferredZoomLevel] = useState(2);
  const basePageVariant = usePageVariant(containerRef, { zoomLevel: 1 });
  const zoomPageVariant = usePageVariant(containerRef, { zoomLevel: zoomScale });

  const changeZoomLevel = useCallback((scale) => {
    // Remembered so the toolbar's zoom button returns to the magnification this
    // reader chose, rather than to a number the component picked for them.
    if (scale > 1) setPreferredZoomLevel(scale);
    setZoomLevel(scale);
  }, [setZoomLevel]);

  const variantFor = useCallback(
    (pageIndex) => isZoomed && currentUnit.includes(pageIndex) ? zoomPageVariant : basePageVariant,
    [basePageVariant, currentUnit, isZoomed, zoomPageVariant]
  );

  return { basePageVariant, variantFor, preferredZoomLevel, changeZoomLevel };
}
