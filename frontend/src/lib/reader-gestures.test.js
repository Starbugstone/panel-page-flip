import { describe, expect, it } from "vitest";

import { createGestureState, reduceGesture, tapZone } from "./reader-gestures";

const down = (x, y, time, id = 1) => ({ type: "pointerdown", id, x, y, time });
const move = (x, y, time, id = 1) => ({ type: "pointermove", id, x, y, time });
const up = (x, y, time, id = 1) => ({ type: "pointerup", id, x, y, time });
const cancel = (x, y, time, id = 1) => ({ type: "pointercancel", id, x, y, time });
const tapTimeout = () => ({ type: "tapTimeout" });

function drive(events, config = {}) {
  let state = createGestureState();
  const actions = [];

  for (const event of events) {
    const result = reduceGesture(state, event, config);
    state = result.state;
    actions.push(...result.actions);
  }

  return { state, actions, types: actions.map((action) => action.type) };
}

describe("taps", () => {
  it("holds a tap back until the double-tap window has passed", () => {
    const { types } = drive([down(100, 100, 0), up(100, 102, 60)]);

    expect(types).toEqual(["waitForTap"]);
  });

  it("delivers the held tap when no second tap arrives", () => {
    const { actions } = drive([down(100, 100, 0), up(100, 102, 60), tapTimeout()]);

    expect(actions.at(-1)).toMatchObject({ type: "tap", x: 100, y: 102 });
  });

  it("never lets the first half of a double tap toggle anything", () => {
    const { types } = drive([
      down(100, 100, 0), up(100, 100, 50),
      down(102, 101, 180), up(102, 101, 220),
      // The timer from the first tap still fires; by then the pair has resolved.
      tapTimeout(),
    ]);

    expect(types).toContain("doubleTap");
    expect(types).not.toContain("tap");
  });

  it("treats two slow taps as two taps, not a double tap", () => {
    const { types } = drive([
      down(100, 100, 0), up(100, 100, 50), tapTimeout(),
      down(100, 100, 900), up(100, 100, 950), tapTimeout(),
    ]);

    expect(types.filter((type) => type === "tap")).toHaveLength(2);
    expect(types).not.toContain("doubleTap");
  });

  it("does not call a drag a tap", () => {
    const { types } = drive([down(100, 100, 0), move(160, 104, 80), up(160, 104, 90)]);

    expect(types).not.toContain("waitForTap");
  });
});

describe("swiping to turn the page", () => {
  it("reports the direction the finger travelled, leaving reading order to the caller", () => {
    const right = drive([down(60, 300, 0), move(150, 305, 60), up(220, 306, 110)]);
    const left = drive([down(300, 300, 0), move(200, 305, 60), up(120, 306, 110)]);

    expect(right.actions.at(-1)).toEqual({ type: "swipe", direction: "right" });
    expect(left.actions.at(-1)).toEqual({ type: "swipe", direction: "left" });
  });

  it("follows the finger while the swipe is still in the balance", () => {
    const { actions } = drive([down(300, 300, 0), move(240, 300, 40)]);

    expect(actions).toEqual([{ type: "swipeMove", dx: -60 }]);
  });

  it("cancels a short, slow drag instead of turning the page", () => {
    const { actions } = drive([down(300, 300, 0), move(275, 300, 200), up(270, 300, 400)]);

    expect(actions.at(-1)).toEqual({ type: "swipeCancel" });
  });

  it("turns the page on a short flick, which is a swipe done quickly", () => {
    const { actions } = drive([down(300, 300, 0), move(280, 300, 20), up(260, 300, 40)]);

    expect(actions.at(-1)).toEqual({ type: "swipe", direction: "left" });
  });

  it("refuses a drag that wandered too far off axis", () => {
    const { actions } = drive([down(300, 300, 0), move(240, 350, 40), up(200, 420, 90)]);

    expect(actions.at(-1)).toEqual({ type: "swipeCancel" });
  });

  it("leaves a vertical drag to the browser's own scrolling", () => {
    const { actions, state } = drive([down(300, 300, 0), move(302, 220, 60), up(302, 180, 120)]);

    expect(actions).toEqual([]);
    expect(state.phase).toBe("idle");
  });

  it("does not page in a mode that has no pages to swipe between", () => {
    const { actions } = drive([down(300, 300, 0), move(150, 300, 60), up(80, 300, 110)], { paged: false });

    expect(actions).toEqual([]);
  });

  it("abandons the swipe when the platform takes the pointer away", () => {
    const { actions } = drive([down(300, 300, 0), move(150, 300, 60), cancel(150, 300, 70)]);

    expect(actions.at(-1)).toEqual({ type: "swipeCancel" });
  });
});

describe("dragging a zoomed page", () => {
  it("pans, and never turns the page", () => {
    const { types, actions } = drive(
      [down(300, 300, 0), move(240, 280, 40), move(200, 260, 80), up(200, 260, 120)],
      { zoomed: true }
    );

    expect(types).not.toContain("swipe");
    expect(types).not.toContain("swipeMove");
    expect(actions).toEqual([
      { type: "pan", dx: -60, dy: -20 },
      { type: "pan", dx: -40, dy: -20 },
    ]);
  });

  it("pans vertically too, because a zoomed page has more of it off screen", () => {
    const { actions } = drive([down(300, 300, 0), move(300, 220, 40)], { zoomed: true });

    expect(actions).toEqual([{ type: "pan", dx: 0, dy: -80 }]);
  });
});

describe("pinching", () => {
  it("reports each move as a ratio on the one before it", () => {
    const { actions } = drive([
      down(100, 300, 0, 1),
      down(300, 300, 10, 2),
      move(400, 300, 40, 2),
    ]);

    expect(actions).toEqual([
      { type: "pinch", scale: 1.5, focal: { x: 250, y: 300 }, dx: 50, dy: 0 },
    ]);
  });

  it("abandons a swipe the moment a second finger lands", () => {
    const { types } = drive([
      down(300, 300, 0, 1),
      move(220, 300, 40, 1),
      down(120, 300, 50, 2),
      move(100, 300, 80, 2),
      up(100, 300, 120, 2),
      up(220, 300, 140, 1),
    ]);

    expect(types).toEqual(["swipeMove", "swipeCancel", "pinch", "pinchEnd"]);
  });

  it("does not let the finger left over from a pinch turn the page", () => {
    const { types } = drive([
      down(100, 300, 0, 1),
      down(300, 300, 10, 2),
      move(400, 300, 40, 2),
      up(400, 300, 60, 2),
      // One finger still down, and now dragged a long way. It is the tail of a
      // pinch, not a swipe.
      move(120, 300, 100, 1),
      up(120, 300, 140, 1),
    ]);

    expect(types).not.toContain("swipe");
    expect(types).not.toContain("waitForTap");
  });

  it("suspends everything while a third finger is down", () => {
    const { state } = drive([
      down(100, 300, 0, 1),
      down(300, 300, 10, 2),
      down(200, 400, 20, 3),
    ]);

    expect(state.phase).toBe("blocked");
  });
});

describe("tap zones", () => {
  it("gives the middle of the page to the controls", () => {
    expect(tapZone(20, 400)).toBe("left");
    expect(tapZone(200, 400)).toBe("center");
    expect(tapZone(390, 400)).toBe("right");
  });

  it("answers center rather than dividing by an unmeasured width", () => {
    expect(tapZone(0, 0)).toBe("center");
  });
});
