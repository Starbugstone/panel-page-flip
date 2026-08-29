import { useEffect } from "react";

/**
 * A wheel over a zoomed page pans it rather than scrolling the document.
 *
 * Attached directly rather than through React so it can be non-passive: the
 * default scroll has to be preventable, and React's synthetic wheel listener
 * cannot be.
 */
export function useReaderZoomedWheel({ containerRef, enabled, pan }) {
  useEffect(() => {
    const container = containerRef.current;
    if (!container || !enabled) return undefined;

    const handleWheel = (event) => {
      event.preventDefault();
      pan({ dx: 0, dy: -event.deltaY });
    };

    container.addEventListener("wheel", handleWheel, { passive: false });
    return () => container.removeEventListener("wheel", handleWheel);
  }, [containerRef, enabled, pan]);
}
