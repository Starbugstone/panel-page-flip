import { readerFitAppearance } from "@/lib/reader-fit";

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
  const { safeFit, viewportClass, touchAction } = readerFitAppearance(fit, zoomed);
  const settled = isSwiping || zoomed ? "" : "transition-transform duration-200 motion-reduce:transition-none";

  return {
    safeFit,
    viewportClass,
    touchAction,
    imageClass: `${IMAGE_CLASSES[safeFit]} mx-auto block select-none shadow-lg ${zoomed ? "zoomed-image" : ""} ${settled}`,
    // Artwork held over from the previous page is decoration, not this page, so
    // it is not announced as the page it is standing in for.
    alt: isStale ? "" : `Page ${pageNumber} of ${title || "Comic"}`,
    ariaHidden: isStale ? "true" : undefined,
  };
}
