import { act, renderHook } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { useViewportProfile } from "./use-viewport-profile";

function screenOf({ width, height, coarsePointer = false, hasHover = true }) {
  window.innerWidth = width;
  window.innerHeight = height;
  window.matchMedia = (query) => ({
    matches: query === "(pointer: coarse)" ? coarsePointer : hasHover,
    media: query,
    addEventListener: () => {},
    removeEventListener: () => {},
  });
}

const resize = () => act(() => { window.dispatchEvent(new Event("resize")); });

describe("following the screen the reader is on", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("classifies the screen it starts on", () => {
    screenOf({ width: 390, height: 844, coarsePointer: true, hasHover: false });
    const { result } = renderHook(() => useViewportProfile());

    expect(result.current).toMatchObject({ device: "phone", orientation: "portrait", coarsePointer: true });
  });

  it("follows a rotation", () => {
    screenOf({ width: 820, height: 1180, coarsePointer: true, hasHover: false });
    const { result } = renderHook(() => useViewportProfile());
    expect(result.current.orientation).toBe("portrait");

    screenOf({ width: 1180, height: 820, coarsePointer: true, hasHover: false });
    resize();

    expect(result.current).toMatchObject({ device: "tablet", orientation: "landscape" });
  });

  it("hands back the same profile when a resize does not change the answer", () => {
    screenOf({ width: 1440, height: 900 });
    const { result } = renderHook(() => useViewportProfile());
    const first = result.current;

    screenOf({ width: 1400, height: 880 });
    resize();

    // Identity, not equality: a new object here re-renders the whole reader on
    // every pixel of a window drag.
    expect(result.current).toBe(first);
  });
});
