import { ImageOff, RefreshCw } from "lucide-react";

import { cn } from "@/lib/utils";

// Out of step with each other, so the panels read as a comic page being laid
// out rather than as one block fading.
const PANEL_DELAYS = ["0ms", "180ms", "360ms", "260ms"];

/**
 * What fills comic artwork's space while the server is being asked for it.
 *
 * Deliberately comic-shaped: panels being laid onto a page, with a light
 * sweeping over them. A generic spinner in a grid of forty cells is forty
 * spinners; this settles into the layout the cover is about to occupy, so the
 * grid looks like it is being drawn rather than like it is failing.
 *
 * Decorative, and hidden from assistive technology on purpose. The cover
 * carries the comic's title in its `alt`, and the card says the title in text
 * directly underneath — announcing "loading" forty times says nothing that the
 * page does not already say better.
 */
export function ComicLoadingArt({ isRetrying = false }) {
  return (
    <div
      aria-hidden="true"
      className="absolute inset-0 overflow-hidden bg-gradient-to-br from-muted via-muted to-comic-purple-soft/40"
    >
      <div className="absolute inset-[8%] grid grid-cols-2 grid-rows-[1.6fr_1fr_1fr] gap-[6%]">
        {PANEL_DELAYS.map((delay, index) => (
          <div
            key={delay}
            style={{ animationDelay: delay }}
            className={cn(
              "rounded-[3px] bg-comic-purple/25 motion-safe:animate-cover-panel",
              index === 0 && "col-span-2"
            )}
          />
        ))}
      </div>

      <div className="absolute inset-y-0 -left-1/3 w-1/3 bg-gradient-to-r from-transparent via-white/40 to-transparent motion-safe:animate-cover-sweep dark:via-white/10" />

      {isRetrying && (
        <RefreshCw
          className="absolute bottom-2 right-2 h-4 w-4 text-comic-purple-dark motion-safe:animate-spin"
          strokeWidth={2.5}
        />
      )}
    </div>
  );
}

/**
 * A cover the server would not give up, after it has been asked for as many
 * times as is polite.
 *
 * The retry is a real button rather than an automatic loop: at this point the
 * failure has outlived a burst of load, and the reader deciding to ask again is
 * better information than a timer deciding it.
 */
export function CoverFailureArt({ title, onRetry }) {
  return (
    <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-muted px-3 text-center">
      <ImageOff className="h-7 w-7 text-muted-foreground" aria-hidden="true" />
      <p className="text-xs text-muted-foreground">Cover unavailable</p>
      <button
        type="button"
        onClick={onRetry}
        aria-label={`Retry cover for ${title}`}
        className="relative z-10 inline-flex items-center gap-1 rounded border border-input bg-background px-2 py-1 text-xs font-medium hover:bg-accent"
      >
        <RefreshCw className="h-3 w-3" aria-hidden="true" />
        Retry
      </button>
      <span className="sr-only">{`Cover of ${title} could not be loaded`}</span>
    </div>
  );
}
