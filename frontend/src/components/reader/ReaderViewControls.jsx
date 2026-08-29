import { LayoutGrid, Maximize, Minimize, ZoomIn, ZoomOut } from "lucide-react";

import { ReaderSettings } from "@/components/reader/ReaderSettings";
import { Button } from "@/components/ui/button.jsx";

const ICON_BUTTON = "bg-card/80 opacity-80 hover:opacity-100";

/**
 * The small group at the top-right: settings, fullscreen, zoom and thumbnails.
 *
 * Absolutely positioned over the page in a window and pulled clear of the
 * browser's own furniture in fullscreen, which is why the two cases carry
 * different classes rather than one that tries to suit both.
 */
export function ReaderViewControls({
  settings,
  preferences,
  effectiveMode,
  isFullscreen,
  isChromeHidden,
  isZoomed,
  showThumbnails,
  pageCount,
  zoomLevel,
  preferredZoomLevel,
  contextLabel,
  hasOverride,
  onSettingsChange,
  onZoomChange,
  onOverrideChange,
  onSettingsOpenChange,
  onResetSettings,
  onToggleFullscreen,
  onZoomOut,
  onZoomIn,
  onToggleThumbnails,
}) {
  return (
    <div
      role="group"
      aria-label="Reader view controls"
      className={`${isFullscreen ? "fullscreen-controls" : "reader-view-controls absolute z-20 flex gap-2 transition-opacity duration-300 motion-reduce:transition-none"} ${isChromeHidden ? "reader-chrome-hidden" : ""}`}
    >
      <ReaderSettings
        settings={settings}
        isLoaded={preferences.isLoaded}
        isSaving={preferences.isSaving}
        hasSyncError={preferences.hasSyncError}
        contextLabel={contextLabel}
        hasOverride={hasOverride}
        modeNotice={settings.mode === "double" && effectiveMode !== "double"
          ? "Two-page mode uses one page on narrow or portrait screens."
          : null}
        zoomLevel={zoomLevel}
        canZoom={pageCount > 0}
        continuousZoom={effectiveMode === "continuous"}
        onChange={onSettingsChange}
        onZoomChange={onZoomChange}
        onOverrideChange={onOverrideChange}
        onOpenChange={onSettingsOpenChange}
        onReset={onResetSettings}
      />

      <Button
        variant="outline"
        size="icon"
        className={ICON_BUTTON}
        onClick={onToggleFullscreen}
        aria-label={isFullscreen ? "Exit fullscreen" : "Enter fullscreen"}
        title={isFullscreen ? "Exit fullscreen" : "Enter fullscreen"}
      >
        {isFullscreen ? <Minimize className="h-4 w-4" /> : <Maximize className="h-4 w-4" />}
      </Button>

      {/* Continuous mode widens its column instead of transforming a page, so
          there is nothing here for a zoom button to act on. */}
      {effectiveMode !== "continuous" && (isZoomed ? (
        <Button variant="outline" size="icon" className={ICON_BUTTON} onClick={onZoomOut} aria-label="Zoom out" title="Zoom out">
          <ZoomOut className="h-4 w-4" />
        </Button>
      ) : (
        <Button
          variant="outline"
          size="icon"
          className={ICON_BUTTON}
          onClick={onZoomIn}
          aria-label="Zoom in"
          title={`Zoom in to ${Math.round(preferredZoomLevel * 100)}%`}
        >
          <ZoomIn className="h-4 w-4" />
        </Button>
      ))}

      <Button
        variant="outline"
        size="icon"
        className={ICON_BUTTON}
        onClick={onToggleThumbnails}
        aria-label={showThumbnails ? "Hide page thumbnails" : "Show page thumbnails"}
        aria-expanded={showThumbnails}
        aria-controls="reader-thumbnail-strip"
        title="Page thumbnails"
      >
        <LayoutGrid className="h-4 w-4" />
      </Button>
    </div>
  );
}
