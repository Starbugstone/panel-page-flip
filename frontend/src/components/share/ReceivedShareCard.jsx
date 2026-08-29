import { BookOpen, Loader2, ShieldAlert, Trash2, Undo2 } from "lucide-react";
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
 * A comic somebody else shared, in the recipient's own list.
 *
 * Which buttons appear is the server's decision, carried on the share as
 * `canRead`, `canRemove` and `canRestore`. A tombstone offers no way to read
 * anything — there is nothing left behind it — only a way to clear the entry.
 */
export function ReceivedShareCard({ share, busy, onConfirmAdult, onRead, onRemove, onRestore, onForget, showActions }) {
  const title = shareDisplayTitle(share);
  const gated = requiresAdultConfirmation(share);

  return (
    <Card>
      <CardContent className="flex gap-4 p-4">
        <ShareCover src={share.coverImagePath} title={title} gated={gated} />
        <div className="min-w-0 flex-1">
          <h3 className="truncate font-bold">{title}</h3>
          <p className="truncate text-sm text-muted-foreground">
            Shared by {share.ownerLabel || share.ownerName}
            {share.comicAuthor ? ` · ${share.comicAuthor}` : ""}
          </p>
          {gated && (
            <p className="mt-1 flex items-center gap-1 text-sm font-medium text-destructive">
              <ShieldAlert className="h-4 w-4" />
              {EXPLICIT_GATE_TITLE}
            </p>
          )}
          <p className="mt-1 text-sm text-muted-foreground">{describeReceivedShare(share)}</p>
        </div>
        {showActions && (
          <div className="flex flex-none flex-col gap-2">
            {gated && !share.isDead && (
              <Button size="sm" disabled={busy} onClick={onConfirmAdult}>
                {busy && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                {EXPLICIT_GATE_CONFIRM_LABEL}
              </Button>
            )}
            {share.canRead && (
              <Button size="sm" onClick={onRead}>
                <BookOpen className="mr-2 h-4 w-4" />
                Read
              </Button>
            )}
            {share.canRemove && (
              <Button size="sm" variant="outline" disabled={busy} onClick={onRemove}>
                Remove from my collection
              </Button>
            )}
            {share.canRestore && (
              <Button size="sm" variant="outline" disabled={busy} onClick={onRestore}>
                <Undo2 className="mr-2 h-4 w-4" />
                Restore
              </Button>
            )}
            {share.isDead && (
              <Button size="sm" variant="ghost" disabled={busy} onClick={onForget}>
                <Trash2 className="mr-2 h-4 w-4" />
                Remove
              </Button>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
