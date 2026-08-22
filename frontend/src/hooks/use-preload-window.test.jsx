import { act, renderHook } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { usePreloadWindow } from "./use-preload-window";

/** Stands in for navigator.connection, which jsdom does not implement. */
function connectionOf(initial) {
  const listeners = new Set();
  const connection = {
    ...initial,
    addEventListener: (_type, listener) => listeners.add(listener),
    removeEventListener: (_type, listener) => listeners.delete(listener),
    change(next) {
      Object.assign(connection, next);
      listeners.forEach((listener) => listener());
    },
  };
  vi.stubGlobal("navigator", { ...globalThis.navigator, connection });
  return connection;
}

const phone = { device: "phone", memory: "standard" };

describe("the preload window", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("reads the connection the reader opened on", () => {
    connectionOf({ effectiveType: "2g" });
    const { result } = renderHook(() => usePreloadWindow(phone));

    expect(result.current).toEqual({ backward: 0, forward: 1 });
  });

  it("follows the connection changing under a reader mid-session", () => {
    const connection = connectionOf({ effectiveType: "4g" });
    const { result } = renderHook(() => usePreloadWindow(phone));
    expect(result.current.forward).toBe(2);

    act(() => connection.change({ effectiveType: "2g" }));
    expect(result.current.forward).toBe(1);

    act(() => connection.change({ effectiveType: "4g" }));
    expect(result.current.forward).toBe(2);
  });

  it("works where the browser reports no connection at all", () => {
    vi.stubGlobal("navigator", {});
    const { result } = renderHook(() => usePreloadWindow(phone));

    expect(result.current).toEqual({ backward: 1, forward: 2 });
  });
});
