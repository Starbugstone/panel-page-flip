import { renderHook } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { usePageVariant } from "./use-page-variant";

const observerCalls = { observed: 0, disconnected: 0 };

class CountingObserver {
  observe() { observerCalls.observed += 1; }
  unobserve() {}
  disconnect() { observerCalls.disconnected += 1; }
}

// One object, created once: a component's ref is stable across renders, and a
// fresh one per render would rebind the observer for reasons of its own.
const container = (clientWidth) => ({ current: { clientWidth } });

describe("how much page to ask the server for", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("measures the room a page has rather than guessing from a breakpoint", () => {
    const { result } = renderHook(() => usePageVariant(container(1600)));

    expect(result.current).toBe("reader-large");
  });

  it("moves up a rung when the page is zoomed, and never back down", () => {
    const ref = container(700);
    const { result, rerender } = renderHook(
      ({ zoomLevel }) => usePageVariant(ref, { zoomLevel }),
      { initialProps: { zoomLevel: 1 } }
    );
    // A narrow container does not ask for less than the reader already opened
    // with: the ladder only ever goes up.
    expect(result.current).toBe("reader-medium");

    rerender({ zoomLevel: 2.5 });
    expect(result.current).toBe("reader-large");

    // The bytes for the larger image are already paid for; going back down
    // would spend a fresh download to show less than what is on screen.
    rerender({ zoomLevel: 1 });
    expect(result.current).toBe("reader-large");
  });

  /**
   * A pinch reports a new scale on every frame it lasts. Watching the container
   * has to survive that: rebuilding the observer sixty times a second is a cost
   * paid during the one gesture least able to afford it.
   */
  it("does not rebuild the container observer for every frame of a pinch", () => {
    observerCalls.observed = 0;
    observerCalls.disconnected = 0;
    vi.stubGlobal("ResizeObserver", CountingObserver);

    const ref = container(800);
    const { rerender } = renderHook(
      ({ zoomLevel }) => usePageVariant(ref, { zoomLevel }),
      { initialProps: { zoomLevel: 1 } }
    );
    expect(observerCalls.observed).toBe(1);

    for (const zoomLevel of [1.1, 1.4, 1.9, 2.3, 2.8]) rerender({ zoomLevel });

    expect(observerCalls.observed).toBe(1);
    expect(observerCalls.disconnected).toBe(0);
  });
});
