import { Folder } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { ExplicitContentNotice } from "@/components/share/ExplicitContentNotice";
import { ShareCardShell } from "@/components/share/ShareCardShell";
import { EXPLICIT_GATE_CONFIRM_LABEL } from "@/lib/sharing";

/** One recipient decision backed by several independently revocable grants. */
export function PendingFolderInvitationCard({ shares, busy, onConfirmAdult, onAccept, onDecline }) {
  const share = shares[0];
  const gated = shares.some((member) => member.requiresAdultConfirmation);
  const disabled = !shares.every((member) => member.canAnswer) || busy;
  const count = shares.length;

  return (
    <Card>
      <ShareCardShell
        media={(
          <div className="flex h-16 w-16 flex-none items-center justify-center rounded bg-muted sm:h-24">
            <Folder className="h-8 w-8 text-comic-purple" />
          </div>
        )}
        title={share.invitationBatchName || "Shared folder"}
        actions={(
          <>
            {gated
              ? <Button size="sm" disabled={disabled} onClick={onConfirmAdult}>{EXPLICIT_GATE_CONFIRM_LABEL}</Button>
              : <Button size="sm" disabled={disabled} onClick={onAccept}>Add all to my collection</Button>}
            <Button size="sm" variant="outline" disabled={disabled} onClick={onDecline}>Decline all</Button>
          </>
        )}
      >
        <p className="text-sm text-muted-foreground">
          {share.ownerLabel || share.ownerName} wants to share {count} {count === 1 ? "comic" : "comics"} with you.
        </p>
        {gated && <ExplicitContentNotice />}
        <p className="mt-1 text-sm text-muted-foreground">
          Accept once to add the whole folder snapshot to your collection.
        </p>
      </ShareCardShell>
    </Card>
  );
}
