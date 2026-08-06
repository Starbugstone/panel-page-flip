import { describe, expect, it, vi } from "vitest";
import { getFullscreenTarget, isFullscreenActive, toggleFullscreen } from "@/lib/fullscreen";

function fakeDocument({ fullscreenElement = null } = {}) {
  const documentElement = { requestFullscreen: vi.fn(() => Promise.resolve()) };
  return {
    documentElement,
    fullscreenElement,
    exitFullscreen: vi.fn(() => Promise.resolve()),
  };
}

describe("reader fullscreen", () => {
  it("takes the document element fullscreen so the side navigation zones come along", () => {
    const doc = fakeDocument();

    expect(getFullscreenTarget(doc)).toBe(doc.documentElement);
    expect(toggleFullscreen(doc)).toBe(true);
    expect(doc.documentElement.requestFullscreen).toHaveBeenCalledTimes(1);
    expect(doc.exitFullscreen).not.toHaveBeenCalled();
  });

  it("exits when already fullscreen", () => {
    const doc = fakeDocument({ fullscreenElement: {} });

    expect(isFullscreenActive(doc)).toBe(true);
    expect(toggleFullscreen(doc)).toBe(false);
    expect(doc.exitFullscreen).toHaveBeenCalledTimes(1);
    expect(doc.documentElement.requestFullscreen).not.toHaveBeenCalled();
  });

  it("reports no change when the browser has no fullscreen API", () => {
    expect(toggleFullscreen({ documentElement: {} })).toBe(false);
    expect(toggleFullscreen({ documentElement: {}, fullscreenElement: {} })).toBe(true);
    expect(isFullscreenActive(undefined)).toBe(false);
    expect(getFullscreenTarget(undefined)).toBe(null);
  });

  it("swallows a rejected fullscreen request instead of leaving an unhandled rejection", async () => {
    const doc = fakeDocument();
    doc.documentElement.requestFullscreen = vi.fn(() => Promise.reject(new Error("denied")));

    expect(() => toggleFullscreen(doc)).not.toThrow();
    await Promise.resolve();
  });

  it("tolerates a fullscreen API that returns nothing instead of a promise", () => {
    const doc = fakeDocument();
    doc.documentElement.requestFullscreen = vi.fn(() => undefined);

    expect(() => toggleFullscreen(doc)).not.toThrow();
  });
});
