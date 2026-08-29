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

const IMAGE_CLASSES = {
  contain: "max-h-full max-w-full h-auto w-auto object-contain",
  width: "h-auto w-full max-h-none max-w-none object-contain",
  height: "h-full w-auto max-h-full max-w-none object-contain",
  original: "h-auto w-auto max-h-none max-w-none object-none",
};

/**
 * How a single page presents itself: how its viewport is laid out, what the
 * browser may still do with a touch, and how the artwork is sized.
 *
 * Kept apart from the component because it is a table lookup with two
 * modifiers, and reading it inline meant reading four class strings to answer
 * one question about the fit.
 */
export function singlePageAppearance({ fit, zoomed, isSwiping, isStale, pageNumber, title }) {
  const safeFit = Object.hasOwn(VIEWPORT_CLASSES, fit) ? fit : "contain";
  const settled = isSwiping || zoomed ? "" : "transition-transform duration-200 motion-reduce:transition-none";

  return {
    safeFit,
    viewportClass: zoomed ? ZOOMED_CLASSES : VIEWPORT_CLASSES[safeFit],
    // A zoomed page is moved entirely by the gestures; a fitted one still
    // scrolls the way every other page on the web does.
    touchAction: zoomed ? "none" : TOUCH_ACTION[safeFit],
    imageClass: `${IMAGE_CLASSES[safeFit]} mx-auto block select-none shadow-lg ${zoomed ? "zoomed-image" : ""} ${settled}`,
    // Artwork held over from the previous page is decoration, not this page, so
    // it is not announced as the page it is standing in for.
    alt: isStale ? "" : `Page ${pageNumber} of ${title || "Comic"}`,
    ariaHidden: isStale ? "true" : undefined,
  };
}
