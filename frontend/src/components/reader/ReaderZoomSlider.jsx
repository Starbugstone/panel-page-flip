import { Label } from "@/components/ui/label";

/**
 * The zoom slider, and what zooming means in the mode currently selected.
 *
 * Continuous scrolling has no page to magnify, so there the same number widens
 * the reading column instead — worth saying, because the control looks
 * identical either way.
 */
export function ReaderZoomSlider({ zoomLevel, canZoom, continuousZoom, isLoaded, onZoomChange }) {
  const percent = Math.round(zoomLevel * 100);

  return (
        <div className="space-y-2">
          <div className="flex items-center justify-between gap-3">
            <Label htmlFor="reader-zoom">Zoom level</Label>
            <span className="min-w-12 text-right text-sm font-medium" aria-live="polite">
              {percent}%
            </span>
          </div>
          <input
            id="reader-zoom"
            type="range"
            min="100"
            max="500"
            step="25"
            value={percent}
            onChange={(event) => onZoomChange?.(Number(event.target.value) / 100)}
            disabled={!isLoaded || !canZoom}
            aria-label="Zoom level"
            aria-valuetext={`${percent}%`}
            className="h-2 w-full cursor-pointer accent-primary disabled:cursor-not-allowed disabled:opacity-50"
          />
          <p className="text-xs text-muted-foreground">
            {canZoom
              ? continuousZoom
                ? "Adjust the width of every page while keeping continuous scrolling."
                : "Adjust the page or spread. This zoom stays when you turn pages, and each new page starts at the top."
              : "Zoom becomes available once the comic has pages."}
          </p>
        </div>
  );
}
