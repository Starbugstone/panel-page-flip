import { describe, expect, it } from "vitest";

import {
  classifyViewport,
  describeViewportContext,
  suggestedFitFor,
  viewportContextKey,
} from "./reader-viewport";

const phonePortrait = { width: 390, height: 844, coarsePointer: true, hasHover: false };
const tabletPortrait = { width: 820, height: 1180, coarsePointer: true, hasHover: false };

describe("classifying what the reader has to work with", () => {
  it("keeps a touch device the same device through a rotation", () => {
    const portrait = classifyViewport(phonePortrait);
    const landscape = classifyViewport({ ...phonePortrait, width: 844, height: 390 });

    expect(portrait.device).toBe("phone");
    expect(landscape.device).toBe("phone");
    expect(portrait.orientation).toBe("portrait");
    expect(landscape.orientation).toBe("landscape");
  });

  it("calls a tablet a tablet in either orientation", () => {
    expect(classifyViewport(tabletPortrait).device).toBe("tablet");
    expect(classifyViewport({ ...tabletPortrait, width: 1180, height: 820 }).device).toBe("tablet");
  });

  it("judges a pointer window by its width, because its height is not a device property", () => {
    // A laptop window dragged short is still a laptop; the same short edge on a
    // touch device would be a tablet.
    expect(classifyViewport({ width: 1280, height: 700, coarsePointer: false }).device).toBe("desktop");
    expect(classifyViewport({ width: 1280, height: 700, coarsePointer: true }).device).toBe("tablet");
  });

  it("treats a narrow desktop window as phone-sized without claiming it is touch", () => {
    const profile = classifyViewport({ width: 420, height: 900, coarsePointer: false, hasHover: true });

    expect(profile.device).toBe("phone");
    expect(profile.coarsePointer).toBe(false);
    expect(profile.hasHover).toBe(true);
  });

  it("reads device memory as a coarse tier and tolerates its absence", () => {
    expect(classifyViewport({ ...phonePortrait, deviceMemory: 1 }).memory).toBe("low");
    expect(classifyViewport({ ...phonePortrait, deviceMemory: 4 }).memory).toBe("standard");
    expect(classifyViewport({ ...phonePortrait, deviceMemory: 8 }).memory).toBe("high");
    expect(classifyViewport(phonePortrait).memory).toBe("standard");
  });

  it("survives being asked before anything has been measured", () => {
    expect(classifyViewport()).toMatchObject({ device: "phone", orientation: "portrait" });
  });
});

describe("what each shape of screen reads best at", () => {
  it("suggests fit width for a phone held upright", () => {
    expect(suggestedFitFor(classifyViewport(phonePortrait))).toBe("width");
  });

  it("keeps a whole page on screen everywhere a page fits", () => {
    expect(suggestedFitFor(classifyViewport({ ...phonePortrait, width: 844, height: 390 }))).toBe("height");
    expect(suggestedFitFor(classifyViewport(tabletPortrait))).toBe("contain");
    expect(suggestedFitFor(classifyViewport({ width: 1440, height: 900 }))).toBe("contain");
  });

  it("falls back to best fit for a context it has no opinion about", () => {
    expect(suggestedFitFor({ device: "watch", orientation: "square" })).toBe("contain");
  });
});

describe("naming a context", () => {
  it("keys an override by shape rather than by hardware", () => {
    expect(viewportContextKey(classifyViewport(phonePortrait))).toBe("phone:portrait");
  });

  it("describes a context in words a reader would use", () => {
    expect(describeViewportContext(classifyViewport(tabletPortrait))).toBe("this tablet in portrait");
  });
});
