import { useCallback, useEffect, useRef, useState } from "react";

import { isReaderControl } from "@/lib/reader-controls";

// Below this, a "drag" was a click with a shaky hand and the reader should
// still treat it as one.
export const DRAG_SLOP_PX = 4;

/**
 * Dragging a zoomed page with a mouse.
 *
 * The touch gesture machine ignores the mouse on purpose: a mouse has a cursor,
 * hover and its own wheel, and putting desktop clicks behind a double-tap
 * window makes every one of them feel broken. But a zoomed page is larger than
 * the window it is read in, and the only thing a mouse can do about that is
 * push it around — so this is the one mouse gesture the reader understands, and
 * it exists only while there is something to move.
 *
 * The drag that moved the page must not also arrive as a click, or letting go
 * would zoom back out and undo the pan that was just made. The click is
 * swallowed in the capture phase, before it reaches React's own handler.
 *
 * @param {{current: HTMLElement|null}} elementRef The reader viewport.
 * @param {object} options
 * @param {boolean} options.enabled Only true while the page is zoomed.
 * @param {(delta: {dx: number, dy: number}) => void} options.onPan
 */
export function useReaderMousePan(elementRef, { enabled = false, onPan } = {}) {
  const [isDragging, setIsDragging] = useState(false);
  const onPanRef = useRef(onPan);
  const dragRef = useRef(null);
  const movedRef = useRef(false);

  // After every render rather than during it: the listeners below are bound
  // once and read whatever the newest render left here.
  useEffect(() => {
    onPanRef.current = onPan;
  });

  useEffect(() => {
    const element = elementRef.current;

    if (!element || !enabled) {
      // Whatever was mid-drag when the zoom ended is over. Without this the
      // grabbing cursor survives a zoom-out that happened under the mouse.
      dragRef.current = null;
      setIsDragging(false);
      return undefined;
    }

    const endDrag = (pointerId) => {
      if (dragRef.current?.id !== pointerId) return;
      dragRef.current = null;
      setIsDragging(false);
      try {
        element.releasePointerCapture?.(pointerId);
      } catch {
        // Already released, which is the state this wanted anyway.
      }
    };

    const onPointerDown = (event) => {
      if (event.pointerType !== "mouse" || event.button !== 0) return;
      // A press on the controls that float over the page is that control's.
      if (isReaderControl(event.target)) return;

      dragRef.current = { id: event.pointerId, x: event.clientX, y: event.clientY };
      movedRef.current = false;
      setIsDragging(true);
      // Otherwise the browser starts its own text or image drag partway
      // through, and the page stops following the mouse.
      event.preventDefault();
      try {
        element.setPointerCapture?.(event.pointerId);
      } catch {
        // Without capture the drag simply ends when the pointer leaves.
      }
    };

    const onPointerMove = (event) => {
      const drag = dragRef.current;
      if (!drag || drag.id !== event.pointerId) return;

      const dx = event.clientX - drag.x;
      const dy = event.clientY - drag.y;
      dragRef.current = { id: drag.id, x: event.clientX, y: event.clientY };

      if (Math.abs(dx) > DRAG_SLOP_PX || Math.abs(dy) > DRAG_SLOP_PX) movedRef.current = true;
      onPanRef.current?.({ dx, dy });
    };

    const onPointerUp = (event) => endDrag(event.pointerId);

    // Capture, so the click is stopped before it reaches the target and bubbles
    // to React's delegated handler at the document root.
    const onClickCapture = (event) => {
      if (!movedRef.current) return;
      movedRef.current = false;
      event.stopPropagation();
      event.preventDefault();
    };

    element.addEventListener("pointerdown", onPointerDown);
    element.addEventListener("pointermove", onPointerMove);
    element.addEventListener("pointerup", onPointerUp);
    element.addEventListener("pointercancel", onPointerUp);
    element.addEventListener("click", onClickCapture, true);

    return () => {
      element.removeEventListener("pointerdown", onPointerDown);
      element.removeEventListener("pointermove", onPointerMove);
      element.removeEventListener("pointerup", onPointerUp);
      element.removeEventListener("pointercancel", onPointerUp);
      element.removeEventListener("click", onClickCapture, true);
      dragRef.current = null;
    };
  }, [elementRef, enabled]);

  /** The cursor is the whole affordance: without it nothing says the page moves. */
  const cursorClass = useCallback(
    () => (enabled ? (isDragging ? "cursor-grabbing" : "cursor-grab") : ""),
    [enabled, isDragging]
  );

  return { isDragging, cursorClass: cursorClass() };
}
