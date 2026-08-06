import { describe, expect, it } from "vitest";
import {
  NOTIFICATION_LAYER_CLASSES,
  OVERLAY_LAYER_CLASSES,
  PAGE_LAYER_CLASSES,
  overlayLayerClass,
} from "@/lib/overlay-layers.js";

/** "z-50" -> 50, "z-[80]" -> 80 */
const zIndexOf = (className) => Number(className.replace(/^z-\[?|\]?$/g, ""));

describe("overlayLayerClass", () => {
  it("puts a page-level dropdown below a page-level modal", () => {
    expect(zIndexOf(overlayLayerClass("popover", 0)))
      .toBeLessThan(zIndexOf(overlayLayerClass("modalOverlay", 0)));
  });

  it("keeps modal content above its own overlay", () => {
    expect(zIndexOf(overlayLayerClass("modalContent", 0)))
      .toBeGreaterThan(zIndexOf(overlayLayerClass("modalOverlay", 0)));
  });

  it("puts a popover opened inside a modal above that modal", () => {
    expect(zIndexOf(overlayLayerClass("popover", 1)))
      .toBeGreaterThan(zIndexOf(overlayLayerClass("modalContent", 0)));
  });

  it("puts a modal opened inside a modal above the popovers of its parent", () => {
    expect(zIndexOf(overlayLayerClass("modalOverlay", 1)))
      .toBeGreaterThan(zIndexOf(overlayLayerClass("popover", 1)));
  });

  it("clamps depth beyond the deepest defined step instead of returning nothing", () => {
    const deepest = OVERLAY_LAYER_CLASSES.popover.at(-1);

    expect(overlayLayerClass("popover", 9)).toBe(deepest);
  });

  it("treats a missing or nonsensical depth as page level", () => {
    expect(overlayLayerClass("popover")).toBe(OVERLAY_LAYER_CLASSES.popover[0]);
    expect(overlayLayerClass("popover", -3)).toBe(OVERLAY_LAYER_CLASSES.popover[0]);
    expect(overlayLayerClass("popover", Number.NaN)).toBe(OVERLAY_LAYER_CLASSES.popover[0]);
  });

  it("rejects an unknown role rather than silently rendering unlayered", () => {
    expect(() => overlayLayerClass("banner")).toThrow(/Unknown overlay role/);
  });
});

describe("the layer scale as a whole", () => {
  it("leaves page furniture below every overlay", () => {
    const lowestOverlay = zIndexOf(overlayLayerClass("popover", 0));

    for (const className of Object.values(PAGE_LAYER_CLASSES)) {
      expect(zIndexOf(className)).toBeLessThan(lowestOverlay);
    }
  });

  it("keeps notifications above the deepest modal", () => {
    const deepestModal = zIndexOf(OVERLAY_LAYER_CLASSES.modalContent.at(-1));

    expect(zIndexOf(NOTIFICATION_LAYER_CLASSES.cookieNotice)).toBeGreaterThan(deepestModal);
    expect(zIndexOf(NOTIFICATION_LAYER_CLASSES.toast))
      .toBeGreaterThan(zIndexOf(NOTIFICATION_LAYER_CLASSES.cookieNotice));
  });

  it("never reuses a value across roles, so ordering never depends on portal order", () => {
    const values = Object.values(OVERLAY_LAYER_CLASSES).flat().map(zIndexOf);

    expect(new Set(values).size).toBe(values.length);
  });
});
