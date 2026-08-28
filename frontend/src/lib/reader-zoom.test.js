import { describe, expect, it } from "vitest";

import {
  IDENTITY_TRANSFORM,
  MAX_SCALE,
  clampTransform,
  doubleTapTransform,
  originAtTop,
  isZoomed,
  panBy,
  readableWidthScale,
  scrollFromTransform,
  stepZoom,
  transformFromScroll,
  zoomAbout,
} from "./reader-zoom";

const viewport = { width: 400, height: 800 };
// A page fitted with letterboxing either side, as "best fit" leaves it on a phone.
const content = { width: 300, height: 800 };
const geometry = { viewport, content };

/** Where a point of the viewport ends up once the transform is applied. */
const project = ({ scale, x, y }, point) => ({
  x: viewport.width / 2 + (point.x - viewport.width / 2) * scale + x,
  y: viewport.height / 2 + (point.y - viewport.height / 2) * scale + y,
});

describe("zooming about a point", () => {
  it("leaves the point under the finger where it was", () => {
    const focal = { x: 120, y: 200 };
    const zoomed = zoomAbout(IDENTITY_TRANSFORM, focal, 2, viewport);

    // The image point that was under the focal point projects back onto it.
    const source = {
      x: viewport.width / 2 + (focal.x - viewport.width / 2) / 1,
      y: viewport.height / 2 + (focal.y - viewport.height / 2) / 1,
    };
    expect(project(zoomed, source).x).toBeCloseTo(focal.x, 5);
    expect(project(zoomed, source).y).toBeCloseTo(focal.y, 5);
  });

  it("composes repeated pinches into one scale", () => {
    const once = zoomAbout(IDENTITY_TRANSFORM, { x: 200, y: 400 }, 1.5, viewport);
    const twice = zoomAbout(once, { x: 200, y: 400 }, 2, viewport);

    expect(twice.scale).toBeCloseTo(3, 5);
  });

  it("refuses to go below the fit the reader chose, or beyond legibility", () => {
    expect(zoomAbout(IDENTITY_TRANSFORM, { x: 0, y: 0 }, 0.2, viewport).scale).toBe(1);
    expect(zoomAbout(IDENTITY_TRANSFORM, { x: 0, y: 0 }, 50, viewport).scale).toBe(MAX_SCALE);
  });

  it("holds the focal point against the scale it actually got, not the one asked for", () => {
    // Pinching out hard from 1x: most of the factor is refused, and the page
    // must not lurch sideways by the part that never happened.
    const focal = { x: 40, y: 40 };
    const clamped = zoomAbout(IDENTITY_TRANSFORM, focal, 50, viewport);
    const stepwise = zoomAbout(IDENTITY_TRANSFORM, focal, MAX_SCALE, viewport);

    expect(clamped).toEqual(stepwise);
  });
});

describe("keeping a panned page over the viewport", () => {
  it("stops at the edge of the artwork", () => {
    const zoomed = { scale: 2, x: 0, y: 0 };
    const dragged = panBy(zoomed, 1000, 1000);

    const bounded = clampTransform(dragged, geometry);
    // 300 * 2 = 600 wide against a 400 viewport: 100 of slack each way.
    expect(bounded.x).toBe(100);
    expect(bounded.y).toBe(400);
  });

  it("centres an axis with nothing hidden", () => {
    const bounded = clampTransform({ scale: 1, x: 90, y: 0 }, geometry);

    expect(bounded.x).toBe(0);
  });

  it("keeps a pan that stays inside the artwork exactly as dragged", () => {
    expect(clampTransform({ scale: 2, x: -40, y: 25 }, geometry)).toEqual({ scale: 2, x: -40, y: 25 });
  });
});

describe("the top of a zoomed page", () => {
  it("is the pan that puts the artwork's top edge on the viewport's top edge", () => {
    // 800 tall at 2x in an 800 viewport: 400 of slack each way, and the top is
    // the positive one — the same edge a flick upward would stop at.
    expect(originAtTop(2, geometry)).toEqual({ scale: 2, x: 0, y: 400 });
  });

  it("stays centred when the zoomed page still fits on screen", () => {
    const fitted = { viewport, content: { width: 200, height: 400 } };

    expect(originAtTop(1.5, fitted)).toEqual({ scale: 1.5, x: 0, y: 0 });
  });
});

describe("double tap", () => {
  it("zooms a letterboxed page to the width it is read at", () => {
    const zoomed = doubleTapTransform(IDENTITY_TRANSFORM, { x: 120, y: 200 }, geometry);

    expect(zoomed.scale).toBeCloseTo(400 / 300, 5);
  });

  it("steps a page that already fills the width, having no readable width to find", () => {
    const fullWidth = { viewport, content: { width: 400, height: 1600 } };

    expect(doubleTapTransform(IDENTITY_TRANSFORM, { x: 200, y: 200 }, fullWidth).scale).toBe(2);
  });

  it("puts a zoomed page back, whatever it was zoomed to", () => {
    expect(doubleTapTransform({ scale: 3.4, x: -80, y: 120 }, { x: 10, y: 10 }, geometry))
      .toEqual(IDENTITY_TRANSFORM);
  });

  it("does not leave the page hanging off the viewport", () => {
    const zoomed = doubleTapTransform(IDENTITY_TRANSFORM, { x: 0, y: 0 }, geometry);

    expect(zoomed).toEqual(clampTransform(zoomed, geometry));
  });
});

describe("the zoom buttons", () => {
  it("step about the middle of the page rather than a pointer that may not exist", () => {
    const zoomed = stepZoom(IDENTITY_TRANSFORM, 2, geometry);

    expect(zoomed).toEqual({ scale: 2, x: 0, y: 0 });
  });

  it("come back down to a whole page", () => {
    const zoomed = stepZoom(IDENTITY_TRANSFORM, 2, geometry);

    expect(isZoomed(stepZoom(zoomed, 0.5, geometry))).toBe(false);
  });
});

describe("what counts as zoomed", () => {
  it("ignores the float dust a pinch leaves behind", () => {
    expect(isZoomed(IDENTITY_TRANSFORM)).toBe(false);
    expect(isZoomed({ scale: 1.0000001, x: 0, y: 0 })).toBe(false);
    expect(isZoomed({ scale: 1.4, x: 0, y: 0 })).toBe(true);
  });
});

describe("readable width", () => {
  it("falls back rather than dividing by a page it has not measured", () => {
    expect(readableWidthScale({ viewport, content: { width: 0, height: 0 } })).toBe(2);
    expect(readableWidthScale({})).toBe(2);
  });
});

describe("a scrolled page and a panned page", () => {
  // A page fitted to the width of a phone: twice as tall as the screen.
  const tall = { viewport: { width: 400, height: 800 }, content: { width: 400, height: 1600 } };

  it("describes the same picture either way", () => {
    const scrolled = { scrollLeft: 0, scrollTop: 300 };
    const transform = transformFromScroll({ ...tall, ...scrolled });

    expect(scrollFromTransform(transform, tall)).toEqual(scrolled);
  });

  it("puts the top of a page at the top, not the middle", () => {
    const transform = transformFromScroll({ ...tall, scrollTop: 0 });

    // 1600 tall in an 800 viewport: the untransformed centre sits 400 too low.
    expect(transform.y).toBe(400);
    expect(clampTransform(transform, tall)).toEqual(transform);
  });

  it("never asks a container to scroll to a negative offset", () => {
    const wide = { viewport: { width: 400, height: 800 }, content: { width: 300, height: 800 } };

    expect(scrollFromTransform({ scale: 1, x: 0, y: 0 }, wide)).toEqual({ scrollLeft: 0, scrollTop: 0 });
  });
});
