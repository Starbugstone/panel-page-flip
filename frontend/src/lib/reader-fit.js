const VIEWPORT_CLASSES = {
  contain: "items-center justify-center overflow-hidden",
  width: "items-start justify-center overflow-x-hidden overflow-y-auto",
  height: "items-center justify-center overflow-hidden",
  original: "items-start justify-start overflow-auto",
};

// A zoomed page is positioned entirely by its transform, which is measured from
// the centre of the viewport. The fit's own alignment and scrolling would move
// the page underneath that and every pan would be off by the difference.
const ZOOMED_CLASSES = "items-center justify-center overflow-hidden";

// What the browser may keep for itself while the page is at natural scale.
// A page at original size overflows in both directions and the browser is the
// one scrolling it, so taking the horizontal axis for page turns here would
// make the right-hand side of a wide page unreachable.
const TOUCH_ACTION = {
  contain: "pan-y",
  width: "pan-y",
  height: "pan-y",
  original: "pan-x pan-y",
};

/**
 * How a reading unit's viewport behaves at a given fit.
 *
 * Shared by both readers because they are two presentations of one setting: a
 * fit that letterboxes a single page has to letterbox a spread the same way,
 * and while these tables lived in both files they could drift apart without
 * either side looking wrong on its own.
 */
export function readerFitAppearance(fit, zoomed) {
  const safeFit = Object.hasOwn(VIEWPORT_CLASSES, fit) ? fit : "contain";

  return {
    safeFit,
    viewportClass: zoomed ? ZOOMED_CLASSES : VIEWPORT_CLASSES[safeFit],
    // A zoomed page is moved entirely by the gestures; a fitted one still
    // scrolls the way every other page on the web does.
    touchAction: zoomed ? "none" : TOUCH_ACTION[safeFit],
  };
}
