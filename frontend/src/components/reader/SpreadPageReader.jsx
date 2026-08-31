import { Button } from "@/components/ui/button";
import { useReaderGestures } from "@/hooks/use-reader-gestures";
import { useReaderMousePan } from "@/hooks/use-reader-mouse-pan";
import { useReaderSurfaceClicks } from "@/hooks/use-reader-surface-clicks";
import { readerFitAppearance } from "@/lib/reader-fit";
import { IDENTITY_TRANSFORM, isZoomed } from "@/lib/reader-zoom";

/**
 * The fits that letterbox a page rather than letting it overflow and scroll.
 *
 * These are the ones that have to bound the artwork's *height*, and the only
 * way a percentage height binds is if every box above it has a definite one —
 * hence `h-full` on the spread rather than `max-h-full`. Without it a reading
 * unit holding a single page (the last page of an odd-length comic, or a cover
 * kept alone) takes the full width, grows past the bottom of the viewport and
 * is clipped by the container's own `overflow-hidden`.
 */
const LETTERBOXED_FITS = new Set(["contain", "height"]);

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
  onSurfaceClick,
  onSurfaceDoubleClick,
  children,
}) {
  const zoomed = isZoomed(transform);
  const { safeFit, viewportClass, touchAction } = readerFitAppearance(fit, zoomed);
  const surfaceClicks = useReaderSurfaceClicks({ onSurfaceClick, onSurfaceDoubleClick });

  useReaderGestures(containerRef, { zoomed, paged: true, ...gestures });
  const { cursorClass } = useReaderMousePan(containerRef, { enabled: zoomed, onPan: gestures?.onPan });

  return (
    <div
      ref={containerRef}
      className={`relative flex h-full max-h-full w-full ${viewportClass} ${cursorClass}`}
      data-page-fit={safeFit}
      data-page-zoomed={zoomed ? "true" : "false"}
      data-reader-mode="double"
      style={{ touchAction }}
      {...surfaceClicks}
    >
      <div
        ref={contentRef}
        className={`flex ${safeFit === "width" ? "w-full items-start" : safeFit === "original" ? "w-max max-w-none items-start" : "h-full max-w-full items-center"} justify-center gap-2 ${isSwiping || zoomed ? "" : "transition-transform duration-200 motion-reduce:transition-none"}`}
        style={{
          transform: `translate3d(${transform.x + swipeOffset}px, ${transform.y}px, 0) scale(${transform.scale})`,
          transformOrigin: "center center",
        }}
      >
        {pages.map(({ pageIndex, image, isLoading, hasFailed, onRetry, isStale }) => (
          <div key={pageIndex} className={`relative flex h-full min-h-0 min-w-0 justify-center ${LETTERBOXED_FITS.has(safeFit) ? "items-center" : "items-start"} ${safeFit === "original" ? "flex-none" : "flex-1"}`}>
            {image && (
              <img
                src={image.src}
                alt={isStale ? "" : `Page ${pageIndex + 1} of ${title || "Comic"}`}
                aria-hidden={isStale ? "true" : undefined}
                data-reader-artwork="true"
                draggable={false}
                className={`block select-none object-contain shadow-lg ${imageClasses(safeFit)}`}
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
