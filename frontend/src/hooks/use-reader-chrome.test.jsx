import { act, renderHook } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { useReaderChrome } from "./use-reader-chrome";

describe("when the reader's controls are on screen", () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  const idle = (ms = 5000) => act(() => { vi.advanceTimersByTime(ms); });

  it("gives the screen back to the artwork once nothing is happening", () => {
    const { result } = renderHook(() => useReaderChrome({ enabled: true }));

    expect(result.current.chromeVisible).toBe(true);
    idle();
    expect(result.current.chromeVisible).toBe(false);
  });

  it("brings the controls back when they are asked for", () => {
    const { result } = renderHook(() => useReaderChrome({ enabled: true }));
    idle();

    act(() => result.current.revealChrome());
    expect(result.current.chromeVisible).toBe(true);
  });

  it("toggles, which is what a tap in the middle of the page does", () => {
    const { result } = renderHook(() => useReaderChrome({ enabled: true }));

    act(() => result.current.toggleChrome());
    expect(result.current.chromeVisible).toBe(false);

    act(() => result.current.toggleChrome());
    expect(result.current.chromeVisible).toBe(true);
  });

  it("never hides a control that is being used", () => {
    const { result } = renderHook(() => useReaderChrome({ enabled: true, pinned: true }));

    idle();
    expect(result.current.chromeVisible).toBe(true);
  });

  it("comes back for the keyboard, so Tab never lands on something invisible", () => {
    const { result } = renderHook(() => useReaderChrome({ enabled: true }));
    idle();
    expect(result.current.chromeVisible).toBe(false);

    act(() => { window.dispatchEvent(new KeyboardEvent("keydown", { key: "Tab" })); });
    expect(result.current.chromeVisible).toBe(true);
  });

  it("counts keyboard paging as reading, rather than hiding on the old schedule", () => {
    const { result } = renderHook(() => useReaderChrome({ enabled: true }));

    // Two seconds in, a keypress. The controls must last three more, not one.
    idle(2000);
    act(() => { window.dispatchEvent(new KeyboardEvent("keydown", { key: "ArrowRight" })); });
    idle(2000);

    expect(result.current.chromeVisible).toBe(true);

    idle(2000);
    expect(result.current.chromeVisible).toBe(false);
  });

  it("stays put for a reader who asked for controls that do not fade", () => {
    const { result } = renderHook(() => useReaderChrome({ enabled: false }));

    idle();
    expect(result.current.chromeVisible).toBe(true);
  });

  it("restores faded controls the moment auto-hide is turned off", () => {
    const { result, rerender } = renderHook(({ enabled }) => useReaderChrome({ enabled }), {
      initialProps: { enabled: true },
    });
    idle();
    expect(result.current.chromeVisible).toBe(false);

    rerender({ enabled: false });
    expect(result.current.chromeVisible).toBe(true);
  });
});
