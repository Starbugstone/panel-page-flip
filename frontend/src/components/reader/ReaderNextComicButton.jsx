import { ArrowRight, BookOpen } from "lucide-react";

import { Button } from "@/components/ui/button.jsx";

/** A visual confirmation of the title the reader is about to open. */
export function ReaderNextComicButton({ comic, onClick }) {
  const title = comic.title || "Untitled comic";

  return (
    <Button
      variant="outline"
      onClick={onClick}
      aria-label={`Read next comic: ${title}`}
      className="h-auto w-full max-w-sm justify-start gap-3 self-center overflow-hidden bg-card p-2 text-left"
      data-next-comic-preview="true"
    >
      {comic.coverImagePath ? (
        <img
          src={comic.coverImagePath}
          alt=""
          loading="eager"
          decoding="async"
          className="h-14 w-10 shrink-0 rounded object-cover"
        />
      ) : (
        <span className="flex h-14 w-10 shrink-0 items-center justify-center rounded bg-muted" aria-hidden="true">
          <BookOpen className="h-5 w-5 text-muted-foreground" />
        </span>
      )}

      <span className="min-w-0 flex-1">
        <span className="block text-xs font-normal text-muted-foreground">Next comic</span>
        <span className="block truncate font-semibold" title={title}>{title}</span>
      </span>
      <ArrowRight className="h-5 w-5 shrink-0" />
    </Button>
  );
}
