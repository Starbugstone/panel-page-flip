import { render, renderHook, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { act } from "react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { useIsMobile } from "./use-mobile";
import SessionMonitor from "@/components/SessionMonitor";

const auth = vi.hoisted(() => ({ sessionExpired: false }));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => auth }));

/** A matchMedia whose result the test controls, with real listeners. */
function stubMatchMedia(initiallyMatches) {
  const listeners = new Set();
  let matches = initiallyMatches;

  window.matchMedia = (query) => ({
    get matches() { return matches; },
    media: query,
    addEventListener: (_, fn) => listeners.add(fn),
    removeEventListener: (_, fn) => listeners.delete(fn),
    addListener: (fn) => listeners.add(fn),
    removeListener: (fn) => listeners.delete(fn),
    dispatchEvent: () => false,
  });

  return {
    set(next) {
      matches = next;
      act(() => { listeners.forEach((fn) => fn()); });
    },
    get listenerCount() { return listeners.size; },
  };
}

describe("useIsMobile", () => {
  const originalMatchMedia = window.matchMedia;
  afterEach(() => { window.matchMedia = originalMatchMedia; });

  it("knows the viewport on the very first render", () => {
    stubMatchMedia(true);

    const { result } = renderHook(() => useIsMobile());

    // Mirroring the viewport into state from an effect meant the first render
    // always said "not mobile", so a mobile visitor got the desktop layout for
    // a frame before it swapped.
    expect(result.current).toBe(true);
  });

  it("follows the viewport when it changes", () => {
    const media = stubMatchMedia(false);
    const { result } = renderHook(() => useIsMobile());

    expect(result.current).toBe(false);

    media.set(true);
    expect(result.current).toBe(true);

    media.set(false);
    expect(result.current).toBe(false);
  });

  it("unsubscribes when the last consumer goes", () => {
    const media = stubMatchMedia(false);
    const { unmount } = renderHook(() => useIsMobile());

    expect(media.listenerCount).toBe(1);
    unmount();
    expect(media.listenerCount).toBe(0);
  });
});

describe("SessionMonitor", () => {
  it("stays quiet while the session is good", () => {
    auth.sessionExpired = false;

    render(<SessionMonitor />);

    expect(screen.queryByText(/session expired/i)).not.toBeInTheDocument();
  });

  it("announces an expired session", () => {
    auth.sessionExpired = true;

    render(<SessionMonitor />);

    expect(screen.getByText(/session expired/i)).toBeInTheDocument();
  });

  it("cannot be dismissed while the session is still expired", async () => {
    const user = userEvent.setup();
    auth.sessionExpired = true;

    render(<SessionMonitor />);
    expect(screen.getByText(/session expired/i)).toBeInTheDocument();

    // Escape used to close it, because "open" was a copy of sessionExpired
    // rather than sessionExpired itself. That left the reader in an application
    // that could no longer reach the server and no longer said so.
    await user.keyboard("{Escape}");

    expect(screen.getByText(/session expired/i)).toBeInTheDocument();
  });
});
