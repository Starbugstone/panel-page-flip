import { BookOpen, RotateCcw } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { CoverFailureArt, CoverLoadingArt } from "@/components/library/CoverPlaceholder";
import { useCoverImage } from "@/hooks/use-cover-image";
import { cn } from "@/lib/utils";

/**
 * The cover, and the reading position laid over it.
 *
 * When the cover is requested is {@link useCoverImage}'s decision, not the
 * browser's: a grid is dozens of these, and letting every one of them start at
 * once is how a scroll through a large library turns into an outage of our own
 * making. What is left here is what it looks like while that happens — the
 * placeholder, the fade in, and the way back from a cover that never arrived.
 */
export function ComicCardCover({ comic, coverPriority, isSharedWithMe, onResetProgress }) {
  // Destructured, not held as one object: assigning `observe` to a `ref` prop
  // is enough for the compiler's lint to treat everything reached through the
  // same object as a ref being read during render.
  const { observe, src, status, attempt, onLoad, onError, retry } = useCoverImage(
    comic.coverImagePath,
    { eager: coverPriority }
  );

  return (
    <div
      ref={observe}
      data-cover-state={status}
      className="relative overflow-hidden bg-muted pt-[140%]"
    >
      {src && (
        <img
          src={src}
          alt={comic.title}
          decoding="async"
          fetchPriority={coverPriority ? "high" : "auto"}
          onLoad={onLoad}
          onError={onError}
          // One transition property covering both: separate transition-* classes
          // would be merged down to whichever came last.
          className={cn(
            "absolute inset-0 h-full w-full object-cover",
            "transition-[transform,opacity] duration-150 group-hover:scale-105",
            status === "loaded" ? "opacity-100" : "opacity-0"
          )}
        />
      )}

      {status === "absent" && (
        <div className="absolute inset-0 flex items-center justify-center bg-muted">
          <BookOpen className="h-8 w-8 text-muted-foreground" aria-hidden="true" />
        </div>
      )}
      {(status === "loading" || status === "retrying") && (
        <CoverLoadingArt isRetrying={attempt > 0} />
      )}
      {status === "failed" && <CoverFailureArt title={comic.title} onRetry={retry} />}

      {isSharedWithMe && (
        <Badge className="absolute left-2 top-2 bg-comic-purple text-white">
          Shared by {comic.sharedBy?.name || "another user"}
        </Badge>
      )}
      {comic.lastReadPage !== undefined && (
        <div className="absolute bottom-0 left-0 right-0 flex items-center justify-between bg-black/70 p-2 text-xs text-white">
          <span>Page {comic.lastReadPage} / {comic.pageCount}</span>
          <Button
            variant="ghost"
            size="sm"
            className="relative z-10 h-7 w-7 p-0 text-white hover:text-red-400"
            onClick={onResetProgress}
            aria-label={`Reset reading progress for ${comic.title}`}
          >
            <RotateCcw size={16} />
          </Button>
        </div>
      )}
    </div>
  );
}
