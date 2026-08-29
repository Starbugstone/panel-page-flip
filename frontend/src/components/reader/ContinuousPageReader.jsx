import { useCallback, useEffect, useMemo, useRef, useState } from "react";

import { Button } from "@/components/ui/button";
import { usePageVariant } from "@/hooks/use-page-variant";
import { useReaderGestures } from "@/hooks/use-reader-gestures";
import { useReaderMousePan } from "@/hooks/use-reader-mouse-pan";
import { useReaderTransform } from "@/hooks/use-reader-transform";
import { createReaderPageUrl, withForcedReload } from "@/lib/reader-pages";

function ContinuousPageContent({ containerRef, comicId, pageIndex, title, resetToken, onActivity }) {
  const imageRef = useRef(null);
  const [retry, setRetry] = useState(0);
  const [result, setResult] = useState({ key: "", status: "loading" });
  const { transform, isZoomed, pinch, pan, doubleTapAt, resetTransform } = useReaderTransform({ containerRef, imageRef });
  // The settings zoom is already in the measurement: it widens this page's own
  // container rather than transforming it, so multiplying it in again would ask
  // the server for a rung the page will never show. A pinch is not in the
  // measurement — a CSS transform leaves layout size alone — so that one counts.
  const variant = usePageVariant(containerRef, { zoomLevel: transform.scale });
  const baseUrl = createReaderPageUrl(comicId, pageIndex + 1, variant);
  const url = useMemo(() => retry > 0 ? withForcedReload(baseUrl) : baseUrl, [baseUrl, retry]);
  const status = result.key === url ? result.status : "loading";
  const hasPreviousImage = result.status === "loaded";

  useEffect(() => resetTransform(), [resetToken, resetTransform]);
  const gestures = useMemo(() => ({
    onTap: () => onActivity?.(),
    onDoubleTap: ({ x, y }) => doubleTapAt({ x, y }),
    onPan: ({ dx, dy }) => pan({ dx, dy }),
    onPinch: ({ scale, focal, dx, dy }) => pinch({ scale, focal, dx, dy }),
  }), [doubleTapAt, onActivity, pan, pinch]);
  useReaderGestures(containerRef, { zoomed: isZoomed, paged: false, ...gestures });
  // A continuous page can only be zoomed by pinching or double tapping it, so
  // this is for a touch laptop rather than a desktop — but once a page is
  // larger than its slot, a mouse has to be able to move it too.
  const { cursorClass } = useReaderMousePan(containerRef, { enabled: isZoomed, onPan: pan });

  if (status === "failed") {
    return (
      <div className="absolute inset-0 flex flex-col items-center justify-center text-destructive">
        <p className="mb-2">Error loading page {pageIndex + 1}.</p>
        <Button variant="outline" onClick={() => setRetry((value) => value + 1)}>Retry</Button>
      </div>
    );
  }

  return (
    <>
      <img
        ref={imageRef}
        src={url}
        alt={`Page ${pageIndex + 1} of ${title || "Comic"}`}
        data-reader-artwork="true"
        draggable={false}
        onLoad={() => setResult({ key: url, status: "loaded" })}
        onError={() => setResult({ key: url, status: "failed" })}
        className={`block max-h-full max-w-full select-none object-contain shadow-lg ${cursorClass}`}
        style={{
          transform: `translate3d(${transform.x}px, ${transform.y}px, 0) scale(${transform.scale})`,
          transformOrigin: "center center",
          touchAction: isZoomed ? "none" : undefined,
        }}
      />
      {status === "loading" && !hasPreviousImage && <div className="pointer-events-none absolute inset-0 animate-pulse bg-muted" aria-hidden="true" />}
      {status === "loading" && hasPreviousImage && (
        <span role="status" className="pointer-events-none absolute bottom-3 rounded-full bg-background/90 px-3 py-1 text-xs shadow">
          Loading sharper page…
        </span>
      )}
    </>
  );
}

function ContinuousPage({ comicId, pageIndex, title, geometry, shouldLoad, resetToken, onActivity }) {
  const containerRef = useRef(null);
  const aspectRatio = Number.isFinite(geometry?.aspectRatio) && geometry.aspectRatio > 0 ? geometry.aspectRatio : 2 / 3;

  return (
    <article
      ref={containerRef}
      data-continuous-page={pageIndex}
      aria-label={`Page ${pageIndex + 1} of ${title || "comic"}`}
      className="relative mx-auto flex max-w-none items-center justify-center overflow-hidden bg-muted/20"
      style={{ aspectRatio: String(aspectRatio) }}
    >
      {shouldLoad ? (
        <ContinuousPageContent
          containerRef={containerRef}
          comicId={comicId}
          pageIndex={pageIndex}
          title={title}
          resetToken={resetToken}
          onActivity={onActivity}
        />
      ) : (
        <span className="text-sm text-muted-foreground">Page {pageIndex + 1}</span>
      )}
    </article>
  );
}

export function ContinuousPageReader({
  containerRef,
  comicId,
  pageCount,
  currentPage,
  title,
  geometry,
  resetToken,
  zoomLevel = 1,
  onCurrentPageChange,
  onActivity,
}) {
  const [nearPages, setNearPages] = useState(() => new Set([currentPage]));
  const ratiosRef = useRef(new Map());
  const reportedRef = useRef(currentPage);
  const positionedRef = useRef(false);
  const reportTimerRef = useRef(null);
  const suppressReportsUntilRef = useRef(0);

  const shouldLoadPage = useCallback(
    (pageIndex) => nearPages.has(pageIndex) || Math.abs(pageIndex - currentPage) <= 2,
    [currentPage, nearPages]
  );

  useEffect(() => {
    const root = containerRef.current;
    if (!root || typeof IntersectionObserver !== "function") return undefined;
    const proximityObserver = new IntersectionObserver((entries) => {
      setNearPages((previous) => {
        const next = new Set(previous);
        entries.forEach((entry) => {
          const pageIndex = Number(entry.target.dataset.continuousPage);
          if (!Number.isInteger(pageIndex)) return;
          if (entry.isIntersecting) next.add(pageIndex);
          else next.delete(pageIndex);
        });
        return next;
      });
    }, { root, rootMargin: "100% 0px", threshold: 0 });
    const visibilityObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        const pageIndex = Number(entry.target.dataset.continuousPage);
        if (Number.isInteger(pageIndex)) ratiosRef.current.set(pageIndex, entry.isIntersecting ? entry.intersectionRatio : 0);
      });
      clearTimeout(reportTimerRef.current);
      reportTimerRef.current = setTimeout(() => {
        if (Date.now() < suppressReportsUntilRef.current) return;
        const visible = [...ratiosRef.current.entries()]
          .filter(([, ratio]) => ratio > 0)
          .sort((a, b) => b[1] - a[1]);
        const nextPage = visible[0]?.[0];
        if (Number.isInteger(nextPage) && nextPage !== reportedRef.current) {
          reportedRef.current = nextPage;
          onCurrentPageChange?.(nextPage);
        }
      }, 180);
    }, { root, threshold: [0, 0.25, 0.5, 0.75, 1] });

    root.querySelectorAll("[data-continuous-page]").forEach((node) => {
      proximityObserver.observe(node);
      visibilityObserver.observe(node);
    });
    return () => {
      proximityObserver.disconnect();
      visibilityObserver.disconnect();
      clearTimeout(reportTimerRef.current);
    };
  }, [containerRef, onCurrentPageChange, pageCount]);

  useEffect(() => {
    if (!positionedRef.current) {
      positionedRef.current = true;
      if (currentPage === 0) return;
    } else if (reportedRef.current === currentPage) return;
    reportedRef.current = currentPage;
    suppressReportsUntilRef.current = Date.now() + 500;
    containerRef.current?.querySelector(`[data-continuous-page="${currentPage}"]`)?.scrollIntoView({ block: "start" });
  }, [containerRef, currentPage]);

  return (
    <div
      ref={containerRef}
      data-reader-mode="continuous"
      data-continuous-zoom={zoomLevel}
      className="reader-continuous h-full w-full overflow-auto overscroll-contain"
      // One custom property per zoom change instead of two inline style writes
      // on every page: a 200-page comic re-laid out the whole scroller on each
      // of the sixteen steps a slider drag fires.
      style={{ "--reader-page-zoom": zoomLevel, touchAction: "pan-x pan-y" }}
    >
      <div className="mx-auto flex w-full max-w-5xl flex-col gap-4 py-4">
        {Array.from({ length: pageCount }, (_, pageIndex) => (
          <ContinuousPage
            key={pageIndex}
            comicId={comicId}
            pageIndex={pageIndex}
            title={title}
            geometry={geometry[pageIndex + 1]}
            shouldLoad={shouldLoadPage(pageIndex)}
            resetToken={resetToken}
            onActivity={onActivity}
          />
        ))}
      </div>
    </div>
  );
}
