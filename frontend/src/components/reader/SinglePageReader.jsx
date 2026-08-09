import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";

const VIEWPORT_CLASSES = {
  contain: "items-center justify-center overflow-hidden",
  width: "items-start justify-center overflow-x-hidden overflow-y-auto",
  height: "items-center justify-center overflow-hidden",
  original: "items-start justify-start overflow-auto",
};

const IMAGE_CLASSES = {
  contain: "max-h-full max-w-full h-auto w-auto object-contain",
  width: "h-auto w-full max-h-none max-w-none object-contain",
  height: "h-full w-auto max-h-full max-w-none object-contain",
  original: "h-auto w-auto max-h-none max-w-none object-none",
};

export function SinglePageReader({
  containerRef,
  image,
  isLoading,
  hasFailed,
  pageNumber,
  title,
  fit,
  isFullscreen,
  isZoomed,
  zoomLevel,
  mousePosition,
  onMouseMove,
  onImageClick,
  onRetry,
  children,
}) {
  const safeFit = Object.hasOwn(VIEWPORT_CLASSES, fit) ? fit : "contain";

  return (
    <div
      ref={containerRef}
      className={`relative max-h-full h-full w-full flex ${VIEWPORT_CLASSES[safeFit]} ${isFullscreen ? "fullscreen-container" : ""}`}
      data-page-fit={safeFit}
      onMouseMove={onMouseMove}
    >
      {image && (
        <img
          src={image.src}
          alt={`Page ${pageNumber} of ${title || "Comic"}`}
          className={`${IMAGE_CLASSES[safeFit]} mx-auto block shadow-lg transition-transform ${isZoomed ? "zoomed-image" : ""}`}
          style={{
            transform: isZoomed ? `scale(${zoomLevel})` : "none",
            transformOrigin: isZoomed ? `${mousePosition.x * 100}% ${mousePosition.y * 100}%` : "center center",
          }}
          onClick={onImageClick}
        />
      )}

      {hasFailed && (
        <div className="m-auto flex flex-col items-center justify-center rounded-md bg-destructive-foreground p-4 text-destructive">
          <p className="mb-2">Error loading page {pageNumber}.</p>
          <Button variant="outline" onClick={onRetry}>Retry</Button>
        </div>
      )}

      {isLoading && (
        <div className="absolute inset-0 flex items-center justify-center">
          <Skeleton className="mx-auto h-full w-full max-w-full object-contain" />
        </div>
      )}

      {children}
    </div>
  );
}
