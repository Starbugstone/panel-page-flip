import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { ExplicitContentNotice } from "@/components/share/ExplicitContentNotice";
import { ShareCardShell } from "@/components/share/ShareCardShell";
import { ShareCover } from "@/components/share/ShareCover";
import {
  EXPLICIT_GATE_CONFIRM_LABEL,
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
      <ShareCardShell
        media={<ShareCover src={share.coverImagePath} title={title} gated={gated} />}
        title={title}
        actions={(
          <>
            {gated
              ? <Button size="sm" disabled={disabled} onClick={onConfirmAdult}>{EXPLICIT_GATE_CONFIRM_LABEL}</Button>
              : <Button size="sm" disabled={disabled} onClick={onAccept}>Add to my collection</Button>}
            <Button size="sm" variant="outline" disabled={disabled} onClick={onDecline}>Decline</Button>
          </>
        )}
      >
        <p className="truncate text-sm text-muted-foreground">
          {share.ownerLabel || share.ownerName} wants to share this with you.
        </p>
        {gated && <ExplicitContentNotice />}
        <p className="mt-1 text-sm text-muted-foreground">{describeReceivedShare(share)}</p>
      </ShareCardShell>
    </Card>
  );
}
