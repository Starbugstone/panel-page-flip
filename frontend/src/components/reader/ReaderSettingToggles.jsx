import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";

export function ReaderSettingSwitch({ id, label, description, checked, onCheckedChange, disabled }) {
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

/**
 * The reader's on/off preferences, in one group.
 *
 * Every control stays inert until the saved values have arrived. A change made
 * against the placeholder defaults would be sent as the user's whole preference
 * set and overwrite what is on the server.
 */
export function ReaderSettingToggles({ settings, isLoaded, onChange }) {
  return (
    <div className="space-y-4">
      <ReaderSettingSwitch
        id="reader-cover-alone"
        label="Show first page alone"
        description="Keep a cover separate when using two-page mode."
        checked={settings.coverAlone}
        onCheckedChange={(coverAlone) => onChange({ coverAlone })}
        disabled={!isLoaded || settings.mode !== "double"}
      />
      <ReaderSettingSwitch
        id="reader-show-progress"
        label="Show progress bar"
        description="Keep a slim page progress indicator above navigation."
        checked={settings.showProgress}
        onCheckedChange={(showProgress) => onChange({ showProgress })}
        disabled={!isLoaded}
      />
      <ReaderSettingSwitch
        id="reader-auto-hide"
        label="Auto-hide reader controls"
        description="Controls return on a centre tap, pointer movement, or keyboard use."
        checked={settings.autoHideControls}
        onCheckedChange={(autoHideControls) => onChange({ autoHideControls })}
        disabled={!isLoaded}
      />
      <ReaderSettingSwitch
        id="reader-wake-lock"
        label="Keep screen awake"
        description="Prevent screen sleep while the reader is open, when supported."
        checked={settings.wakeLock}
        onCheckedChange={(wakeLock) => onChange({ wakeLock })}
        disabled={!isLoaded}
      />
    </div>
  );
}
