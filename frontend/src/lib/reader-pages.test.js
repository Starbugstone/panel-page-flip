import { describe, expect, it } from "vitest";

import {
  createPageManifestUrl,
  createPageThumbnailUrl,
  selectPageVariant,
  withForcedReload,
} from "./reader-pages";

describe("selectPageVariant", () => {
  it("asks for a page that covers the pixels it will occupy, and no more", () => {
    expect(selectPageVariant({ cssWidth: 600, pixelRatio: 1 })).toBe("reader-small");
    expect(selectPageVariant({ cssWidth: 900, pixelRatio: 1 })).toBe("reader-medium");
    expect(selectPageVariant({ cssWidth: 1600, pixelRatio: 1 })).toBe("reader-large");
  });

  it("counts device pixels, not CSS pixels", () => {
    // The same 600-pixel column: a laptop can use the small page, a phone at
    // three device pixels each cannot.
    expect(selectPageVariant({ cssWidth: 600, pixelRatio: 1 })).toBe("reader-small");
    expect(selectPageVariant({ cssWidth: 600, pixelRatio: 2 })).toBe("reader-medium");
    expect(selectPageVariant({ cssWidth: 600, pixelRatio: 3 })).toBe("reader-large");
  });

  it("stops counting device pixels somewhere short of absurdity", () => {
    expect(selectPageVariant({ cssWidth: 300, pixelRatio: 12 })).toBe("reader-medium");
  });

  it("moves up a rung when the reader zooms in", () => {
    expect(selectPageVariant({ cssWidth: 600, pixelRatio: 1, zoomLevel: 2 })).toBe("reader-medium");
    expect(selectPageVariant({ cssWidth: 600, pixelRatio: 2, zoomLevel: 2 })).toBe("reader-large");
  });

  it("never reaches for the source page, however far it is zoomed", () => {
    expect(selectPageVariant({ cssWidth: 2000, pixelRatio: 3, zoomLevel: 5 })).toBe("reader-large");
  });

  it("falls back to a middle rung when there is nothing to measure yet", () => {
    expect(selectPageVariant({ cssWidth: 0 })).toBe("reader-medium");
    expect(selectPageVariant({})).toBe("reader-medium");
  });
});

describe("page urls", () => {
  it("asks for thumbnails from the same endpoint as pages", () => {
    expect(createPageThumbnailUrl(42, 7)).toBe("/api/comics/42/pages/7?variant=thumb");
    expect(createPageThumbnailUrl(42, 0)).toBeNull();
  });

  it("asks the manifest to resolve from where the reader is", () => {
    expect(createPageManifestUrl(42)).toBe("/api/comics/42/pages?from=1");
    expect(createPageManifestUrl(42, 40)).toBe("/api/comics/42/pages?from=40");
  });

  it("keeps the variant when a reload is forced past the browser cache", () => {
    const url = withForcedReload("/api/comics/42/pages/1?variant=reader-large");

    expect(url).toContain("variant=reader-large");
    expect(url).toMatch(/[?&]_force_reload=\d+$/);
  });
});
