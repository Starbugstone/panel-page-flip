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

    act(() => result.current.stepZoomBy(3));
    act(() => result.current.resetTransform());

    expect(result.current.transform).toEqual({ scale: 1, x: 0, y: 0 });
    expect(elements.containerRef.current.scrollTop).toBe(0);
  });

  it("survives being driven before anything has been laid out", () => {
    const { result } = renderHook(() => useReaderTransform({ containerRef: { current: null }, imageRef: { current: null } }));

    act(() => result.current.pinch({ scale: 2, focal: { x: 0, y: 0 } }));

    expect(Number.isFinite(result.current.transform.x)).toBe(true);
    expect(Number.isFinite(result.current.transform.y)).toBe(true);
  });
});
