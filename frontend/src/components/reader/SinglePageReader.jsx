import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useReaderGestures } from "@/hooks/use-reader-gestures";
import { useReaderMousePan } from "@/hooks/use-reader-mouse-pan";
import { useReaderSurfaceClicks } from "@/hooks/use-reader-surface-clicks";
import { singlePageAppearance } from "@/lib/reader-single-page";
import { IDENTITY_TRANSFORM, isZoomed } from "@/lib/reader-zoom";

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
  const zoomed = isZoomed(transform);
  const { safeFit, viewportClass, touchAction, imageClass, alt, ariaHidden } = singlePageAppearance({
    fit, zoomed, isSwiping, isStale, pageNumber, title,
  });
  // Mouse clicks and touch taps reach this element by different routes, and
  // only the mouse's belong to the caller: a browser sends a click after a tap
  // too, so without this a double tap would zoom and the trailing click would
  // immediately undo it.
  const surfaceClicks = useReaderSurfaceClicks({ onSurfaceClick, onSurfaceDoubleClick });

  useReaderGestures(containerRef, { zoomed, paged, ...gestures });
  const { cursorClass } = useReaderMousePan(containerRef, { enabled: zoomed, onPan: gestures?.onPan });

  return (
    <div
      ref={containerRef}
      className={`relative max-h-full h-full w-full flex ${viewportClass} ${cursorClass}`}
      data-page-fit={safeFit}
      data-page-zoomed={zoomed ? "true" : "false"}
      style={{ touchAction }}
      {...surfaceClicks}
    >
      {image && (
        <img
          ref={imageRef}
          src={image.src}
          alt={alt}
          aria-hidden={ariaHidden}
          data-reader-artwork="true"
          // A dragged image is the browser offering to copy a file, which under
          // a finger or a mouse is never what a page turn meant.
          draggable={false}
          className={imageClass}
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
