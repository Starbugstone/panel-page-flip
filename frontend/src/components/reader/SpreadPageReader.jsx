import { useRef } from "react";

import { Button } from "@/components/ui/button";
import { useReaderGestures } from "@/hooks/use-reader-gestures";
import { IDENTITY_TRANSFORM, isZoomed } from "@/lib/reader-zoom";

const VIEWPORT_CLASSES = {
  contain: "items-center justify-center overflow-hidden",
  width: "items-start justify-center overflow-x-hidden overflow-y-auto",
  height: "items-center justify-center overflow-hidden",
  original: "items-start justify-start overflow-auto",
};

const TOUCH_ACTION = {
  contain: "pan-y",
  width: "pan-y",
  height: "pan-y",
  original: "pan-x pan-y",
};

function imageClasses(fit) {
  if (fit === "width") return "h-auto w-full";
  if (fit === "height") return "h-full w-auto max-w-full";
  if (fit === "original") return "h-auto w-auto max-h-none max-w-none";
  return "max-h-full max-w-full h-auto w-auto";
}

export function SpreadPageReader({
  containerRef,
  contentRef,
  pages,
  title,
  fit,
  transform = IDENTITY_TRANSFORM,
  swipeOffset = 0,
  isSwiping = false,
  gestures,
  onImageClick,
  children,
}) {
  const safeFit = Object.hasOwn(VIEWPORT_CLASSES, fit) ? fit : "contain";
  const zoomed = isZoomed(transform);
  const lastPointerTypeRef = useRef("mouse");

  useReaderGestures(containerRef, { zoomed, paged: true, ...gestures });

  return (
    <div
      ref={containerRef}
      className={`relative flex h-full max-h-full w-full ${zoomed ? "items-center justify-center overflow-hidden" : VIEWPORT_CLASSES[safeFit]}`}
      data-page-fit={safeFit}
      data-page-zoomed={zoomed ? "true" : "false"}
      data-reader-mode="double"
      style={{ touchAction: zoomed ? "none" : TOUCH_ACTION[safeFit] }}
      onPointerDownCapture={(event) => { lastPointerTypeRef.current = event.pointerType; }}
    >
      <div
        ref={contentRef}
        className={`flex ${safeFit === "width" ? "w-full items-start" : "max-h-full max-w-full items-center"} justify-center gap-2 ${isSwiping || zoomed ? "" : "transition-transform duration-200 motion-reduce:transition-none"}`}
        style={{
          transform: `translate3d(${transform.x + swipeOffset}px, ${transform.y}px, 0) scale(${transform.scale})`,
          transformOrigin: "center center",
        }}
        onClick={(event) => {
          if (event.target.closest("button, a, input, select, textarea")) return;
          if (lastPointerTypeRef.current === "mouse") onImageClick?.(event);
        }}
      >
        {pages.map(({ pageIndex, image, isLoading, hasFailed, onRetry, isStale }) => (
          <div key={pageIndex} className="relative flex h-full min-h-0 min-w-0 flex-1 items-start justify-center">
            {image && (
              <img
                src={image.src}
                alt={isStale ? "" : `Page ${pageIndex + 1} of ${title || "Comic"}`}
                aria-hidden={isStale ? "true" : undefined}
                draggable={false}
                className={`block object-contain shadow-lg ${imageClasses(safeFit)}`}
              />
            )}
            {isLoading && !image && <div className="aspect-[2/3] w-full max-w-md animate-pulse rounded bg-muted" />}
            {isLoading && image && (
              <span role="status" className="absolute bottom-3 rounded-full bg-background/90 px-3 py-1 text-xs shadow">
                Loading page {pageIndex + 1}…
              </span>
            )}
            {hasFailed && (
              <div className="m-auto flex flex-col items-center rounded-md bg-destructive-foreground p-4 text-destructive">
                <p className="mb-2">Error loading page {pageIndex + 1}.</p>
                <Button variant="outline" onClick={onRetry}>Retry</Button>
              </div>
            )}
          </div>
        ))}
      </div>
      {children}
    </div>
  );
}
