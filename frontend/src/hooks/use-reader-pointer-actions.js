import { useCallback, useMemo } from "react";

import { mouseClickAction, tapZone } from "@/lib/reader-gestures";

const SWIPE_FOLLOW = 0.35;

/**
 * What a finger or a mouse means over the page, once reading direction is
 * accounted for.
 *
 * Logical navigation stays logical everywhere else; only here is it turned into
 * physical left and right, because only here does the side of the screen
 * something happened on matter. Right-to-left reading swaps the two and nothing
 * upstream has to know.
 */
export function useReaderPointerActions({
  containerRef, direction, isZoomed, canGoPrevious, canGoNext,
  goPrevious, goNext, toggleChrome, doubleTapAt, pan, pinch, zoomToFit,
  onSwipeOffsetChange, onSwipingChange,
}) {
  const rtl = direction === "rtl";
  const physicalLeft = rtl ? goNext : goPrevious;
  const physicalRight = rtl ? goPrevious : goNext;

  const turnZones = {
    leftLabel: rtl ? "Left edge: next page" : "Left edge: previous page",
    rightLabel: rtl ? "Right edge: previous page" : "Right edge: next page",
    onLeft: physicalLeft,
    onRight: physicalRight,
    leftDisabled: rtl ? !canGoNext : !canGoPrevious,
    rightDisabled: rtl ? !canGoPrevious : !canGoNext,
  };

  const gestures = useMemo(() => ({
    onTap: ({ x }) => {
      const zone = tapZone(x, containerRef.current?.clientWidth ?? 0);
      if (isZoomed || zone === "center") return toggleChrome();
      if (zone === "left") physicalLeft();
      else physicalRight();
    },
    onDoubleTap: ({ x, y }) => doubleTapAt({ x, y }),
    onSwipeMove: ({ dx }) => {
      onSwipingChange(true);
      onSwipeOffsetChange(dx * SWIPE_FOLLOW);
    },
    onSwipe: ({ direction: swipe }) => {
      onSwipingChange(false);
      onSwipeOffsetChange(0);
      if (swipe === "right") physicalLeft();
      else physicalRight();
    },
    onSwipeCancel: () => {
      onSwipingChange(false);
      onSwipeOffsetChange(0);
    },
    onPan: ({ dx, dy }) => pan({ dx, dy }),
    onPinch: ({ scale, focal, dx, dy }) => pinch({ scale, focal, dx, dy }),
  }), [containerRef, doubleTapAt, isZoomed, onSwipeOffsetChange, onSwipingChange, pan, physicalLeft, physicalRight, pinch, toggleChrome]);

  const onSurfaceClick = useCallback((event) => {
    const rect = containerRef.current?.getBoundingClientRect();
    if (!rect) return;

    const action = mouseClickAction({
      x: event.clientX - rect.left,
      width: rect.width,
      onArtwork: Boolean(event.target.closest?.("[data-reader-artwork]")),
      zoomed: isZoomed,
    });

    if (action === "chrome") toggleChrome();
    else if (action === "left") physicalLeft();
    else physicalRight();
  }, [containerRef, isZoomed, physicalLeft, physicalRight, toggleChrome]);

  /**
   * The mouse's counterpart to touch's second tap: a deliberate way back out.
   *
   * The two clicks that precede it have each toggled the chrome, which leaves
   * it exactly as it was — so this only has to deal with the zoom. Deferring
   * those toggles to tell a single click from a double one was tried and
   * removed: it put a fifth of a second between every zoomed click and the
   * controls appearing, to save a flicker that cancels itself out.
   */
  const onSurfaceDoubleClick = useCallback(() => {
    if (isZoomed) zoomToFit();
  }, [isZoomed, zoomToFit]);

  return { turnZones, gestures, onSurfaceClick, onSurfaceDoubleClick };
}
