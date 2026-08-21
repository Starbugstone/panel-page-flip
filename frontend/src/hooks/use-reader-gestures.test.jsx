import { act, render } from "@testing-library/react";
import { useRef } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { useReaderGestures } from "./use-reader-gestures";

/**
 * jsdom has no pointer model, so the events are built by hand. Only the fields
 * the hook actually reads are set, which is also a check that it reads no more
 * than these.
 */
function pointerEvent(type, { id = 1, x = 0, y = 0, time = 0, pointerType = "touch" } = {}) {
  const event = new Event(type, { bubbles: true });
  Object.assign(event, { pointerId: id, clientX: x, clientY: y, pointerType });
  Object.defineProperty(event, "timeStamp", { value: time });
  return event;
}

function Surface(props) {
  const ref = useRef(null);
  useReaderGestures(ref, props);
  return <div ref={ref} data-testid="page" style={{ width: 400, height: 800 }} />;
}

const renderSurface = (props) => {
  const view = render(<Surface {...props} />);
  return view.getByTestId("page");
};

const fire = (element, ...events) => act(() => {
  events.forEach((event) => element.dispatchEvent(event));
});

describe("driving the reader with a finger", () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it("reports a tap once the double-tap window has passed", () => {
    const onTap = vi.fn();
    const page = renderSurface({ onTap });

    fire(page,
      pointerEvent("pointerdown", { x: 200, y: 400, time: 0 }),
      pointerEvent("pointerup", { x: 200, y: 400, time: 60 }));
    expect(onTap).not.toHaveBeenCalled();

    act(() => { vi.advanceTimersByTime(400); });
    expect(onTap).toHaveBeenCalledWith(expect.objectContaining({ x: 200, y: 400 }));
  });

  it("reports a double tap instead, and no tap at all", () => {
    const onTap = vi.fn();
    const onDoubleTap = vi.fn();
    const page = renderSurface({ onTap, onDoubleTap });

    fire(page,
      pointerEvent("pointerdown", { x: 100, y: 100, time: 0 }),
      pointerEvent("pointerup", { x: 100, y: 100, time: 50 }),
      pointerEvent("pointerdown", { x: 101, y: 100, time: 150 }));
    act(() => { vi.advanceTimersByTime(400); });

    expect(onDoubleTap).toHaveBeenCalledWith(expect.objectContaining({ x: 101, y: 100 }));
    expect(onTap).not.toHaveBeenCalled();
  });

  it("turns the page on a swipe", () => {
    const onSwipe = vi.fn();
    const page = renderSurface({ onSwipe });

    fire(page,
      pointerEvent("pointerdown", { x: 320, y: 400, time: 0 }),
      pointerEvent("pointermove", { x: 240, y: 402, time: 40 }),
      pointerEvent("pointerup", { x: 180, y: 404, time: 90 }));

    expect(onSwipe).toHaveBeenCalledWith(expect.objectContaining({ direction: "left" }));
  });

  it("pans instead of paging once the page is zoomed", () => {
    const onSwipe = vi.fn();
    const onPan = vi.fn();
    const page = renderSurface({ onSwipe, onPan, zoomed: true });

    fire(page,
      pointerEvent("pointerdown", { x: 320, y: 400, time: 0 }),
      pointerEvent("pointermove", { x: 240, y: 402, time: 40 }),
      pointerEvent("pointerup", { x: 180, y: 404, time: 90 }));

    expect(onPan).toHaveBeenCalled();
    expect(onSwipe).not.toHaveBeenCalled();
  });

  it("leaves the mouse alone, which has a cursor and does not need disambiguating", () => {
    const onTap = vi.fn();
    const onSwipe = vi.fn();
    const page = renderSurface({ onTap, onSwipe });

    fire(page,
      pointerEvent("pointerdown", { x: 320, y: 400, time: 0, pointerType: "mouse" }),
      pointerEvent("pointermove", { x: 200, y: 400, time: 40, pointerType: "mouse" }),
      pointerEvent("pointerup", { x: 120, y: 400, time: 80, pointerType: "mouse" }));
    act(() => { vi.advanceTimersByTime(400); });

    expect(onTap).not.toHaveBeenCalled();
    expect(onSwipe).not.toHaveBeenCalled();
  });

  it("reads the newest handlers, not the ones it was mounted with", () => {
    const first = vi.fn();
    const second = vi.fn();
    const view = render(<Surface onSwipe={first} />);
    view.rerender(<Surface onSwipe={second} />);
    const page = view.getByTestId("page");

    fire(page,
      pointerEvent("pointerdown", { x: 320, y: 400, time: 0 }),
      pointerEvent("pointermove", { x: 240, y: 400, time: 40 }),
      pointerEvent("pointerup", { x: 180, y: 400, time: 90 }));

    expect(second).toHaveBeenCalled();
    expect(first).not.toHaveBeenCalled();
  });

  it("stops listening when the reader goes away", () => {
    const onSwipe = vi.fn();
    const view = render(<Surface onSwipe={onSwipe} />);
    const page = view.getByTestId("page");
    view.unmount();

    fire(page,
      pointerEvent("pointerdown", { x: 320, y: 400, time: 0 }),
      pointerEvent("pointermove", { x: 240, y: 400, time: 40 }),
      pointerEvent("pointerup", { x: 180, y: 400, time: 90 }));

    expect(onSwipe).not.toHaveBeenCalled();
  });
});
