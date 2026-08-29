import { useCallback, useLayoutEffect, useRef, useState } from "react";

import {
  IDENTITY_TRANSFORM,
  clampTransform,
  doubleTapTransform,
  clampScale,
  isZoomed,
  originAtTop,
  panBy,
  scrollFromTransform,
  stepZoom,
  transformFromScroll,
  zoomAbout,
} from "@/lib/reader-zoom";

/**
 * The one piece of zoom state the reader has, and the only place that decides
 * whether the page is being scrolled or transformed.
 *
 * A fitted page scrolls natively; a zoomed page is moved by its transform.
 * Every entry point here converts between the two, so a pinch that begins
 * partway down a tall page carries on from where the reader actually was.
 *
 * `enabled: false` is continuous mode, which has no transform at all: it scrolls
 * natively and only borrows the zoom number to widen its pages. Disabled, this
 * keeps the scale and touches no geometry and no DOM — it used to be handed a
 * ref that was deliberately never attached, which worked only because the
 * arithmetic happens to tolerate a zero-sized viewport.
 */
/**
 * Reading the page's geometry, and handing the position between the two things
 * that can own it.
 *
 * While zoomed the transform owns the position and the scroller must be at
 * rest, or its offset would add itself to every pan. Back at natural scale the
 * scroller owns it again — but it cannot be told until it can scroll, which is
 * a render away, so the offset is parked and applied in a layout effect.
 */
function useTransformGeometry({ containerRef, imageRef, transformRef, pendingScrollRef, transform, apply }) {
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
  }, [containerRef, transformRef]);

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
  }, [apply, containerRef, pendingScrollRef, transformRef]);

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
  }, [transform, containerRef, pendingScrollRef]);

  return { measure, startingPoint, settle };
}

export function useReaderTransform({ containerRef, imageRef, enabled = true }) {
  const [transform, setTransform] = useState(IDENTITY_TRANSFORM);
  const transformRef = useRef(IDENTITY_TRANSFORM);
  const pendingScrollRef = useRef(null);
  // Set while the reader is being put at the top of a page that has not been
  // laid out yet. See resetPosition and the layout effect that clears it.
  const settlingAtTopRef = useRef(false);

  const apply = useCallback((next) => {
    transformRef.current = next;
    setTransform(next);
  }, []);

  const { measure, startingPoint, settle } = useTransformGeometry({
    containerRef, imageRef, transformRef, pendingScrollRef, transform, apply,
  });

  const pinch = useCallback(({ scale, focal, dx = 0, dy = 0 }) => {
    if (!enabled) return;

    settlingAtTopRef.current = false;
    const geometry = measure();
    // Zooming holds the point between the fingers still, which is right for the
    // scale and wrong for the fingers: two of them dragging across the glass is
    // a pan, and how far they went is what dx and dy say.
    const zoomed = zoomAbout(startingPoint(geometry), focal, scale, geometry.viewport);
    settle(panBy(zoomed, dx, dy), geometry);
  }, [enabled, measure, settle, startingPoint]);

  const pan = useCallback(({ dx, dy }) => {
    if (!enabled || !isZoomed(transformRef.current)) return;

    settlingAtTopRef.current = false;
    const geometry = measure();
    apply(clampTransform(panBy(transformRef.current, dx, dy), geometry));
  }, [apply, enabled, measure]);

  const doubleTapAt = useCallback((point) => {
    if (!enabled) return;

    settlingAtTopRef.current = false;
    const geometry = measure();
    settle(doubleTapTransform(startingPoint(geometry), point, geometry), geometry);
  }, [enabled, measure, settle, startingPoint]);

  const setZoomLevel = useCallback((scale) => {
    settlingAtTopRef.current = false;
    if (!enabled) {
      apply({ ...IDENTITY_TRANSFORM, scale: clampScale(scale) });
      return;
    }

    const geometry = measure();
    const current = startingPoint(geometry);
    const requested = Number(scale);
    const factor = Number.isFinite(requested) && current.scale > 0
      ? requested / current.scale
      : 1;
    settle(stepZoom(current, factor, geometry), geometry);
  }, [apply, enabled, measure, settle, startingPoint]);

  const zoomToFit = useCallback(() => setZoomLevel(1), [setZoomLevel]);

  /** A new fit or reader layout starts from the top at natural scale. */
  const resetTransform = useCallback(() => {
    settlingAtTopRef.current = false;
    pendingScrollRef.current = null;
    const container = containerRef.current;
    if (enabled && container) {
      container.scrollLeft = 0;
      container.scrollTop = 0;
    }
    apply(IDENTITY_TRANSFORM);
  }, [apply, containerRef, enabled]);

  /**
   * Keep the chosen zoom, but show the top of a newly selected page.
   *
   * The page being turned to has not rendered yet, so what can be measured here
   * is the page being turned away from. Where the two differ in height — fit to
   * width, a taller next page — its top edge is somewhere this cannot know, so
   * the position is corrected by the layout effect below once the new artwork
   * has a size.
   */
  const resetPosition = useCallback(() => {
    // Continuous mode owns its own scroll position; there is no page turn to
    // put at the top and no container here to move.
    if (!enabled) return;

    const scale = transformRef.current.scale;
    if (!isZoomed({ scale })) {
      resetTransform();
      return;
    }

    pendingScrollRef.current = null;
    const container = containerRef.current;
    if (container) {
      container.scrollLeft = 0;
      container.scrollTop = 0;
    }
    settlingAtTopRef.current = true;
    apply(originAtTop(scale, measure()));
  }, [apply, containerRef, enabled, measure, resetTransform]);

  // Runs after every render while a page turn is settling, because the artwork
  // arrives at its size over several of them. Re-clamping to the top is what it
  // already asked for, so this converges rather than fighting: once the value
  // stops changing there is nothing left to apply. Any deliberate move by the
  // reader clears the flag first — see pan, pinch and the zoom entry points.
  useLayoutEffect(() => {
    if (!enabled || !settlingAtTopRef.current) return;

    const geometry = measure();
    if (!(geometry.content.height > 0)) return;

    const next = originAtTop(transformRef.current.scale, geometry);
    if (next.x !== transformRef.current.x || next.y !== transformRef.current.y) apply(next);
  });

  return {
    transform,
    isZoomed: isZoomed(transform),
    pinch,
    pan,
    doubleTapAt,
    setZoomLevel,
    zoomToFit,
    resetPosition,
    resetTransform,
  };
}
