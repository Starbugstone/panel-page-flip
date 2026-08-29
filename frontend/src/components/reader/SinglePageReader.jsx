import { useRef } from "react";

import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useReaderGestures } from "@/hooks/use-reader-gestures";
import { useReaderMousePan } from "@/hooks/use-reader-mouse-pan";
import { IDENTITY_TRANSFORM, isZoomed } from "@/lib/reader-zoom";

const VIEWPORT_CLASSES = {
  contain: "items-center justify-center overflow-hidden",
  width: "items-start justify-center overflow-x-hidden overflow-y-auto",
  height: "items-center justify-center overflow-hidden",
  original: "items-start justify-start overflow-auto",
};

// A zoomed page is positioned entirely by its transform, which is measured from
// the centre of the viewport. The fit's own alignment and scrolling would move
// the page underneath that and every pan would be off by the difference.
const ZOOMED_CLASSES = "items-center justify-center overflow-hidden";

// What the browser may keep for itself while the page is at natural scale.
// A page at original size overflows in both directions and the browser is the
// one scrolling it, so taking the horizontal axis for page turns here would
// make the right-hand side of a wide page unreachable.
const TOUCH_ACTION = {
  contain: "pan-y",
  width: "pan-y",
  height: "pan-y",
  original: "pan-x pan-y",
};

const IMAGE_CLASSES = {
  contain: "max-h-full max-w-full h-auto w-auto object-contain",
  width: "h-auto w-full max-h-none max-w-none object-contain",
  height: "h-full w-auto max-h-full max-w-none object-contain",
  original: "h-auto w-auto max-h-none max-w-none object-none",
};

export function SinglePageReader({
  containerRef,
  imageRef,
  image,
  isLoading,
  hasFailed,
  pageNumber,
  title,
  fit,
  transform = IDENTITY_TRANSFORM,
  swipeOffset = 0,
  isSwiping = false,
  paged = true,
  gestures,
  onSurfaceClick,
  onSurfaceDoubleClick,
  onRetry,
  isStale = false,
  children,
}) {
  const safeFit = Object.hasOwn(VIEWPORT_CLASSES, fit) ? fit : "contain";
  const zoomed = isZoomed(transform);
  // Mouse clicks and touch taps reach this element by different routes, and
  // only the mouse's belong to the caller: a browser sends a click after a tap
  // too, so without this a double tap would zoom and the trailing click would
  // immediately undo it.
  const lastPointerTypeRef = useRef("mouse");

  useReaderGestures(containerRef, { zoomed, paged, ...gestures });
  const { cursorClass } = useReaderMousePan(containerRef, { enabled: zoomed, onPan: gestures?.onPan });

  return (
    <div
      ref={containerRef}
      className={`relative max-h-full h-full w-full flex ${zoomed ? ZOOMED_CLASSES : VIEWPORT_CLASSES[safeFit]} ${cursorClass}`}
      data-page-fit={safeFit}
      data-page-zoomed={zoomed ? "true" : "false"}
      // A zoomed page is moved entirely by the gestures above; a fitted one
      // still scrolls the way every other page on the web does.
      style={{ touchAction: zoomed ? "none" : TOUCH_ACTION[safeFit] }}
      onPointerDownCapture={(event) => { lastPointerTypeRef.current = event.pointerType; }}
      onClick={(event) => {
        // Bound to the viewport rather than the artwork, so the mat around the
        // page is clickable. What a click on the page itself may mean is the
        // caller's decision, not this element's.
        if (event.target.closest("button, a, input, select, textarea")) return;
        if (lastPointerTypeRef.current === "mouse") onSurfaceClick?.(event);
      }}
      onDoubleClick={(event) => {
        if (event.target.closest("button, a, input, select, textarea")) return;
        if (lastPointerTypeRef.current === "mouse") onSurfaceDoubleClick?.(event);
      }}
    >
      {image && (
        <img
          ref={imageRef}
          src={image.src}
          alt={isStale ? "" : `Page ${pageNumber} of ${title || "Comic"}`}
          aria-hidden={isStale ? "true" : undefined}
          data-reader-artwork="true"
          // A dragged image is the browser offering to copy a file, which under
          // a finger or a mouse is never what a page turn meant.
          draggable={false}
          className={`${IMAGE_CLASSES[safeFit]} mx-auto block select-none shadow-lg ${zoomed ? "zoomed-image" : ""} ${isSwiping || zoomed ? "" : "transition-transform duration-200 motion-reduce:transition-none"}`}
          style={{
            transform: `translate3d(${transform.x + swipeOffset}px, ${transform.y}px, 0) scale(${transform.scale})`,
            transformOrigin: "center center",
          }}
        />
      )}

      {hasFailed && (
        <div className="m-auto flex flex-col items-center justify-center rounded-md bg-destructive-foreground p-4 text-destructive">
          <p className="mb-2">Error loading page {pageNumber}.</p>
          <Button variant="outline" onClick={onRetry}>Retry</Button>
        </div>
      )}

      {isLoading && !image && (
        <div className="absolute inset-0 flex items-center justify-center">
          <Skeleton className="mx-auto h-full w-full max-w-full object-contain" />
        </div>
      )}

      {isLoading && image && (
        <div role="status" className="absolute bottom-3 left-1/2 -translate-x-1/2 rounded-full bg-background/90 px-3 py-1 text-xs shadow">
          Loading page {pageNumber}…
        </div>
      )}

      {children}
    </div>
  );
}
