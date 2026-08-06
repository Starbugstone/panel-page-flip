/**
 * One deliberate stacking order for every overlay in the app.
 *
 * Radix renders dropdowns, popovers and dialogs into sibling portals on
 * `document.body`, so whichever one wins is decided purely by `z-index` (and,
 * on a tie, by portal insertion order — which is whatever the user happened to
 * open first). Giving them all `z-50` is what let the header's Tags button and
 * the comic action menu sit on top of the Edit and Share dialogs.
 *
 * The scale below fixes the order once, centrally. Overlays opened *inside* a
 * modal need to beat that modal, so each role has one class per nesting depth
 * rather than a single fixed value:
 *
 *   depth 0 — opened from the page       popover 50, overlay 60, content 70
 *   depth 1 — opened from inside a modal popover 80, overlay 90, content 100
 *   depth 2 — modal within a modal       popover 110, overlay 120, content 130
 *
 * Above all of it sit two things that must never be covered: the cookie notice
 * (150) and toasts (200).
 *
 * Anything below 50 is page furniture — card actions, the header, reader
 * controls — and is expected to lose to every overlay.
 */

/** @typedef {"popover" | "modalOverlay" | "modalContent"} OverlayRole */

/**
 * Literal class names, one per depth. They have to be spelled out rather than
 * built from a template so Tailwind's scanner can see every value it must
 * generate.
 *
 * @type {Record<OverlayRole, string[]>}
 */
export const OVERLAY_LAYER_CLASSES = {
  popover: ["z-50", "z-[80]", "z-[110]"],
  modalOverlay: ["z-[60]", "z-[90]", "z-[120]"],
  modalContent: ["z-[70]", "z-[100]", "z-[130]"],
};

/** Page furniture that overlays must cover. */
export const PAGE_LAYER_CLASSES = {
  /** Card action buttons, reader controls, and similar in-card affordances. */
  cardAction: "z-10",
  /** The application header. */
  header: "z-30",
};

/** Always-on-top notifications, deliberately above every modal. */
export const NOTIFICATION_LAYER_CLASSES = {
  cookieNotice: "z-[150]",
  toast: "z-[200]",
};

/**
 * The Tailwind `z-*` class an overlay should use.
 *
 * Depth beyond the deepest defined step clamps to that step: a fourth level of
 * nesting is a UI problem, not a reason to render something invisible.
 *
 * @param {OverlayRole} role
 * @param {number} depth How many modals enclose this overlay.
 * @returns {string}
 */
export function overlayLayerClass(role, depth = 0) {
  const classes = OVERLAY_LAYER_CLASSES[role];
  if (!classes) {
    throw new Error(`Unknown overlay role: ${role}`);
  }

  const step = Number.isFinite(depth) ? Math.max(0, Math.trunc(depth)) : 0;

  return classes[Math.min(step, classes.length - 1)];
}
