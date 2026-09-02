import { UserPlus } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ShareCover } from "@/components/share/ShareCover";
import { ShareRecipientRow } from "@/components/share/ShareRecipientRow";
import { EXPLICIT_FLAG_LABEL, recipientLabel, recipientTarget, summariseRecipients } from "@/lib/sharing";

const EMPTY_TARGET = { email: "", username: "", userCode: "" };

/** One of the owner's comics, and everybody it has been offered to. */
export function SharedComicGroup({ group, busyShareId, onShare, onStopSharing, onResend, onRevoke, onDelete }) {
  const counts = summariseRecipients(group.recipients);

  return (
    <Card>
      <CardContent className="space-y-4 p-4">
        <div className="flex flex-col gap-4 sm:flex-row">
          <div className="flex min-w-0 flex-1 gap-4">
            <ShareCover src={group.coverImagePath} title={group.title} />
            <div className="min-w-0 flex-1">
              <h3 className="truncate font-bold">{group.title}</h3>
              <p className="truncate text-sm text-muted-foreground">{group.author}</p>
              {group.explicitContent && <Badge variant="outline" className="mt-1">{EXPLICIT_FLAG_LABEL}</Badge>}
              <p className="mt-1 text-sm text-muted-foreground">
                {counts.total} {counts.total === 1 ? "recipient" : "recipients"}
                {" · "}
                {counts.accepted} accepted, {counts.pending} pending, {counts.declined} declined,{" "}
                {counts.revoked} revoked
              </p>
            </div>
          </div>
          <div className="flex w-full flex-none flex-col gap-2 sm:w-auto">
            <Button size="sm" variant="outline" onClick={() => onShare({ ...EMPTY_TARGET, comicIds: [group.comicId] })}>
              <UserPlus className="mr-2 h-4 w-4" />
              Share this comic
            </Button>
            {counts.accepted + counts.pending > 0 && (
              <Button size="sm" variant="ghost" onClick={() => onStopSharing(group)}>Stop sharing</Button>
            )}
          </div>
        </div>

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
