import { useState } from "react";
import { RotateCcw } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

/**
 * The cover, and the reading position laid over it.
 *
 * A cover served from the browser cache decodes in the same frame, so the fade
 * only ever runs on a genuine first load rather than on every revisit. A comic
 * with no cover has nothing to wait for, and one that fails to load still has
 * to reveal its alt text.
 */
export function ComicCardCover({ comic, coverPriority, isSharedWithMe, onResetProgress }) {
  const [loaded, setLoaded] = useState(!comic.coverImagePath);

  return (
    <div className="relative overflow-hidden bg-muted pt-[140%]">
      <img
        src={comic.coverImagePath}
        alt={comic.title}
        decoding="async"
        loading={coverPriority ? "eager" : "lazy"}
        fetchPriority={coverPriority ? "high" : "auto"}
        onLoad={() => setLoaded(true)}
        onError={() => setLoaded(true)}
        // One transition property covering both: separate transition-* classes
        // would be merged down to whichever came last.
        className={cn(
          "absolute inset-0 h-full w-full object-cover",
          "transition-[transform,opacity] duration-150 group-hover:scale-105",
          loaded ? "opacity-100" : "opacity-0"
        )}
      />
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
            className="h-7 w-7 p-0 text-white hover:text-red-400"
            onClick={(event) => {
              // Inside the card's Link; without this the reset opens the reader.
              event.preventDefault();
              event.stopPropagation();
              onResetProgress();
            }}
            aria-label={`Reset reading progress for ${comic.title}`}
          >
            <RotateCcw size={16} />
          </Button>
        </div>
      )}
    </div>
  );
}
