import { ContinuousPageReader } from "@/components/reader/ContinuousPageReader";
import { ReaderPageTurnZones } from "@/components/reader/ReaderPageTurnZones";
import { SinglePageReader } from "@/components/reader/SinglePageReader";
import { SpreadPageReader } from "@/components/reader/SpreadPageReader";

/** Which reader is on screen, and the empty state that stands in for all of them. */
export function ReaderStage({
  book, view, containerRef, imageRef, gestures, turnZones,
  onCurrentPageChange, onActivity, onSurfaceClick, onSurfaceDoubleClick, onRetryCurrent,
}) {
  if (book.pageCount === 0) return <div className="text-xl">This comic has no pages to display.</div>;

  if (book.effectiveMode === "continuous") {
    return (
      <ContinuousPageReader
        containerRef={containerRef}
        comicId={book.comicId}
        pageCount={book.pageCount}
        currentPage={book.currentPage}
        title={book.comic.title}
        geometry={book.pageGeometry}
        resetToken={`${view.profile.orientation}:${book.effectiveMode}`}
        zoomLevel={view.transform.scale}
        onCurrentPageChange={onCurrentPageChange}
        onActivity={onActivity}
      />
    );
  }

  const shared = {
    containerRef,
    title: book.comic.title,
    fit: view.settings.fit,
    transform: view.transform,
    swipeOffset: view.swipeOffset,
    isSwiping: view.isSwiping,
    gestures,
    onSurfaceClick,
    onSurfaceDoubleClick,
  };

  return (
    <ReaderPageTurnZones {...turnZones}>
      {book.effectiveMode === "double" ? (
        <SpreadPageReader {...shared} contentRef={imageRef} pages={book.orderedPageStates} />
      ) : (
        <SinglePageReader
          {...shared}
          imageRef={imageRef}
          image={book.pageStates[0]?.image}
          isStale={book.pageStates[0]?.isStale}
          isLoading={book.pageStates[0]?.isLoading}
          hasFailed={book.pageStates[0]?.hasFailed}
          pageNumber={book.currentPage + 1}
          onRetry={onRetryCurrent}
        />
      )}
    </ReaderPageTurnZones>
  );
}
