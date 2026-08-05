import { describe, expect, it, vi } from "vitest";
import {
  persistCookieNoticeDismissal,
  wasCookieNoticeDismissed,
} from "@/lib/cookie-notice-storage";

describe("cookie notice storage", () => {
  it("stays visible when browser storage is unavailable", () => {
    const blockedStorage = {
      getItem: () => {
        throw new Error("storage blocked");
      },
    };

    expect(wasCookieNoticeDismissed(blockedStorage)).toBe(false);
  });

  it("handles browsers that reject access to the storage property", () => {
    const originalDescriptor = Object.getOwnPropertyDescriptor(globalThis, "localStorage");
    Object.defineProperty(globalThis, "localStorage", {
      configurable: true,
      get: () => {
        throw new Error("storage access blocked");
      },
    });

    try {
      expect(wasCookieNoticeDismissed()).toBe(false);
      expect(() => persistCookieNoticeDismissal()).not.toThrow();
    } finally {
      if (originalDescriptor) {
        Object.defineProperty(globalThis, "localStorage", originalDescriptor);
      } else {
        delete globalThis.localStorage;
      }
    }
  });

  it("does not throw when dismissal cannot be persisted", () => {
    const blockedStorage = {
      setItem: vi.fn(() => {
        throw new Error("storage blocked");
      }),
    };

    expect(() => persistCookieNoticeDismissal(blockedStorage)).not.toThrow();
    expect(blockedStorage.setItem).toHaveBeenCalledOnce();
  });
});
