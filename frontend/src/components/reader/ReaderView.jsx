import { ReaderPageControls } from "@/components/reader/ReaderPageControls";
import { ReaderStage } from "@/components/reader/ReaderStage";
import { ReaderSuggestions } from "@/components/reader/ReaderSuggestions";
import { ReaderThumbnailStrip } from "@/components/reader/ReaderThumbnailStrip";
import { ReaderViewControls } from "@/components/reader/ReaderViewControls";
import { toggleFullscreen } from "@/lib/fullscreen";
import { describeViewportContext } from "@/lib/reader-viewport";

/**
 * How wide the stage may be. Fitted artwork is held to a comfortable column;
 * anything the reader has deliberately made bigger — a width or original fit, a
 * zoom, a continuous scroll — is given the whole window.
 */
const stageWidthClass = ({ effectiveMode, fit, isZoomed }) =>
  effectiveMode === "continuous" || fit === "width" || fit === "original" || isZoomed ? "max-w-none" : "max-w-4xl";

/**
 * Everything inside the reader's shell: the page itself, the two groups of
 * controls, the thumbnail strip and whichever prompt is showing.
 *
 * Arguments arrive grouped rather than as forty parameters — `book` is what is
 * being read, `view` is how it is currently being shown, `refs` are the
 * elements the gesture and measurement hooks need, and `actions` is everything
 * that can be done to any of it.
 */
export function ReaderView({ book, view, refs, actions, suggestions, nextComic, onNextComic }) {
  const { settings, profile, transform, isZoomed, isFullscreen, isChromeHidden } = view;
  const contextLabel = describeViewportContext(profile);

  return (
    <>
      <div className={`${stageWidthClass({ effectiveMode: book.effectiveMode, fit: settings.fit, isZoomed })} ${isFullscreen ? "reader-stage-fullscreen" : "reader-stage"} ${book.effectiveMode !== "continuous" && !isChromeHidden ? "reader-stage-controls-visible" : ""} flex w-full items-center justify-center pt-4`}>
        <ReaderStage
          book={book}
          view={view}
          containerRef={refs.container}
          imageRef={refs.image}
          gestures={actions.gestures}
          turnZones={actions.turnZones}
          onCurrentPageChange={actions.goToLogicalPage}
          onActivity={actions.toggleChrome}
          onSurfaceClick={actions.onSurfaceClick}
          onSurfaceDoubleClick={actions.onSurfaceDoubleClick}
          onRetryCurrent={() => actions.retryPage(book.currentPage)}
        />
      </div>

      <ReaderViewControls
        settings={settings}
        preferences={view.preferences}
        effectiveMode={book.effectiveMode}
        isFullscreen={isFullscreen}
        isChromeHidden={isChromeHidden}
        isZoomed={isZoomed}
        showThumbnails={view.showThumbnails}
        pageCount={book.pageCount}
        zoomLevel={transform.scale}
        preferredZoomLevel={view.preferredZoomLevel}
        contextLabel={contextLabel}
        hasOverride={view.hasContextOverride}
        onSettingsChange={actions.changeSettings}
        onZoomChange={actions.changeZoomLevel}
        onOverrideChange={actions.changeContextOverride}
        onSettingsOpenChange={actions.setSettingsOpen}
        onResetSettings={actions.resetSettings}
        onToggleFullscreen={() => toggleFullscreen(document)}
        onZoomOut={actions.zoomToFit}
        onZoomIn={actions.zoomIn}
        onToggleThumbnails={actions.toggleThumbnails}
      />

      {view.showThumbnails && book.pageCount > 0 && (
        <ReaderThumbnailStrip
          key={`${book.comicId}-${book.pageCount}`}
          comicId={book.comicId}
          pageCount={book.pageCount}
          currentPage={book.currentPage}
          geometry={book.pageGeometry}
          onSelect={actions.goToReaderPage}
        />
      )}

      {!view.isSettingsOpen && <ReaderSuggestions suggestions={suggestions} contextLabel={contextLabel} />}

      <ReaderPageControls
        controlsRef={refs.controls}
        currentPage={book.currentPage}
        currentPageRef={book.currentPageRef}
        pageCount={book.pageCount}
        unitLabel={book.unitLabel}
        unitLength={book.currentUnit.length}
        progressUpTo={Math.max(...book.currentUnit) + 1}
        progressLabel={book.currentUnit.length > 1
          ? `Pages ${book.unitLabel} of ${book.pageCount}`
          : `Page ${book.currentPage + 1} of ${book.pageCount}`}
        showProgress={settings.showProgress}
        effectiveMode={book.effectiveMode}
        isFullscreen={isFullscreen}
        isChromeHidden={isChromeHidden}
        isZoomed={isZoomed}
        zoomScale={transform.scale}
        canGoPrevious={actions.canGoPrevious}
        canGoNext={actions.canGoNext}
        onPrevious={actions.goPrevious}
        onNext={actions.goNext}
        onGoToPage={actions.goToReaderPage}
        onForceReload={actions.forceReload}
        nextComic={nextComic}
        onNextComic={onNextComic}
      />
    </>
  );
}
