import { useCallback, useLayoutEffect, useRef, useState } from "react";

import {
  IDENTITY_TRANSFORM,
  clampTransform,
  doubleTapTransform,
  isZoomed,
  panBy,
  scrollFromTransform,
  stepZoom,
  transformFromScroll,
  zoomAbout,
  zoomOut,
} from "@/lib/reader-zoom";

/**
 * The one piece of zoom state the reader has, and the only place that decides
 * whether the page is being scrolled or transformed.
 *
 * A fitted page scrolls natively; a zoomed page is moved by its transform.
 * Every entry point here converts between the two, so a pinch that begins
 * partway down a tall page carries on from where the reader actually was.
 */
export function useReaderTransform({ containerRef, imageRef }) {
  const [transform, setTransform] = useState(IDENTITY_TRANSFORM);
  const transformRef = useRef(IDENTITY_TRANSFORM);
  const pendingScrollRef = useRef(null);

  const apply = useCallback((next) => {
    transformRef.current = next;
    setTransform(next);
  }, []);

  const measure = useCallback(() => {
    const container = containerRef.current;
    const image = imageRef.current;

    return {
      viewport: { width: container?.clientWidth ?? 0, height: container?.clientHeight ?? 0 },
      // Layout size: a CSS transform does not change it, so this stays the size
      // of the fitted page however far it is currently zoomed.
      content: { width: image?.offsetWidth ?? 0, height: image?.offsetHeight ?? 0 },
    };
  }, [containerRef, imageRef]);

  const startingPoint = useCallback((geometry) => {
    if (isZoomed(transformRef.current)) return transformRef.current;

    const container = containerRef.current;
    return transformFromScroll({
      ...geometry,
      scrollLeft: container?.scrollLeft ?? 0,
      scrollTop: container?.scrollTop ?? 0,
    });
  }, [containerRef]);

  const settle = useCallback((next, geometry) => {
    const container = containerRef.current;

    if (isZoomed(next)) {
      // The transform owns the position while zoomed, and the page is centred
      // under it — which is only true if the scroller is at rest. Leaving a
      // scroll offset in place would add itself to every pan.
      if (container && !isZoomed(transformRef.current)) {
        container.scrollLeft = 0;
        container.scrollTop = 0;
      }
      apply(clampTransform(next, geometry));
      return;
    }

    // Back at natural scale the scroller owns the position again. It cannot be
    // told until it can scroll, which is a render away — the container is still
    // clipped while the page is zoomed.
    pendingScrollRef.current = scrollFromTransform(next, geometry);
    apply({ ...IDENTITY_TRANSFORM });
  }, [apply, containerRef]);

  // Layout, not passive: the container only becomes scrollable in this render,
  // and a passive effect would let the browser paint the page at the top before
  // the scroll it is supposed to come back to.
  useLayoutEffect(() => {
    const pending = pendingScrollRef.current;
    if (!pending) return;

    pendingScrollRef.current = null;
    const container = containerRef.current;
    if (!container) return;

    container.scrollLeft = pending.scrollLeft;
    container.scrollTop = pending.scrollTop;
  }, [transform, containerRef]);

  const pinch = useCallback(({ scale, focal, dx = 0, dy = 0 }) => {
    const geometry = measure();
    // Zooming holds the point between the fingers still, which is right for the
    // scale and wrong for the fingers: two of them dragging across the glass is
    // a pan, and how far they went is what dx and dy say.
    const zoomed = zoomAbout(startingPoint(geometry), focal, scale, geometry.viewport);
    settle(panBy(zoomed, dx, dy), geometry);
  }, [measure, settle, startingPoint]);

  const pan = useCallback(({ dx, dy }) => {
    if (!isZoomed(transformRef.current)) return;

    const geometry = measure();
    apply(clampTransform(panBy(transformRef.current, dx, dy), geometry));
  }, [apply, measure]);

  const doubleTapAt = useCallback((point) => {
    const geometry = measure();
    settle(doubleTapTransform(startingPoint(geometry), point, geometry), geometry);
  }, [measure, settle, startingPoint]);

  const stepZoomBy = useCallback((factor) => {
    const geometry = measure();
    settle(stepZoom(startingPoint(geometry), factor, geometry), geometry);
  }, [measure, settle, startingPoint]);

  const setZoomLevel = useCallback((scale) => {
    const geometry = measure();
    const current = startingPoint(geometry);
    const requested = Number(scale);
    const factor = Number.isFinite(requested) && current.scale > 0
      ? requested / current.scale
      : 1;
    settle(stepZoom(current, factor, geometry), geometry);
  }, [measure, settle, startingPoint]);

  const zoomToFit = useCallback(() => {
    const geometry = measure();
    settle(zoomOut(startingPoint(geometry), geometry), geometry);
  }, [measure, settle, startingPoint]);

  /** Keep the chosen zoom, but put a newly selected page back at its origin. */
  const resetPosition = useCallback(() => {
    pendingScrollRef.current = null;
    const container = containerRef.current;
    if (container) {
      container.scrollLeft = 0;
      container.scrollTop = 0;
    }
    apply({ scale: transformRef.current.scale, x: 0, y: 0 });
  }, [apply, containerRef]);

  /** A new fit or reader layout starts from the top at natural scale. */
  const resetTransform = useCallback(() => {
    pendingScrollRef.current = null;
    const container = containerRef.current;
    if (container) {
      container.scrollLeft = 0;
      container.scrollTop = 0;
    }
    apply(IDENTITY_TRANSFORM);
  }, [apply, containerRef]);

  return {
    transform,
    isZoomed: isZoomed(transform),
    pinch,
    pan,
    doubleTapAt,
    stepZoomBy,
    setZoomLevel,
    zoomToFit,
    resetPosition,
    resetTransform,
  };
}
