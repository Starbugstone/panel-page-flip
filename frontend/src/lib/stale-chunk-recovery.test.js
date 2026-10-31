import { describe, expect, it, vi } from "vitest";
import { installStaleChunkRecovery, reloadOnceForStaleChunk } from "./stale-chunk-recovery.js";

function fakeWindow({ state = null, onLine = true } = {}) {
  const win = new EventTarget();
  win.navigator = { onLine };
  win.location = { reload: vi.fn() };
  win.history = {
    state,
    replaceState: vi.fn((next) => {
      win.history.state = next;
    }),
  };
  return win;
}

describe("recovering from a chunk removed by a deployment", () => {
  it("reloads when Vite reports a chunk failed to load", () => {
    const win = fakeWindow();
    installStaleChunkRecovery(win);

    win.dispatchEvent(new Event("vite:preloadError", { cancelable: true }));

    expect(win.location.reload).toHaveBeenCalledTimes(1);
  });

  it("does not reload again when the chunk still fails after the reload", () => {
    const win = fakeWindow();
    installStaleChunkRecovery(win);

    win.dispatchEvent(new Event("vite:preloadError"));
    win.dispatchEvent(new Event("vite:preloadError"));
    win.dispatchEvent(new Event("vite:preloadError"));

    expect(win.location.reload).toHaveBeenCalledTimes(1);
  });

  it("leaves the failure to surface once it has given up", () => {
    const win = fakeWindow();
    installStaleChunkRecovery(win);
    const event = new Event("vite:preloadError", { cancelable: true });

    win.dispatchEvent(new Event("vite:preloadError"));
    win.dispatchEvent(event);

    expect(event.defaultPrevented).toBe(false);
  });

  it("recovers again from a later deployment in a tab left open", () => {
    const longAgo = Date.now() - 3600000;
    const win = fakeWindow({ state: { staleChunkReloadAt: longAgo } });

    expect(reloadOnceForStaleChunk(win)).toBe(true);
    expect(win.location.reload).toHaveBeenCalledTimes(1);
  });

  it("keeps the router's own history state", () => {
    const win = fakeWindow({ state: { idx: 3, key: "abc" } });

    reloadOnceForStaleChunk(win);

    expect(win.history.state).toMatchObject({ idx: 3, key: "abc" });
  });

  it("does not reload while the browser is offline", () => {
    const win = fakeWindow({ onLine: false });

    expect(reloadOnceForStaleChunk(win)).toBe(false);
    expect(win.location.reload).not.toHaveBeenCalled();
  });

  it("does not reload when it cannot record the attempt", () => {
    const win = fakeWindow();
    win.history.replaceState = vi.fn(() => {
      throw new Error("SecurityError");
    });

    expect(reloadOnceForStaleChunk(win)).toBe(false);
    expect(win.location.reload).not.toHaveBeenCalled();
  });
});
