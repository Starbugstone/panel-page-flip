import { Loader2, RotateCcw, UserPlus, XCircle } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { SHARE_STATUS, SHARE_STATUS_LABELS, describeNotification, recipientLabel } from "@/lib/sharing";

const STATUS_VARIANTS = {
  [SHARE_STATUS.ACCEPTED]: "default",
  [SHARE_STATUS.PENDING]: "secondary",
  [SHARE_STATUS.DECLINED]: "outline",
  [SHARE_STATUS.REVOKED]: "outline",
};

/**
 * One person a comic was shared with, and what can still be done about it.
 *
 * Every share listed here belongs to a comic the owner still has — a deleted
 * one leaves the list entirely — so the only state worth calling out is a
 * lapsed invitation. Somebody reached by their sharing code is named rather
 * than addressed: the point of the code was that the sender never learned the
 * address.
 */
export function ShareRecipientRow({ recipient, busy, onShareAnother, onResend, onRevoke }) {
  const label = recipientLabel(recipient);
  const notification = describeNotification(recipient);

  return (
    <li className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
      <div className="min-w-0">
        <span className="block truncate text-sm">{label}</span>
        {recipient.recipientUserCode && (
          <span className="block text-xs text-muted-foreground">User code {recipient.recipientUserCode}</span>
        )}
        {recipient.status === SHARE_STATUS.PENDING && recipient.isExpired && (
          <span className="block text-xs text-muted-foreground">Invitation expired</span>
        )}
        {/* The share is real whatever this says; it is here so an owner whose
            mail server was having a bad afternoon knows why nobody answered. */}
        {notification && <span className="block text-xs text-destructive">{notification}</span>}
      </div>
      <div className="flex flex-wrap items-center justify-end gap-2">
        <Badge variant={STATUS_VARIANTS[recipient.status] || "outline"}>
          {SHARE_STATUS_LABELS[recipient.status] || recipient.status}
        </Badge>
        <Button
          size="sm"
          variant="ghost"
          aria-label={`Share another comic with ${label}`}
          onClick={onShareAnother}
        >
          <UserPlus className="h-4 w-4" />
          <span className="ml-2 hidden md:inline">Share another comic</span>
        </Button>
        {recipient.canResend && (
          <Button size="sm" variant="ghost" disabled={busy} onClick={onResend}>
            {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <RotateCcw className="h-4 w-4" />}
            <span className="ml-2 hidden sm:inline">Resend</span>
          </Button>
        )}
        {recipient.canRevoke && (
          <Button
            size="sm"
            variant="ghost"
            disabled={busy}
            aria-label={`Revoke access for ${label}`}
            onClick={onRevoke}
          >
            <XCircle className="h-4 w-4" />
            <span className="ml-2 hidden sm:inline">Revoke</span>
          </Button>
        )}
      </div>
    </li>
  );
}
