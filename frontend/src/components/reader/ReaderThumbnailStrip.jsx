import { useEffect, useRef, useState } from "react";

import { createPageThumbnailUrl } from "@/lib/reader-pages";
import { useCoverImage } from "@/hooks/use-cover-image";

/**
 * How many thumbnails either side of the current page are fetched without being
 * asked for. Enough to fill the strip on a wide screen, few enough that opening
 * a 400-page book is not 400 requests.
 */
const EAGER_WINDOW = 3;

function ThumbnailImage({ url }) {
  // These share the cover request budget. Opening the strip must not launch
  // a screenful of expensive resizes ahead of the page being read.
  const { src, onLoad, onError } = useCoverImage(url, { eager: true });

  return src ? <img src={src} alt="" decoding="async" loading="lazy" fetchPriority="low" onLoad={onLoad} onError={onError} className="h-full w-full object-contain" /> : null;
}

/**
 * A page navigator built from the same derivative pipeline the reader uses.
 *
 * Every slot exists in the DOM from the start — buttons are cheap, and they are
 * what gives the strip its scroll length and the browser its tab order. The
 * images are the expensive part, so they are mounted only for pages near the
 * current one and for slots the reader has actually scrolled to.
 *
 * Which slots have been scrolled to is state about one particular comic, so the
 * caller keys this component by comic: a new comic is a new strip rather than an
 * old one that has to remember to forget.
 */
export function ReaderThumbnailStrip({ comicId, pageCount, currentPage, viewportContext, geometry = {}, onSelect }) {
  const [revealed, setRevealed] = useState(() => new Set());
  const containerRef = useRef(null);
  const currentRef = useRef(null);

  useEffect(() => {
    if (typeof IntersectionObserver !== "function") return undefined;

    const observer = new IntersectionObserver(
      (entries) => {
        setRevealed((previous) => {
          const next = new Set(previous);
          entries.forEach((entry) => {
            const index = Number(entry.target.dataset.pageIndex);
            if (!Number.isInteger(index)) return;
            if (entry.isIntersecting) next.add(index);
            else next.delete(index);
          });
          return next;
        });
      },
      // Slightly ahead of the scroll, so a thumbnail is usually there by the
      // time it is looked at rather than appearing under the reader's eye.
      { root: containerRef.current, rootMargin: "300px" }
    );

    // Callback refs run before effects, when the observer does not exist yet.
    containerRef.current?.querySelectorAll('[data-page-index]').forEach((node) => observer.observe(node));
    return () => observer.disconnect();
  }, [comicId, pageCount]);

  useEffect(() => {
    currentRef.current?.scrollIntoView({ block: "nearest", inline: "center" });
  }, [currentPage, viewportContext]);

  if (!comicId || !Number.isInteger(pageCount) || pageCount <= 0) return null;

  return (
    <div
      ref={containerRef}
      id="reader-thumbnail-strip"
      role="group"
      aria-label="Page thumbnails"
      className="reader-thumbnail-strip flex w-full gap-2 overflow-x-auto border-t bg-card/80 px-3 pt-2"
    >
      {Array.from({ length: pageCount }, (_, index) => {
        const pageNumber = index + 1;
        const isCurrent = index === currentPage;
        const shouldLoad = revealed.has(index) || Math.abs(index - currentPage) <= EAGER_WINDOW;
        const aspectRatio = geometry[pageNumber]?.aspectRatio;

        return (
          <button
            key={pageNumber}
            type="button"
            data-page-index={index}
            ref={(node) => {
              if (isCurrent) currentRef.current = node;
            }}
            onClick={() => onSelect?.(index)}
            aria-label={`Go to page ${pageNumber}`}
            aria-current={isCurrent ? "true" : undefined}
            className={`flex shrink-0 flex-col items-center gap-1 rounded-md border p-1 text-xs ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 ${
              isCurrent ? "border-primary bg-primary/10 font-semibold" : "border-transparent hover:border-muted-foreground/40"
            }`}
          >
            <span
              className="flex h-24 w-16 items-center justify-center overflow-hidden rounded-sm bg-muted"
              style={aspectRatio ? { aspectRatio: String(aspectRatio), height: "6rem", width: "auto" } : undefined}
            >
              {shouldLoad && (
                <ThumbnailImage url={createPageThumbnailUrl(comicId, pageNumber)} />
              )}
            </span>
            <span>{pageNumber}</span>
          </button>
        );
      })}
    </div>
  );
}
