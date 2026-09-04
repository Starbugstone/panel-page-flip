import { useState } from "react";
import { Loader2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { describeBulkOutcome, runBulkAction } from "@/lib/bulk-actions";

/** Mirrors UserWarning::MAX_MESSAGE_LENGTH. The server checks again. */
const MAX_WARNING_LENGTH = 2000;

/**
 * One dialog for every place a warning is issued from, one row or many.
 *
 * The user, comic and share tables each name their target differently, so the
 * caller supplies a target — exactly one of `userId`, `comicId` or `shareId` —
 * and the server works out who is actually being written to. Naming a comic
 * warns whoever owns it; naming a share warns whoever made it.
 *
 * A bulk warning is the same message written once and sent to each recipient in
 * turn, through the same endpoint a single warning uses. Recipients who refuse
 * it — warning yourself, a comic deleted since the page loaded — are reported
 * without holding up the rest.
 *
 * @param {object} props
 * @param {{userId?: number, comicId?: number, shareId?: number}|null} [props.target]
 *        The one thing being warned about; null closes the dialog.
 * @param {Array<{target: object, label?: string}>} [props.targets] Several of them.
 *        The label names a recipient in the failure summary and is not sent.
 * @param {string} [props.subjectLabel] What to name in the heading.
 * @param {string} [props.recipientLabel] Who will receive it.
 * @param {() => void} props.onClose
 * @param {() => void} [props.onSent]
 */
export function AdminWarnDialog({ target, targets, subjectLabel, recipientLabel, onClose, onSent }) {
  const { toast } = useToast();
  // Keyed by the target rather than cleared by an effect, so a message typed
  // for one account cannot survive into the next dialog opened — not even for
  // the render between the target changing and the effect running.
  const [draft, setDraft] = useState({ forTarget: null, message: "", sendEmail: false });
  const [isSending, setIsSending] = useState(false);
  const [progress, setProgress] = useState(null);

  const recipients = targets ?? (target ? [{ target }] : []);
  const targetKey = recipients.length > 0 ? JSON.stringify(recipients) : null;
  const current = draft.forTarget === targetKey ? draft : { message: "", sendEmail: false };
  const { message, sendEmail } = current;
  const update = (patch) => setDraft({ forTarget: targetKey, ...current, ...patch });

  const trimmed = message.trim();
  const tooLong = trimmed.length > MAX_WARNING_LENGTH;

  const send = async () => {
    setIsSending(true);

    const outcome = await runBulkAction(
      recipients,
      (recipient) => api.post("/api/admin/warnings", { ...recipient.target, message: trimmed, sendEmail }),
      (done, total) => setProgress({ done, total })
    );

    setIsSending(false);
    setProgress(null);
    for (const failure of outcome.failed) {
      logger.error("Sending a warning failed:", failure.message);
    }
    toast(summarise(outcome, recipients.length));

    // Nothing landed, so the message is still worth having: the dialog stays
    // open with it intact rather than making somebody type it again. Once any
    // warning has gone the dialog closes, because reopening it would resend to
    // the recipients who already have it.
    if (outcome.succeeded.length === 0) return;
    onSent?.();
    onClose();
  };

  return (
    <Dialog open={recipients.length > 0} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{subjectLabel ? `Warn about ${subjectLabel}` : "Send a warning"}</DialogTitle>
          <DialogDescription>
            {recipientLabel
              ? `${recipientLabel} will see this the next time they sign in, and can dismiss it. `
              : "They will see this the next time they sign in, and can dismiss it. "}
            It does not by itself restrict what they can read, upload or share.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="warning-message">Message</Label>
            <Textarea
              id="warning-message"
              value={message}
              onChange={(event) => update({ message: event.target.value })}
              rows={6}
              autoFocus
              disabled={isSending}
              placeholder="Explain what needs to change, and what happens if it does not."
            />
            <p className={`text-xs ${tooLong ? "text-destructive" : "text-muted-foreground"}`}>
              {trimmed.length} / {MAX_WARNING_LENGTH} characters
            </p>
          </div>

          <div className="flex items-start gap-2">
            <Checkbox
              id="warning-send-email"
              checked={sendEmail}
              onCheckedChange={(checked) => update({ sendEmail: checked === true })}
              disabled={isSending}
            />
            <div className="space-y-1">
              <Label htmlFor="warning-send-email" className="font-normal">
                Also email them a copy
              </Label>
              <p className="text-xs text-muted-foreground">
                The same message, sent to the address on their account. The notice waits for them
                here either way.
              </p>
            </div>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={isSending}>Cancel</Button>
          <Button onClick={send} disabled={isSending || trimmed === "" || tooLong}>
            {isSending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            {progress && progress.total > 1
              ? `Sending… ${progress.done} of ${progress.total}`
              : recipients.length > 1 ? `Send ${recipients.length} warnings` : "Send warning"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * A single warning keeps its own wording: "Warning sent" and the server's own
 * confirmation say more than a count of one would.
 */
function summarise(outcome, total) {
  if (total === 1) {
    const [failure] = outcome.failed;
    return failure
      ? {
        title: "Could not send the warning",
        description: failure.message || "Please try again.",
        variant: "destructive",
      }
      : { title: "Warning sent", description: outcome.succeeded[0].result?.message };
  }

  return describeBulkOutcome(outcome, {
    noun: "warning",
    verbPast: "sent",
    labelOf: (recipient) => recipient.label,
  });
}
