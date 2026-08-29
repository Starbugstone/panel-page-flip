import { act, renderHook } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { useReaderTransform } from "./use-reader-transform";

/**
 * Plain objects rather than mounted elements: jsdom lays nothing out, so every
 * real element would measure zero. These stand for a phone-width viewport with
 * a page fitted to it, and record the scroll the hook writes back.
 */
const refs = ({ viewport = { width: 400, height: 800 }, content = { width: 400, height: 1600 }, scrollTop = 0 } = {}) => ({
  containerRef: {
    current: {
      clientWidth: viewport.width,
      clientHeight: viewport.height,
      scrollLeft: 0,
      scrollTop,
    },
  },
  imageRef: { current: { offsetWidth: content.width, offsetHeight: content.height } },
});

describe("zoom that knows where the reader was", () => {
  it("carries on from the scroll position a pinch started at", () => {
    const elements = refs({ scrollTop: 300 });
    const { result } = renderHook(() => useReaderTransform(elements));

    act(() => result.current.pinch({ scale: 2, focal: { x: 200, y: 400 } }));

    // The page is 1600 tall in an 800 viewport, so 300 scrolled puts image
    // pixel 700 in the middle of the screen. Doubling the scale must leave it
    // there: the transform has to move the page 200 down to keep it.
    expect(result.current.transform.scale).toBe(2);
    expect(result.current.transform.y).toBe(200);
    expect(elements.containerRef.current.scrollTop).toBe(0);
  });

  it("hands the position back to the scroller on the way out", () => {
    const elements = refs({ scrollTop: 300 });
    const { result } = renderHook(() => useReaderTransform(elements));

    act(() => result.current.pinch({ scale: 2, focal: { x: 200, y: 400 } }));
    act(() => result.current.zoomToFit());

    expect(result.current.isZoomed).toBe(false);
    expect(elements.containerRef.current.scrollTop).toBe(300);
  });

  it("follows two fingers that drag while they pinch", () => {
    const elements = refs({ content: { width: 400, height: 800 } });
    const { result } = renderHook(() => useReaderTransform(elements));

    act(() => result.current.pinch({ scale: 2, focal: { x: 200, y: 400 } }));
    const held = result.current.transform;
    act(() => result.current.pinch({ scale: 1, focal: { x: 170, y: 380 }, dx: -30, dy: -20 }));

    expect(result.current.transform.scale).toBe(held.scale);
    expect(result.current.transform.x).toBe(held.x - 30);
    expect(result.current.transform.y).toBe(held.y - 20);
  });

  it("pans only what is off screen", () => {
    const elements = refs({ content: { width: 400, height: 800 } });
    const { result } = renderHook(() => useReaderTransform(elements));

    act(() => result.current.pinch({ scale: 2, focal: { x: 200, y: 400 } }));
    act(() => result.current.pan({ dx: 1000, dy: 1000 }));

    // 800 wide against 400: 200 of slack, and not a pixel more.
    expect(result.current.transform).toMatchObject({ x: 200, y: 400 });
  });

  it("ignores a pan on a page that is not zoomed", () => {
    const elements = refs();
    const { result } = renderHook(() => useReaderTransform(elements));

    act(() => result.current.pan({ dx: 50, dy: 50 }));

    expect(result.current.transform).toEqual({ scale: 1, x: 0, y: 0 });
    expect(elements.containerRef.current.scrollTop).toBe(0);
  });

  it("double taps to readable width, and back again", () => {
    // A letterboxed page: 300 of artwork in 400 of screen.
    const elements = refs({ content: { width: 300, height: 800 } });
    const { result } = renderHook(() => useReaderTransform(elements));

    act(() => result.current.doubleTapAt({ x: 120, y: 200 }));
    expect(result.current.transform.scale).toBeCloseTo(400 / 300, 5);

    act(() => result.current.doubleTapAt({ x: 120, y: 200 }));
    expect(result.current.isZoomed).toBe(false);
  });

  it("starts a new page at the top, at natural scale", () => {
    const elements = refs({ scrollTop: 500 });
    const { result } = renderHook(() => useReaderTransform(elements));

    act(() => result.current.setZoomLevel(3));
    act(() => result.current.resetTransform());

    expect(result.current.transform).toEqual({ scale: 1, x: 0, y: 0 });
    expect(elements.containerRef.current.scrollTop).toBe(0);
  });

  it("sets an absolute zoom level from the settings slider", () => {
    const elements = refs({ content: { width: 400, height: 800 } });
    const { result } = renderHook(() => useReaderTransform(elements));

    act(() => result.current.setZoomLevel(2.5));
    expect(result.current.transform.scale).toBe(2.5);

    act(() => result.current.setZoomLevel(1.25));
    expect(result.current.transform.scale).toBe(1.25);
  });

  it("keeps the zoom and shows the top of a new page, not the middle", () => {
    const elements = refs({ content: { width: 400, height: 800 } });
    const { result } = renderHook(() => useReaderTransform(elements));

    act(() => result.current.setZoomLevel(2.5));
    act(() => result.current.pan({ dx: -80, dy: -120 }));
    act(() => result.current.resetPosition());

    // 800 tall at 2.5x in an 800 viewport: 600 of slack, and the top is +600.
    expect(result.current.transform).toEqual({ scale: 2.5, x: 0, y: 600 });
    expect(elements.containerRef.current.scrollTop).toBe(0);
  });

  /**
   * The turn is decided before the page turned to has rendered, so the only
   * artwork measurable at that moment is the one being left. Under fit-to-width
   * a taller next page has a top edge further out than the old page's, and
   * clamping to the old one leaves the reader part way down the new page.
   */
  it("finds the top of the new page once it has a height of its own", () => {
    const elements = refs({ content: { width: 400, height: 800 } });
    const { result, rerender } = renderHook(() => useReaderTransform(elements));

    act(() => result.current.pinch({ scale: 2, focal: { x: 200, y: 400 } }));
    act(() => result.current.resetPosition());

    // Still the outgoing page: 800 at 2x in an 800 viewport is 400 of slack.
    expect(result.current.transform).toEqual({ scale: 2, x: 0, y: 400 });

    // The next page lays out taller, as a page fitted to the width may.
    elements.imageRef.current.offsetHeight = 1600;
    act(() => rerender());

    // 1600 at 2x in an 800 viewport: 1200 of slack, and the top is +1200.
    expect(result.current.transform).toEqual({ scale: 2, x: 0, y: 1200 });
  });

  /** Settling must not outlive the turn: a reader who pans has chosen a place. */
  it("stops chasing the top once the reader has moved the page themselves", () => {
    const elements = refs({ content: { width: 400, height: 800 } });
    const { result, rerender } = renderHook(() => useReaderTransform(elements));

    act(() => result.current.pinch({ scale: 2, focal: { x: 200, y: 400 } }));
    act(() => result.current.resetPosition());
    act(() => result.current.pan({ dx: 0, dy: -150 }));

    const chosen = result.current.transform;
    elements.imageRef.current.offsetHeight = 1600;
    act(() => rerender());

    expect(result.current.transform).toEqual(chosen);
  });

  it("survives being driven before anything has been laid out", () => {
    const { result } = renderHook(() => useReaderTransform({ containerRef: { current: null }, imageRef: { current: null } }));

    act(() => result.current.pinch({ scale: 2, focal: { x: 0, y: 0 } }));

    expect(Number.isFinite(result.current.transform.x)).toBe(true);
    expect(Number.isFinite(result.current.transform.y)).toBe(true);
  });
});

/**
 * Continuous mode has no transform: it scrolls natively and only borrows the
 * zoom number to widen its pages. It used to be switched off by handing the
 * hook a ref that was never attached, which worked only because the arithmetic
 * tolerates a zero-sized viewport — a clamp against measured geometry, or an
 * early return on a missing container, would have silently stopped the zoom
 * slider working with nothing to show for it.
 */
describe("with the transform switched off", () => {
  it("keeps the zoom level and leaves the scroller alone", () => {
    const elements = refs({ scrollTop: 500 });
    const { result } = renderHook(() => useReaderTransform({ ...elements, enabled: false }));

    act(() => result.current.setZoomLevel(2.5));

    expect(result.current.transform).toEqual({ scale: 2.5, x: 0, y: 0 });
    expect(elements.containerRef.current.scrollTop).toBe(500);
  });

  it("holds the zoom level to the reader's limits", () => {
    const elements = refs();
    const { result } = renderHook(() => useReaderTransform({ ...elements, enabled: false }));

    act(() => result.current.setZoomLevel(99));
    expect(result.current.transform.scale).toBe(5);

    act(() => result.current.setZoomLevel(0.1));
    expect(result.current.transform.scale).toBe(1);

    act(() => result.current.setZoomLevel("not a number"));
    expect(result.current.transform.scale).toBe(1);
  });

  it("ignores the gestures that belong to a paged reader", () => {
    const elements = refs();
    const { result } = renderHook(() => useReaderTransform({ ...elements, enabled: false }));

    act(() => result.current.setZoomLevel(2));
    act(() => result.current.pinch({ scale: 2, focal: { x: 200, y: 400 } }));
    act(() => result.current.pan({ dx: 50, dy: 50 }));
    act(() => result.current.doubleTapAt({ x: 100, y: 100 }));

    expect(result.current.transform).toEqual({ scale: 2, x: 0, y: 0 });
    expect(elements.containerRef.current.scrollTop).toBe(0);
  });

  it("does not move the scroller when a page turn asks for the top", () => {
    const elements = refs({ scrollTop: 900 });
    const { result } = renderHook(() => useReaderTransform({ ...elements, enabled: false }));

    act(() => result.current.setZoomLevel(3));
    act(() => result.current.resetPosition());

    expect(elements.containerRef.current.scrollTop).toBe(900);
    expect(result.current.transform.scale).toBe(3);
  });
});
