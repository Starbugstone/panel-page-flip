/**
 * The reader's transform state, as arithmetic.
 *
 * One `{ scale, x, y }` describes what pinch, pan, double tap and the zoom
 * buttons all produce, and it maps directly onto
 * `translate(Xpx, Ypx) scale(S)` about the page's own centre. Keeping the maths
 * here means the gesture layer can stay geometry-free and every rule about what
 * a zoom may not do is a function with a test rather than a CSS accident.
 */

export const IDENTITY_TRANSFORM = Object.freeze({ scale: 1, x: 0, y: 0 });

// Below 1 the page would be smaller than the fit the reader chose, which is a
// different setting, not a zoom. Above 5 a comic page is pixels.
export const MIN_SCALE = 1;
export const MAX_SCALE = 5;

const clamp = (value, low, high) => Math.min(high, Math.max(low, value));

export function isZoomed({ scale } = IDENTITY_TRANSFORM) {
  return scale > MIN_SCALE + 0.001;
}

/**
 * Hold `focal` still while the scale changes by `factor`.
 *
 * Pinching around the midpoint of two fingers and double-tapping a panel are
 * the same operation: the point under the finger is the one that must not move.
 */
export function zoomAbout(transform, focal, factor, viewport) {
  const scale = clamp(transform.scale * factor, MIN_SCALE, MAX_SCALE);
  // Clamping may have refused some of the requested factor; the focal point has
  // to be held against what actually happened, not what was asked for.
  const applied = transform.scale > 0 ? scale / transform.scale : 1;
  const centreX = viewport.width / 2;
  const centreY = viewport.height / 2;

  return {
    scale,
    x: (focal.x - centreX) * (1 - applied) + transform.x * applied,
    y: (focal.y - centreY) * (1 - applied) + transform.y * applied,
  };
}

export function panBy(transform, dx, dy) {
  return { ...transform, x: transform.x + dx, y: transform.y + dy };
}

/**
 * Keep the page over the viewport it is being read in: pan stops at the edge of
 * the artwork, and an axis with nothing hidden stays centred. Without this a
 * flick sends the page off screen and leaves the reader looking at nothing.
 */
export function clampTransform(transform, { viewport, content }) {
  const overflowX = Math.max(0, content.width * transform.scale - viewport.width) / 2;
  const overflowY = Math.max(0, content.height * transform.scale - viewport.height) / 2;

  return {
    scale: clamp(transform.scale, MIN_SCALE, MAX_SCALE),
    x: clamp(transform.x, -overflowX, overflowX),
    y: clamp(transform.y, -overflowY, overflowY),
  };
}

/**
 * The scale at which the artwork fills the width it is read at — the zoom
 * somebody actually wants when they double-tap a page that is letterboxed to
 * fit on screen. A page already using the full width has no readable width to
 * find, so it gets a plain step instead.
 */
export function readableWidthScale({ viewport, content, fallback = 2 }) {
  if (!(content?.width > 0) || !(viewport?.width > 0)) return clamp(fallback, MIN_SCALE, MAX_SCALE);

  const filled = viewport.width / content.width;
  return clamp(filled > 1.05 ? filled : fallback, MIN_SCALE, MAX_SCALE);
}

/**
 * Double tap zooms to readable width around the tapped point; a second double
 * tap puts the page back. Reset is unconditional, so there is always one
 * gesture that returns a lost reader to a whole page.
 */
export function doubleTapTransform(transform, focal, geometry) {
  if (isZoomed(transform)) return { ...IDENTITY_TRANSFORM };

  const target = readableWidthScale(geometry);
  return clampTransform(zoomAbout(IDENTITY_TRANSFORM, focal, target, geometry.viewport), geometry);
}

/** The zoom buttons: a step about the middle of the page, which is where a keyboard user is looking. */
export function stepZoom(transform, factor, geometry) {
  const centre = { x: geometry.viewport.width / 2, y: geometry.viewport.height / 2 };
  return clampTransform(zoomAbout(transform, centre, factor, geometry.viewport), geometry);
}
