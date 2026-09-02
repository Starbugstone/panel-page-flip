import { BookOpen, Loader2, Trash2, Undo2 } from "lucide-react";
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
      <ShareCardShell
        media={<ShareCover src={share.coverImagePath} title={title} gated={gated} />}
        title={title}
        actions={showActions && (
          <>
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
          </>
        )}
      >
        <p className="truncate text-sm text-muted-foreground">
          Shared by {share.ownerLabel || share.ownerName}
          {share.comicAuthor ? ` · ${share.comicAuthor}` : ""}
        </p>
        {gated && <ExplicitContentNotice />}
        <p className="mt-1 text-sm text-muted-foreground">{describeReceivedShare(share)}</p>
      </ShareCardShell>
    </Card>
  );
}
