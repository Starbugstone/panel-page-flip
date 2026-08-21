export const READER_DEVICES = Object.freeze(["phone", "tablet", "desktop"]);
export const READER_ORIENTATIONS = Object.freeze(["portrait", "landscape"]);

// Where a touch device stops being a phone, measured on the short edge so the
// answer survives rotation. 600 sits above every phone in landscape and below
// every tablet in portrait.
const TABLET_SHORT_EDGE = 600;

// A window on a pointer device has no fixed short edge — it is whatever the
// user last dragged it to — so only its width says how much room a page has.
const POINTER_PHONE_WIDTH = 600;
const POINTER_TABLET_WIDTH = 1024;

// navigator.deviceMemory is a coarse, optional, spoofable hint. It is allowed
// to shrink the preload window and nothing else.
const LOW_MEMORY_GB = 2;
const HIGH_MEMORY_GB = 8;

function memoryTier(deviceMemory) {
  if (typeof deviceMemory !== "number" || !Number.isFinite(deviceMemory)) return "standard";
  if (deviceMemory <= LOW_MEMORY_GB) return "low";
  if (deviceMemory >= HIGH_MEMORY_GB) return "high";
  return "standard";
}

/**
 * Classify the space and input the reader has, from capabilities rather than
 * from a user-agent string. The result is a responsive classification, not a
 * claim about what hardware this is: a narrow desktop window is a "phone" here
 * because a page has a phone's worth of room, and it says so while still
 * reporting the fine pointer that decides hit targets and hover affordances.
 *
 * Deliberately no pixel measurements in the result. A profile that changed on
 * every pixel of a drag would re-render the reader continuously; this one
 * changes only when the answer does.
 */
export function classifyViewport({
  width = 0,
  height = 0,
  coarsePointer = false,
  touchCapable = coarsePointer,
  hasHover = true,
  deviceMemory,
} = {}) {
  const shortEdge = Math.min(width, height);
  const device = coarsePointer
    ? (shortEdge < TABLET_SHORT_EDGE ? "phone" : "tablet")
    : (width < POINTER_PHONE_WIDTH
      ? "phone"
      : width < POINTER_TABLET_WIDTH ? "tablet" : "desktop");

  return {
    device,
    orientation: height >= width ? "portrait" : "landscape",
    coarsePointer,
    touchCapable,
    hasHover,
    memory: memoryTier(deviceMemory),
  };
}

/** The key an override is stored against: what the reader is, not who is using it. */
export function viewportContextKey({ device, orientation } = {}) {
  return `${device}:${orientation}`;
}

const SUGGESTED_FITS = Object.freeze({
  "phone:portrait": "width",
  "phone:landscape": "height",
  "tablet:portrait": "contain",
  "tablet:landscape": "contain",
  "desktop:portrait": "contain",
  "desktop:landscape": "contain",
});

/**
 * The fit this shape of screen reads best at. A suggestion only: rotation must
 * never rewrite a choice the reader has made for itself.
 */
export function suggestedFitFor(profile) {
  return SUGGESTED_FITS[viewportContextKey(profile)] ?? "contain";
}

const DEVICE_LABELS = Object.freeze({ phone: "this phone", tablet: "this tablet", desktop: "this screen" });

export function describeViewportContext({ device, orientation } = {}) {
  return `${DEVICE_LABELS[device] ?? "this screen"} in ${orientation === "landscape" ? "landscape" : "portrait"}`;
}
