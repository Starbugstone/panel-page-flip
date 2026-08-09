import { act, renderHook } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { clampPageIndex, useReaderNavigation } from "./use-reader-navigation";

describe("logical reader navigation", () => {
  it("clamps restored progress without inventing visual page numbers", () => {
    expect(clampPageIndex(-5, 10)).toBe(0);
    expect(clampPageIndex(13, 10)).toBe(9);
    expect(clampPageIndex(4, 10)).toBe(4);
  });

  it("shares bounded next, previous, and jump operations", () => {
    const { result } = renderHook(() => useReaderNavigation(3));

    act(() => result.current.goNext());
    expect(result.current.currentPage).toBe(1);
    expect(result.current.canGoPrevious).toBe(true);

    act(() => result.current.goToPage(2));
    expect(result.current.currentPage).toBe(2);
    expect(result.current.canGoNext).toBe(false);

    act(() => result.current.goNext());
    expect(result.current.currentPage).toBe(2);

    act(() => result.current.goPrevious());
    expect(result.current.currentPage).toBe(1);
  });
});
