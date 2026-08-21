import { RotateCcw, Settings2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Separator } from "@/components/ui/separator";
import { Switch } from "@/components/ui/switch";
import { READER_DIRECTIONS, READER_FITS, READER_MODES } from "@/lib/reader-preferences";

function SettingSwitch({ id, label, description, checked, onCheckedChange, disabled }) {
  return (
    <div className="flex items-start justify-between gap-4">
      <div className="space-y-0.5">
        <Label htmlFor={id} className={disabled ? "" : "cursor-pointer"}>{label}</Label>
        <p className="text-xs text-muted-foreground">{description}</p>
      </div>
      <Switch id={id} checked={checked} onCheckedChange={onCheckedChange} aria-label={label} disabled={disabled} />
    </div>
  );
}

export function ReaderSettings({
  settings,
  isLoaded,
  isSaving,
  contextLabel,
  hasOverride,
  modeNotice,
  onChange,
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
          <p className="mt-1 text-xs text-muted-foreground">Saved to your account</p>
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
        <SettingSwitch
          id="reader-context-override"
          label="Different page size here"
          description={`Keep a separate page size for ${contextLabel}.`}
          checked={Boolean(hasOverride)}
          onCheckedChange={onOverrideChange}
          disabled={!isLoaded || settings.mode === "continuous"}
        />

        <Separator />

        {/* Every control stays inert until the saved values have arrived. A
            change made against the placeholder defaults would be sent as the
            user's whole preference set and overwrite what is on the server. */}
        <div className="space-y-4">
          <SettingSwitch
            id="reader-cover-alone"
            label="Show first page alone"
            description="Keep a cover separate when using two-page mode."
            checked={settings.coverAlone}
            onCheckedChange={(coverAlone) => onChange({ coverAlone })}
            disabled={!isLoaded || settings.mode !== "double"}
          />
          <SettingSwitch
            id="reader-show-progress"
            label="Show progress bar"
            description="Keep a slim page progress indicator above navigation."
            checked={settings.showProgress}
            onCheckedChange={(showProgress) => onChange({ showProgress })}
            disabled={!isLoaded}
          />
          <SettingSwitch
            id="reader-auto-hide"
            label="Auto-hide reader controls"
            description="Controls return on a centre tap, pointer movement, or keyboard use."
            checked={settings.autoHideControls}
            onCheckedChange={(autoHideControls) => onChange({ autoHideControls })}
            disabled={!isLoaded}
          />
          <SettingSwitch
            id="reader-wake-lock"
            label="Keep screen awake"
            description="Prevent screen sleep while the reader is open, when supported."
            checked={settings.wakeLock}
            onCheckedChange={(wakeLock) => onChange({ wakeLock })}
            disabled={!isLoaded}
          />
        </div>

        <Separator />

        <div className="flex items-center justify-between gap-3">
          <span className="text-xs text-muted-foreground" role="status" aria-live="polite">
            {!isLoaded ? "Loading settings…" : isSaving ? "Saving…" : "Saved across devices"}
          </span>
          <Button variant="ghost" size="sm" onClick={onReset} disabled={!isLoaded}>
            <RotateCcw className="mr-2 h-4 w-4" /> Reset defaults
          </Button>
        </div>
      </PopoverContent>
    </Popover>
  );
}
