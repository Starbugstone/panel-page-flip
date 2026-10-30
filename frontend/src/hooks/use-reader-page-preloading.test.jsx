import { act, renderHook } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { usePageImageCache } from "@/hooks/use-page-image-cache";
import { FakeImage } from "@/test/fake-image";
import { useReaderPagePreloading } from "./use-reader-page-preloading";

const VARIANT = "reader-medium";
const variantFor = () => VARIANT;
const READING_UNITS = [[0], [1, 2], [3, 4], [5, 6], [7, 8], [9]];

/** A low-memory device's window, which keeps nothing behind the anchor page. */
const NARROW_WINDOW = { backward: 0, forward: 1 };

function renderPreloading({ currentPage, currentUnit }) {
  const currentPageRef = { current: currentPage };
  return renderHook(() => {
    const cache = usePageImageCache({ comicId: "42", pageCount: 10 });
    useReaderPagePreloading({
      cancelLoadsExcept: cache.cancelLoadsExcept,
      evictOutside: cache.evictOutside,
      loadPage: cache.loadPage,
      queuePages: cache.queuePages,
      resetPageCache: cache.reset,
      currentUnit,
      currentPageRef,
      variantFor,
      effectiveMode: "double",
      pageCount: 10,
      preloadWindow: NARROW_WINDOW,
      readingUnits: READING_UNITS,
      basePageVariant: VARIANT,
    });
    return cache;
  });
}

describe("useReaderPagePreloading", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    FakeImage.reset();
    vi.stubGlobal("Image", FakeImage);
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it("keeps both sides of a spread restored on its second page when clearing the cache", async () => {
    const { result } = renderPreloading({ currentPage: 4, currentUnit: [3, 4] });
    act(() => { void result.current.loadPage(0, VARIANT); });
    await act(async () => { await vi.advanceTimersByTimeAsync(100); });
    expect(result.current.isPageReady(0, VARIANT)).toBe(true);
    expect(result.current.isPageReady(3, VARIANT)).toBe(true);
    expect(result.current.isPageReady(4, VARIANT)).toBe(true);

    await act(async () => { await vi.advanceTimersByTimeAsync(1500); });

    expect(result.current.isPageReady(3, VARIANT)).toBe(true);
    expect(result.current.isPageReady(4, VARIANT)).toBe(true);
    expect(result.current.imageCache[0]).toBeUndefined();
  });
});
