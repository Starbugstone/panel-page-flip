import { describe, expect, it } from "vitest";

import { preloadWindowFor, readNetworkHints } from "./reader-preload";

describe("how far ahead to read", () => {
  it("gives a desktop more room than a phone", () => {
    const desktop = preloadWindowFor({ device: "desktop", memory: "standard" });
    const phone = preloadWindowFor({ device: "phone", memory: "standard" });

    expect(desktop.forward).toBeGreaterThan(phone.forward);
    expect(desktop.backward).toBeGreaterThanOrEqual(phone.backward);
  });

  it("shrinks the window on a device that says it has little memory", () => {
    const standard = preloadWindowFor({ device: "tablet", memory: "standard" });
    const low = preloadWindowFor({ device: "tablet", memory: "low" });

    expect(low.forward).toBeLessThan(standard.forward);
    expect(low.forward).toBeGreaterThanOrEqual(1);
  });

  it("still keeps the next page, however constrained the device", () => {
    expect(preloadWindowFor({ device: "phone", memory: "low" })).toEqual({ backward: 0, forward: 1 });
  });

  it("fetches only what is about to be read when the user has asked to save data", () => {
    expect(preloadWindowFor({ device: "desktop", memory: "high" }, { saveData: true }))
      .toEqual({ backward: 0, forward: 1 });
  });

  it("treats a 2g connection the same way", () => {
    expect(preloadWindowFor({ device: "desktop" }, { effectiveType: "2g" }).forward).toBe(1);
    expect(preloadWindowFor({ device: "desktop" }, { effectiveType: "4g" }).forward).toBe(5);
  });

  it("assumes the smallest sensible window for a device it cannot classify", () => {
    expect(preloadWindowFor()).toEqual({ backward: 1, forward: 2 });
  });
});

describe("reading the network hints", () => {
  it("reports nothing rather than throwing where the API is absent", () => {
    expect(readNetworkHints({})).toEqual({ saveData: false, effectiveType: undefined });
    expect(readNetworkHints(undefined)).toEqual({ saveData: false, effectiveType: undefined });
  });

  it("passes on what the browser does report", () => {
    expect(readNetworkHints({ connection: { saveData: true, effectiveType: "3g" } }))
      .toEqual({ saveData: true, effectiveType: "3g" });
  });
});
