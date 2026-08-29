import { useCallback, useEffect, useState } from "react";

const measured = (element) => element ? Math.ceil(element.getBoundingClientRect().height) || null : null;

/**
 * How much room the reader's bottom controls actually occupy.
 *
 * The stage has to hold the whole page clear of them, and the bar is not one
 * fixed height: the zoom badge, a spread's page range, coarse-pointer hit
 * targets and a window narrow enough to wrap the row each make it taller. A
 * reserved constant was only ever right for the plainest desktop case and hid
 * the foot of the page in every other one.
 *
 * Measuring cannot feed back into itself: the bar is positioned against the
 * viewport, so the padding this number drives never changes its height.
 *
 * @returns {{ height: number | null, controlsRef: (node: Element | null) => void }}
 *   `height` is null until the bar has been laid out, which is the caller's cue
 *   to keep its fallback reservation rather than reserve nothing at all.
 */
export function useReaderControlsHeight() {
  // A callback ref rather than an object one: the bar mounts a render after the
  // reader does, and an effect keyed on a ref would never see it arrive. It
  // also gives the first measurement a moment that is already after layout,
  // which keeps it out of the effect below.
  const [element, setElement] = useState(null);
  const [height, setHeight] = useState(null);

  const controlsRef = useCallback((node) => {
    setElement(node);
    setHeight(measured(node));
  }, []);

  useEffect(() => {
    const remeasure = () => setHeight(measured(element));
    // Observing reports the current size straight away, so what the bar grows
    // by after mount — a zoom badge, a page range, a row that wraps — arrives
    // here rather than needing a second measurement on the way in.
    const observer = typeof ResizeObserver === "function" ? new ResizeObserver(remeasure) : null;
    if (observer && element) observer.observe(element);
    window.addEventListener("resize", remeasure);

    return () => {
      observer?.disconnect();
      window.removeEventListener("resize", remeasure);
    };
  }, [element]);

  return { height, controlsRef };
}
