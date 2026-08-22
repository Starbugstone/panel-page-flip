/**
 * One interaction model for touch reading.
 *
 * Tap, double tap, swipe, pan and pinch all begin as the same pointer going
 * down, and every conflict between them is decided here rather than in handlers
 * scattered through the reader. The machine is pure: it takes normalized
 * pointer events and returns the next state plus what the reader should do, so
 * every rule below can be tested without a touchscreen.
 */

export const GESTURE_DEFAULTS = Object.freeze({
  // Long enough for a comfortable double tap, short enough that a deliberate
  // single tap on the controls does not feel delayed.
  doubleTapMs: 280,
  // A finger never lands perfectly still, so a tap is allowed to wander.
  tapSlopPx: 10,
  tapMaxMs: 400,
  // Movement below this has not chosen an axis yet; committing earlier makes a
  // slightly diagonal scroll turn the page.
  axisSlopPx: 12,
  swipeMinDistancePx: 60,
  // A short flick still turns the page if it was fast enough.
  swipeFlickDistancePx: 24,
  swipeMinVelocity: 0.35,
  // A swipe that drifts this much off axis was a scroll with a lean on it.
  swipeMaxOffAxisRatio: 0.7,
});

export function createGestureState() {
  return {
    phase: "idle",
    pointers: {},
    start: null,
    last: null,
    pinch: null,
    pendingTap: null,
    lastTap: null,
  };
}

const distance = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
const midpoint = (a, b) => ({ x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 });
const pointerList = (pointers) => Object.values(pointers);

function withoutPointer(pointers, id) {
  const next = { ...pointers };
  delete next[id];
  return next;
}

function beginPinch(state) {
  const [a, b] = pointerList(state.pointers);
  return {
    ...state,
    phase: "pinching",
    // A pinch that starts mid-swipe abandons the swipe: two fingers on the page
    // are never a page turn, however far the first one had travelled.
    pinch: { distance: distance(a, b), focal: midpoint(a, b) },
    pendingTap: null,
    lastTap: null,
  };
}

// The lift is part of the swipe. Judging it on the last move instead loses
// whatever distance the finger covered after the browser's final move event,
// which on a fast flick is most of it.
function resolveSwipe(state, end, config) {
  const dx = end.x - state.start.x;
  const dy = end.y - state.start.y;
  const elapsed = Math.max(1, end.time - state.start.time);
  const velocity = Math.abs(dx) / elapsed;

  const wentFarEnough = Math.abs(dx) >= config.swipeMinDistancePx;
  const wentFastEnough = velocity >= config.swipeMinVelocity
    && Math.abs(dx) >= config.swipeFlickDistancePx;
  const stayedOnAxis = Math.abs(dy) <= Math.abs(dx) * config.swipeMaxOffAxisRatio;

  return (wentFarEnough || wentFastEnough) && stayedOnAxis
    ? { type: "swipe", direction: dx > 0 ? "right" : "left" }
    : { type: "swipeCancel" };
}

function isTap(state, event, config) {
  return distance(state.start, event) <= config.tapSlopPx
    && event.time - state.start.time <= config.tapMaxMs;
}

function isDoubleTap(lastTap, event, config) {
  return Boolean(lastTap)
    && event.time - lastTap.time <= config.doubleTapMs
    && distance(lastTap, event) <= config.tapSlopPx * 2;
}

function onPointerDown(state, event, config) {
  const pointers = { ...state.pointers, [event.id]: { x: event.x, y: event.y } };
  const count = Object.keys(pointers).length;

  if (count === 2) {
    const actions = state.phase === "swiping" ? [{ type: "swipeCancel" }] : [];
    return { state: beginPinch({ ...state, pointers }), actions };
  }

  // Three fingers is not a reader gesture. Everything stays suspended until the
  // hand comes off the screen, rather than resolving into whatever two of them
  // happen to be doing.
  if (count > 2) {
    return { state: { ...state, pointers, phase: "blocked", pendingTap: null }, actions: [] };
  }

  if (isDoubleTap(state.lastTap, event, config)) {
    return {
      // Blocked, not idle: the finger is still down, and its lift must not also
      // register as the first tap of another pair.
      state: { ...state, pointers, phase: "blocked", pendingTap: null, lastTap: null },
      actions: [{ type: "doubleTap", x: event.x, y: event.y }],
    };
  }

  const point = { x: event.x, y: event.y, time: event.time };
  return {
    state: { ...state, pointers, phase: "pressing", start: point, last: point, pendingTap: null },
    actions: [],
  };
}

function onPointerMove(state, event, config) {
  if (!state.pointers[event.id]) return { state, actions: [] };

  const pointers = { ...state.pointers, [event.id]: { x: event.x, y: event.y } };
  const point = { x: event.x, y: event.y, time: event.time };

  if (state.phase === "pinching") {
    const [a, b] = pointerList(pointers);
    if (!b) return { state: { ...state, pointers }, actions: [] };

    const spread = distance(a, b);
    const focal = midpoint(a, b);
    const previous = state.pinch;
    return {
      state: { ...state, pointers, pinch: { distance: spread, focal } },
      actions: [{
        type: "pinch",
        // A ratio since the last move, so the caller composes it onto whatever
        // transform it is already showing instead of recomputing from a base.
        scale: previous.distance > 0 ? spread / previous.distance : 1,
        focal,
        dx: focal.x - previous.focal.x,
        dy: focal.y - previous.focal.y,
      }],
    };
  }

  if (state.phase === "panning") {
    return {
      state: { ...state, pointers, last: point },
      actions: [{ type: "pan", dx: event.x - state.last.x, dy: event.y - state.last.y }],
    };
  }

  if (state.phase === "swiping") {
    return {
      state: { ...state, pointers, last: point },
      actions: [{ type: "swipeMove", dx: event.x - state.start.x }],
    };
  }

  if (state.phase !== "pressing") return { state: { ...state, pointers }, actions: [] };

  const dx = event.x - state.start.x;
  const dy = event.y - state.start.y;
  if (Math.hypot(dx, dy) < config.axisSlopPx) {
    return { state: { ...state, pointers, last: point }, actions: [] };
  }

  if (config.zoomed) {
    return {
      state: { ...state, pointers, last: point, phase: "panning", pendingTap: null },
      actions: [{ type: "pan", dx: event.x - state.last.x, dy: event.y - state.last.y }],
    };
  }

  if (Math.abs(dx) > Math.abs(dy) && config.paged) {
    return {
      state: { ...state, pointers, last: point, phase: "swiping", pendingTap: null },
      actions: [{ type: "swipeMove", dx }],
    };
  }

  // A vertical drag on a page taller than the viewport belongs to the browser's
  // own scrolling. Claiming it here would fight the scroller for the same
  // finger and lose the momentum the platform gives for free.
  return { state: { ...state, pointers, last: point, phase: "blocked" }, actions: [] };
}

function onPointerEnd(state, event, config, { cancelled }) {
  const pointers = withoutPointer(state.pointers, event.id);
  const remaining = Object.keys(pointers).length;
  const settled = { ...state, pointers, pinch: null };

  if (state.phase === "pinching") {
    return {
      // One finger left over from a pinch must not become a swipe on its way
      // off the glass.
      state: { ...settled, phase: remaining > 0 ? "blocked" : "idle" },
      actions: [{ type: "pinchEnd" }],
    };
  }

  if (state.phase === "swiping") {
    const action = cancelled ? { type: "swipeCancel" } : resolveSwipe(state, event, config);
    return { state: { ...settled, phase: remaining > 0 ? "blocked" : "idle" }, actions: [action] };
  }

  if (state.phase === "pressing" && !cancelled && isTap(state, event, config)) {
    const tap = { x: event.x, y: event.y, time: event.time };
    return {
      state: { ...settled, phase: remaining > 0 ? "blocked" : "idle", pendingTap: tap, lastTap: tap },
      // Nothing visible happens yet. A tap that turns out to be the first half
      // of a double tap would otherwise flash the controls on and off before
      // the zoom it was actually asking for.
      actions: [{ type: "waitForTap", delay: config.doubleTapMs }],
    };
  }

  return { state: { ...settled, phase: remaining > 0 ? "blocked" : "idle" }, actions: [] };
}

/**
 * @param {object} state    from createGestureState()
 * @param {object} event    {type, id, x, y, time}
 * @param {object} config   {zoomed, paged} over GESTURE_DEFAULTS
 * @returns {{state: object, actions: object[]}}
 */
export function reduceGesture(state, event, config = {}) {
  const settings = { zoomed: false, paged: true, ...GESTURE_DEFAULTS, ...config };

  switch (event.type) {
    case "pointerdown":
      return onPointerDown(state, event, settings);
    case "pointermove":
      return onPointerMove(state, event, settings);
    case "pointerup":
      return onPointerEnd(state, event, settings, { cancelled: false });
    case "pointercancel":
      return onPointerEnd(state, event, settings, { cancelled: true });
    case "tapTimeout":
      return state.pendingTap
        ? {
          state: { ...state, pendingTap: null, lastTap: null },
          actions: [{ type: "tap", x: state.pendingTap.x, y: state.pendingTap.y }],
        }
        : { state, actions: [] };
    case "reset":
      return { state: createGestureState(), actions: [] };
    default:
      return { state, actions: [] };
  }
}

/**
 * Which third of the page was tapped. Centre is deliberately the widest: it is
 * the one that has to be hittable with a thumb without turning the page.
 */
export function tapZone(x, width, edgeFraction = 0.28) {
  if (!(width > 0)) return "center";
  const ratio = x / width;
  if (ratio <= edgeFraction) return "left";
  if (ratio >= 1 - edgeFraction) return "right";
  return "center";
}

/**
 * What a mouse click on the paged reader means.
 *
 * Page turns live on the mat around the artwork and never on the artwork
 * itself. A mouse has a cursor, so a reader follows a panel with it and clicks
 * where they are already looking — and on a page that was also a page-turn
 * target, every one of those clicks lost their place. The letterbox is dead
 * space by definition, which makes it the one region where a click cannot mean
 * anything else.
 *
 * The page keeps the click that cannot lose anything: showing and hiding the
 * chrome. Touch is unaffected and still uses the whole width — a phone fitted
 * to width has no mat to aim at, and a thumb has no cursor to aim with.
 *
 * @param {object} click
 * @param {number} click.x Pointer offset within the reader viewport.
 * @param {number} click.width Reader viewport width.
 * @param {boolean} click.onArtwork Whether the click landed on a page image.
 * @param {boolean} click.zoomed Whether the page is currently zoomed in.
 * @returns {"zoomOut"|"chrome"|"left"|"right"}
 */
export function mouseClickAction({ x, width, onArtwork = false, zoomed = false }) {
  // A zoomed page is bigger than its window, so there is no mat and no zone to
  // read. Clicking is the mouse's way back out.
  if (zoomed) return "zoomOut";
  if (onArtwork) return "chrome";

  const zone = tapZone(x, width);

  return zone === "center" ? "chrome" : zone;
}
