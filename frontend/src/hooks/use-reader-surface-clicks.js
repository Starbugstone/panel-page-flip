import { useRef } from "react";

import { isReaderControl } from "@/lib/reader-controls";

/**
 * The click handling both readers put on their viewport.
 *
 * Bound to the viewport rather than the artwork so the mat around the page is
 * clickable, and answered only for a mouse, because touch and pen already go
 * through the gesture machine — letting them through here as well would turn
 * the release of every swipe into a page turn.
 *
 * Shared so the single-page and spread readers cannot disagree about what a
 * click on the reading surface is. What such a click *means* stays the caller's
 * decision; this only decides whether one happened.
 */
export function useReaderSurfaceClicks({ onSurfaceClick, onSurfaceDoubleClick }) {
  const lastPointerTypeRef = useRef("mouse");

  const fromMouse = (handler) => (event) => {
    if (isReaderControl(event.target)) return;
    if (lastPointerTypeRef.current === "mouse") handler?.(event);
  };

  return {
    onPointerDownCapture: (event) => { lastPointerTypeRef.current = event.pointerType; },
    onClick: fromMouse(onSurfaceClick),
    onDoubleClick: fromMouse(onSurfaceDoubleClick),
  };
}
