import { useEffect, useRef } from "react";

import { isReaderControl } from "@/lib/reader-controls";
import { createGestureState, reduceGesture } from "@/lib/reader-gestures";

/**
 * Pointer Events into one gesture machine, and its decisions out to the reader.
 *
 * Only touch and pen go through the machine. A mouse has a cursor, hover and
 * its own wheel, and putting its clicks behind a double-tap window would make
 * every desktop click feel broken.
 */
export function useReaderGestures(elementRef, { zoomed = false, paged = true, enabled = true, ...handlers }) {
  const stateRef = useRef(createGestureState());
  const tapTimerRef = useRef(null);
  // Re-binding listeners whenever a handler identity changes would drop a
  // gesture mid-drag; the machine's own state lives across those renders.
  const handlersRef = useRef(handlers);
  const contextRef = useRef({ zoomed, paged });

  // After every render, not during it: the listeners below are bound once and
  // read whatever the newest render left here when a gesture actually happens.
  useEffect(() => {
    handlersRef.current = handlers;
    contextRef.current = { zoomed, paged };
  });

  useEffect(() => {
    const element = elementRef.current;
    if (!element || !enabled) return undefined;

    // A gesture that began on a control is that control's, for as long as the
    // finger is down: the moves and the lift have to be ignored too, or half a
    // swipe arrives with no beginning.
    const ignored = new Set();

    const dispatch = (event) => {
      const { state, actions } = reduceGesture(stateRef.current, event, contextRef.current);
      stateRef.current = state;

      for (const action of actions) {
        if (action.type === "waitForTap") {
          clearTimeout(tapTimerRef.current);
          tapTimerRef.current = setTimeout(() => dispatch({ type: "tapTimeout" }), action.delay);
          continue;
        }

        const handler = handlersRef.current[`on${action.type[0].toUpperCase()}${action.type.slice(1)}`];
        handler?.(action);
      }
    };

    const toEvent = (type, event) => {
      const rect = element.getBoundingClientRect();
      return {
        type,
        id: event.pointerId,
        // Element coordinates, because that is what a zoom focal point and a
        // tap zone are both measured in.
        x: event.clientX - rect.left,
        y: event.clientY - rect.top,
        time: event.timeStamp,
      };
    };

    const isGesturePointer = (event) => event.pointerType !== "mouse";

    const onPointerDown = (event) => {
      if (!isGesturePointer(event)) return;
      if (isReaderControl(event.target)) {
        ignored.add(event.pointerId);
        return;
      }
      try {
        // Capture, so a finger that leaves the page still finishes its gesture
        // here rather than silently stopping halfway. A pointer the browser has
        // already forgotten refuses to be captured, which is not a reason to
        // drop the gesture.
        element.setPointerCapture?.(event.pointerId);
      } catch {
        // Nothing to do: without capture the gesture simply ends early.
      }
      dispatch(toEvent("pointerdown", event));
    };

    const onPointerMove = (event) => {
      if (!isGesturePointer(event) || ignored.has(event.pointerId)) return;
      dispatch(toEvent("pointermove", event));
    };

    const onPointerUp = (event) => {
      if (!isGesturePointer(event)) return;
      if (ignored.delete(event.pointerId)) return;
      try {
        element.releasePointerCapture?.(event.pointerId);
      } catch {
        // Already released, which is the state this wanted anyway.
      }
      dispatch(toEvent("pointerup", event));
    };

    const onPointerCancel = (event) => {
      if (!isGesturePointer(event)) return;
      if (ignored.delete(event.pointerId)) return;
      dispatch(toEvent("pointercancel", event));
    };

    element.addEventListener("pointerdown", onPointerDown);
    element.addEventListener("pointermove", onPointerMove);
    element.addEventListener("pointerup", onPointerUp);
    element.addEventListener("pointercancel", onPointerCancel);

    return () => {
      element.removeEventListener("pointerdown", onPointerDown);
      element.removeEventListener("pointermove", onPointerMove);
      element.removeEventListener("pointerup", onPointerUp);
      element.removeEventListener("pointercancel", onPointerCancel);
      clearTimeout(tapTimerRef.current);
      stateRef.current = createGestureState();
    };
  }, [elementRef, enabled]);
}
