import { ComicLoadingArt } from "@/components/library/CoverPlaceholder";
import { cn } from "@/lib/utils";

/** The library's comic-panel loading artwork, sized for one reader page slot. */
export function ReaderPageLoading({ pageNumber, className }) {
  return (
    <div
      role="status"
      data-reader-page-loading={pageNumber}
      className={cn("pointer-events-none relative overflow-hidden bg-muted", className)}
    >
      <ComicLoadingArt />
      <span className="sr-only">Loading page {pageNumber}…</span>
    </div>
  );
}
