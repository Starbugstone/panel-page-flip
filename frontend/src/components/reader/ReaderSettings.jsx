import { RotateCcw, Settings2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Separator } from "@/components/ui/separator";
import { Switch } from "@/components/ui/switch";
import { READER_FITS } from "@/lib/reader-preferences";

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

export function ReaderSettings({ settings, isLoaded, isSaving, onChange, onReset }) {
  return (
    <Popover>
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
          <p className="mt-1 text-xs text-muted-foreground">
            Single-page mode · saved to your account
          </p>
        </div>

        <div className="space-y-2">
          <Label htmlFor="reader-fit">Page size</Label>
          <Select value={settings.fit} onValueChange={(fit) => onChange({ fit })} disabled={!isLoaded}>
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
            Fit width can scroll vertically. Original size can scroll in either direction.
          </p>
        </div>

        <Separator />

        {/* Every control stays inert until the saved values have arrived. A
            change made against the placeholder defaults would be sent as the
            user's whole preference set and overwrite what is on the server. */}
        <div className="space-y-4">
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
            label="Fade fullscreen controls"
            description="Controls return on hover or keyboard focus."
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
