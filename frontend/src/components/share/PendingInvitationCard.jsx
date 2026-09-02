import { ShieldAlert } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ShareCover } from "@/components/share/ShareCover";
import {
  EXPLICIT_GATE_CONFIRM_LABEL,
  EXPLICIT_GATE_TITLE,
  describeReceivedShare,
  requiresAdultConfirmation,
  shareDisplayTitle,
} from "@/lib/sharing";

/**
 * An invitation waiting to be answered.
 *
 * The emailed link is not the only way in: somebody signed in and looking at
 * their own invitation has already identified themselves. That still is not an
 * age declaration, so an explicit invitation offers the gate here instead of
 * Accept.
 */
export function PendingInvitationCard({ share, busy, onConfirmAdult, onAccept, onDecline }) {
  const title = shareDisplayTitle(share);
  const gated = requiresAdultConfirmation(share);
  const disabled = !share.canAnswer || busy;

  return (
    <Card>
      <CardContent className="flex flex-col gap-4 p-4 sm:flex-row">
        <div className="flex min-w-0 flex-1 gap-4">
          <ShareCover src={share.coverImagePath} title={title} gated={gated} />
          <div className="min-w-0 flex-1">
            <h3 className="truncate font-bold">{title}</h3>
            <p className="truncate text-sm text-muted-foreground">
              {share.ownerLabel || share.ownerName} wants to share this with you.
            </p>
            {gated && (
              <p className="mt-1 flex items-center gap-1 text-sm font-medium text-destructive">
                <ShieldAlert className="h-4 w-4" />
                {EXPLICIT_GATE_TITLE}
              </p>
            )}
            <p className="mt-1 text-sm text-muted-foreground">{describeReceivedShare(share)}</p>
          </div>
        </div>
        <div className="flex w-full flex-none flex-col gap-2 sm:w-auto">
          {gated
            ? <Button size="sm" disabled={disabled} onClick={onConfirmAdult}>{EXPLICIT_GATE_CONFIRM_LABEL}</Button>
            : <Button size="sm" disabled={disabled} onClick={onAccept}>Add to my collection</Button>}
          <Button size="sm" variant="outline" disabled={disabled} onClick={onDecline}>Decline</Button>
        </div>
      </CardContent>
    </Card>
  );
}
