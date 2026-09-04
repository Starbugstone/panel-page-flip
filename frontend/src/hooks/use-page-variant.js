import { useCallback, useEffect, useRef, useState } from "react";

import { selectPageVariant } from "@/lib/reader-pages";

/**
 * Which bounded page size this reader should be asking for, from how much room
 * the page actually has and how sharp this screen is.
 *
 * Measured rather than guessed from a breakpoint: the same 800 CSS pixels are a
 * phone at three device pixels each and a window somebody has dragged narrow on
 * a desktop, and only one of those needs the larger image.
 */
export function usePageVariant(containerRef, { zoomLevel = 1 } = {}) {
  // An img starts fetching before effects run. Wait for the first measurement
  // or every newly revealed scroll page downloads a default size first.
  const [variant, setVariant] = useState(null);
  const zoomRef = useRef(zoomLevel);

  const measure = useCallback(() => {
    setVariant(selectPageVariant({
      cssWidth: containerRef?.current?.clientWidth || window.innerWidth || 0,
      pixelRatio: window.devicePixelRatio,
      zoomLevel: zoomRef.current,
    }));
  }, [containerRef]);

  // A pinch moves the zoom on every frame it lasts. Measuring again is cheap;
  // tearing down and rebuilding the observer below is not, so the zoom is read
  // through a ref and only the measurement repeats.
  useEffect(() => {
    zoomRef.current = zoomLevel;
    measure();
  }, [zoomLevel, measure]);

  useEffect(() => {
    measure();

    const element = containerRef?.current;
    const observer = typeof ResizeObserver === "function" ? new ResizeObserver(measure) : null;
    if (observer && element) observer.observe(element);
    window.addEventListener("resize", measure);

    return () => {
      observer?.disconnect();
      window.removeEventListener("resize", measure);
    };
  }, [containerRef, measure]);

  return variant;
}
