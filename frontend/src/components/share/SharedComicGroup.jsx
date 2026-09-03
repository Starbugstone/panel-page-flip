import { UserPlus } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ShareCardShell } from "@/components/share/ShareCardShell";
import { ShareCover } from "@/components/share/ShareCover";
import { ShareRecipientRow } from "@/components/share/ShareRecipientRow";
import { EXPLICIT_FLAG_LABEL, recipientLabel, recipientTarget, summariseRecipients } from "@/lib/sharing";

const EMPTY_TARGET = { email: "", username: "", userCode: "" };

/** One of the owner's comics, and everybody it has been offered to. */
export function SharedComicGroup({ group, busyShareId, onShare, onStopSharing, onResend, onRevoke, onDelete }) {
  const counts = summariseRecipients(group.recipients);

  return (
    <Card>
      <ShareCardShell
        media={<ShareCover src={group.coverImagePath} title={group.title} />}
        title={group.title}
        actions={(
          <>
            <Button size="sm" variant="outline" onClick={() => onShare({ ...EMPTY_TARGET, comicIds: [group.comicId] })}>
              <UserPlus className="mr-2 h-4 w-4" />
              Share this comic
            </Button>
            {counts.accepted + counts.pending > 0 && (
              <Button size="sm" variant="ghost" onClick={() => onStopSharing(group)}>Stop sharing</Button>
            )}
          </>
        )}
      >
        <p className="truncate text-sm text-muted-foreground">{group.author}</p>
        {group.explicitContent && <Badge variant="outline" className="mt-1">{EXPLICIT_FLAG_LABEL}</Badge>}
        <p className="mt-1 text-sm text-muted-foreground">
          {counts.total} {counts.total === 1 ? "recipient" : "recipients"}
          {" · "}
          {counts.accepted} accepted, {counts.pending} pending, {counts.declined} declined,{" "}
          {counts.revoked} revoked
        </p>
      </ShareCardShell>

      <CardContent className="px-4 pb-4">
        <ul className="divide-y rounded border">
          {group.recipients.map((recipient) => (
            <ShareRecipientRow
              key={recipient.id}
              recipient={recipient}
              busy={busyShareId === recipient.id}
              onShareAnother={() => onShare({
                ...recipientTarget(recipient),
                // Already confirmed: this button names them, and the name came
                // from a share this owner already has. Nothing for a Check to
                // find out.
                resolved: recipient.recipientUsername
                  ? {
                    username: recipient.recipientUsername,
                    name: recipient.recipientName || "",
                    label: recipientLabel(recipient),
                  }
                  : null,
                comicIds: [],
              })}
              onResend={() => onResend(recipient)}
              onRevoke={() => onRevoke(recipient)}
              onDelete={() => onDelete(recipient)}
            />
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
