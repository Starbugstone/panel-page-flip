import { RotateCcw, Settings2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { ReaderSettingSwitch, ReaderSettingToggles } from "@/components/reader/ReaderSettingToggles";
import { ReaderZoomSlider } from "@/components/reader/ReaderZoomSlider";
import { Separator } from "@/components/ui/separator";
import { READER_DIRECTIONS, READER_FITS, READER_MODES } from "@/lib/reader-preferences";
import { PrivacyChoicesButton } from "@/components/ads/PrivacyChoicesButton.jsx";

export function ReaderSettings({
  settings,
  isLoaded,
  isSaving,
  hasSyncError,
  contextLabel,
  hasOverride,
  modeNotice,
  zoomLevel = 1,
  // Whether there is a comic to zoom at all. Every reading mode zooms — paged
  // by transforming the page, continuous by widening the column — so this is
  // not a mode gate, and the fallback copy below must not read like one.
  canZoom = true,
  continuousZoom = false,
  onChange,
  onZoomChange,
  onOverrideChange,
  onOpenChange,
  onReset,
}) {
  return (
    <Popover onOpenChange={onOpenChange}>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          size="icon"
          className="bg-card/80 opacity-80 hover:opacity-100"
          aria-label="Reader settings"
          title="Reader settings"
        >
          <Settings2 className="h-4 w-4" />
        </Button>
      </PopoverTrigger>
      <PopoverContent align="end" className="max-h-[var(--radix-popover-content-available-height)] w-[min(22rem,calc(100vw-2rem))] space-y-4 overflow-y-auto">
        <div>
          <h2 className="font-semibold">Reader settings</h2>
          <p className="mt-1 text-xs text-muted-foreground">Preferences sync across devices</p>
        </div>

        <div className="grid gap-3 sm:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor="reader-mode">Reading mode</Label>
            <Select value={settings.mode} onValueChange={(mode) => onChange({ mode })} disabled={!isLoaded}>
              <SelectTrigger id="reader-mode" aria-label="Reading mode"><SelectValue /></SelectTrigger>
              <SelectContent>
                {READER_MODES.map((mode) => <SelectItem key={mode.value} value={mode.value}>{mode.label}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2">
            <Label htmlFor="reader-direction">Reading direction</Label>
            <Select value={settings.direction} onValueChange={(direction) => onChange({ direction })} disabled={!isLoaded}>
              <SelectTrigger id="reader-direction" aria-label="Reading direction"><SelectValue /></SelectTrigger>
              <SelectContent>
                {READER_DIRECTIONS.map((direction) => <SelectItem key={direction.value} value={direction.value}>{direction.label}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
        </div>
        {modeNotice && <p className="text-xs text-muted-foreground">{modeNotice}</p>}

        <ReaderZoomSlider
          zoomLevel={zoomLevel}
          canZoom={canZoom}
          continuousZoom={continuousZoom}
          isLoaded={isLoaded}
          onZoomChange={onZoomChange}
        />

        <Separator />

        <div className="space-y-2">
          <Label htmlFor="reader-fit">Page size</Label>
          <Select value={settings.fit} onValueChange={(fit) => onChange({ fit })} disabled={!isLoaded || settings.mode === "continuous"}>
            <SelectTrigger id="reader-fit" aria-label="Page size">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {READER_FITS.map((fit) => (
                <SelectItem key={fit.value} value={fit.value}>{fit.label}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          <p className="text-xs text-muted-foreground">
            {settings.mode === "continuous"
              ? "Continuous mode sizes each page to the reading column."
              : "Fit width can scroll vertically. Original size can scroll in either direction."}
          </p>
          <p className="text-xs text-muted-foreground">
            {hasOverride
              ? `Applies to ${contextLabel} only.`
              : "Applies everywhere you read."}
          </p>
        </div>

        {/* A phone held upright and a tablet turned sideways want different page
            sizes. Saying so here is deliberate: rotating the device must never
            rewrite a choice on its own. */}
        <ReaderSettingSwitch
          id="reader-context-override"
          label="Different page size here"
          description={`Keep a separate page size for ${contextLabel}.`}
          checked={Boolean(hasOverride)}
          onCheckedChange={onOverrideChange}
          disabled={!isLoaded || settings.mode === "continuous"}
        />

        <Separator />

        <ReaderSettingToggles settings={settings} isLoaded={isLoaded} onChange={onChange} />

        <Separator />

        <div className="flex items-center justify-between gap-3">
          <span className="text-xs text-muted-foreground" role="status" aria-live="polite">
            {!isLoaded ? "Loading settings…" : isSaving ? "Saving…" : hasSyncError ? "Not synced with your account" : "Saved across devices"}
          </span>
          <Button variant="ghost" size="sm" onClick={onReset} disabled={!isLoaded}>
            <RotateCcw className="mr-2 h-4 w-4" /> Reset defaults
          </Button>
        </div>

        <PrivacyChoicesButton className="text-xs text-muted-foreground underline underline-offset-4 hover:text-foreground" />
      </PopoverContent>
    </Popover>
  );
}
