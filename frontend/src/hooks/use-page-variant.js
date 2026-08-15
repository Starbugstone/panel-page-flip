import { useEffect, useState } from "react";

import { DEFAULT_READER_VARIANT, largerPageVariant, selectPageVariant } from "@/lib/reader-pages";

/**
 * Which bounded page size this reader should be asking for, from how much room
 * the page actually has and how sharp this screen is.
 *
 * Measured rather than guessed from a breakpoint: the same 800 CSS pixels are a
 * phone at three device pixels each and a window somebody has dragged narrow on
 * a desktop, and only one of those needs the larger image.
 */
export function usePageVariant(containerRef, { zoomLevel = 1 } = {}) {
  const [variant, setVariant] = useState(DEFAULT_READER_VARIANT);

  useEffect(() => {
    const measure = () => {
      // The viewport stands in until the container has been laid out, so the
      // first page of a session is not fetched at the smallest size available.
      const cssWidth = containerRef?.current?.clientWidth || window.innerWidth || 0;
      const next = selectPageVariant({
        cssWidth,
        pixelRatio: window.devicePixelRatio,
        zoomLevel,
      });

      setVariant((current) => largerPageVariant(current, next));
    };

    measure();

    const element = containerRef?.current;
    const observer = typeof ResizeObserver === "function" ? new ResizeObserver(measure) : null;
    if (observer && element) observer.observe(element);
    window.addEventListener("resize", measure);

    return () => {
      observer?.disconnect();
      window.removeEventListener("resize", measure);
    };
  }, [containerRef, zoomLevel]);

  return variant;
}
