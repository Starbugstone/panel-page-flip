import { ArrowLeft, ArrowRight, RefreshCw } from "lucide-react";

import { ReaderNextComicButton } from "@/components/reader/ReaderNextComicButton";
import { ReaderPageJump } from "@/components/reader/ReaderPageJump";
import { Button } from "@/components/ui/button.jsx";
import { Progress } from "@/components/ui/progress.jsx";

/** The bar along the bottom: progress, page turns, and the jump-to-page box. */
export function ReaderPageControls({
  controlsRef,
  currentPage,
  currentPageRef,
  pageCount,
  unitLabel,
  unitLength,
  progressUpTo,
  progressLabel,
  showProgress,
  effectiveMode,
  isFullscreen,
  isChromeHidden,
  isZoomed,
  zoomScale,
  canGoPrevious,
  canGoNext,
  onPrevious,
  onNext,
  onGoToPage,
  onForceReload,
  nextComic,
  onNextComic,
}) {
  const buttonBackground = isFullscreen ? "" : "bg-card";
  const isLastPage = pageCount > 0 && progressUpTo >= pageCount;
  const showNextComic = isLastPage && nextComic;

  return (
    <div
      ref={controlsRef}
      role="group"
      aria-label="Reader page controls"
      className={`reader-controls ${isFullscreen ? "reader-controls-fullscreen" : ""} ${isChromeHidden ? "reader-chrome-hidden" : ""}`}
    >
      {showProgress && pageCount > 0 && (
        <Progress
          value={(progressUpTo / pageCount) * 100}
          aria-label={progressLabel}
          className="h-1 w-full rounded-none bg-muted/60"
        />
      )}

      {showNextComic && <ReaderNextComicButton comic={nextComic} onClick={onNextComic} />}

      <div className="flex w-full items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            onClick={onPrevious}
            disabled={!canGoPrevious}
            aria-label="Previous page"
            aria-keyshortcuts="ArrowLeft"
            title="Previous page (Left arrow)"
            className={`shrink-0 ${buttonBackground}`}
          >
            <ArrowLeft className="h-4 w-4 sm:mr-2" /><span className="hidden sm:inline">Previous</span>
          </Button>

          {/* Continuous mode holds no decoded page of its own to replace. */}
          {effectiveMode !== "continuous" && (
            <Button
              variant="outline"
              size="icon"
              onClick={onForceReload}
              aria-label="Force reload current page"
              title="Force reload current page"
              className={`hidden shrink-0 min-[400px]:inline-flex ${buttonBackground}`}
            >
              <RefreshCw className="h-4 w-4" />
            </Button>
          )}
        </div>

        <ReaderPageJump
          currentPage={currentPage}
          currentPageRef={currentPageRef}
          pageCount={pageCount}
          unitLabel={unitLabel}
          unitLength={unitLength}
          isZoomed={isZoomed}
          zoomScale={zoomScale}
          onGoToPage={onGoToPage}
        />

        {showNextComic ? (
          <span className="h-10 w-10 shrink-0 sm:w-[5.75rem]" aria-hidden="true" />
        ) : (
          <Button
            variant="outline"
            onClick={onNext}
            disabled={!canGoNext}
            aria-label="Next page"
            aria-keyshortcuts="ArrowRight"
            title="Next page (Right arrow)"
            className={`shrink-0 ${buttonBackground}`}
          >
            <span className="hidden sm:inline">Next</span><ArrowRight className="h-4 w-4 sm:ml-2" />
          </Button>
        )}
      </div>
    </div>
  );
}
