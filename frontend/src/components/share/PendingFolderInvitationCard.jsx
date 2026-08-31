import { Folder, ShieldAlert } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { EXPLICIT_GATE_CONFIRM_LABEL, EXPLICIT_GATE_TITLE } from "@/lib/sharing";

/** One recipient decision backed by several independently revocable grants. */
export function PendingFolderInvitationCard({ shares, busy, onConfirmAdult, onAccept, onDecline }) {
  const share = shares[0];
  const gated = shares.some((member) => member.requiresAdultConfirmation);
  const disabled = !shares.every((member) => member.canAnswer) || busy;
  const count = shares.length;

  return (
    <Card>
      <CardContent className="flex flex-col gap-4 p-4 sm:flex-row">
        <div className="flex h-16 w-16 flex-none items-center justify-center rounded bg-muted sm:h-24">
          <Folder className="h-8 w-8 text-comic-purple" />
        </div>
        <div className="min-w-0 flex-1">
          <h3 className="truncate font-bold">{share.invitationBatchName || "Shared folder"}</h3>
          <p className="text-sm text-muted-foreground">
            {share.ownerLabel || share.ownerName} wants to share {count} {count === 1 ? "comic" : "comics"} with you.
          </p>
          {gated && (
            <p className="mt-1 flex items-center gap-1 text-sm font-medium text-destructive">
              <ShieldAlert className="h-4 w-4" />
              {EXPLICIT_GATE_TITLE}
            </p>
          )}
          <p className="mt-1 text-sm text-muted-foreground">
            Accept once to add the whole folder snapshot to your collection.
          </p>
        </div>
        <div className="flex w-full flex-none flex-col gap-2 sm:w-auto">
          {gated
            ? <Button size="sm" disabled={disabled} onClick={onConfirmAdult}>{EXPLICIT_GATE_CONFIRM_LABEL}</Button>
            : <Button size="sm" disabled={disabled} onClick={onAccept}>Add all to my collection</Button>}
          <Button size="sm" variant="outline" disabled={disabled} onClick={onDecline}>Decline all</Button>
        </div>
      </CardContent>
    </Card>
  );
}
