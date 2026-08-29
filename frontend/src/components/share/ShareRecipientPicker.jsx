import { ShareCodeOptions } from "@/components/share/ShareCodeOptions";
import { ShareDirectRecipient } from "@/components/share/ShareDirectRecipient";
import { Button } from "@/components/ui/button";
import { MODES } from "@/lib/sharing-workflow";

/**
 * Step two: who the comics are for.
 *
 * Two shapes of answer, deliberately kept in one step. Naming somebody is a
 * different act from making a code anyone can redeem, but both answer the same
 * question — where is this going — and splitting them into separate dialogs
 * would make the choice look like a setting rather than a fork in one task.
 */
export function ShareRecipientPicker({ mode, setMode, isSending, issuedCode, ...rest }) {
  return (
    <section className="space-y-3">
      <h3 className="font-semibold">2. Share with</h3>

      <div className="flex flex-wrap gap-1 rounded-md border p-1" role="tablist">
        {[
          [MODES.DIRECT, "Someone I know"],
          [MODES.CODE, "Create a code"],
        ].map(([value, label]) => (
          <Button
            key={value}
            type="button"
            role="tab"
            size="sm"
            variant={mode === value ? "default" : "ghost"}
            aria-selected={mode === value}
            disabled={isSending || issuedCode !== null}
            onClick={() => setMode(value)}
          >
            {label}
          </Button>
        ))}
      </div>

      {mode === MODES.DIRECT && <ShareDirectRecipient {...rest} isSending={isSending} />}
      {mode === MODES.CODE && (
        <ShareCodeOptions {...rest} isSending={isSending} issuedCode={issuedCode} />
      )}
    </section>
  );
}
