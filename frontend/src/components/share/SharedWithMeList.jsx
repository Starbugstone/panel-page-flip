import { Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { PendingInvitationCard } from "@/components/share/PendingInvitationCard";
import { PendingFolderInvitationCard } from "@/components/share/PendingFolderInvitationCard";
import { ReceivedShareCard } from "@/components/share/ReceivedShareCard";

/**
 * Everything shared with the signed-in reader, in three sections that ask for
 * three different things: an answer, a read, or a tidy-up.
 */
export function SharedWithMeList({ sharedWithMe, groups, actions, onRead, onCleanupDead }) {
  const { invitations, collection, dead } = groups;
  const pendingDecisions = [];
  const folderBatches = new Map();
  invitations.forEach((share) => {
    if (!share.invitationBatchId) {
      pendingDecisions.push({ key: `share-${share.id}`, shares: [share] });
      return;
    }
    if (!folderBatches.has(share.invitationBatchId)) {
      const batch = { key: `batch-${share.invitationBatchId}`, shares: [] };
      folderBatches.set(share.invitationBatchId, batch);
      pendingDecisions.push(batch);
    }
    folderBatches.get(share.invitationBatchId).shares.push(share);
  });

  if (sharedWithMe.length === 0) {
    return <p className="py-12 text-center text-muted-foreground">Nobody has shared a comic with you yet.</p>;
  }

  const received = (shares) => (
    <ul className="space-y-3">
      {shares.map((share) => (
        <li key={share.id}>
          <ReceivedShareCard
            share={share}
            busy={actions.busyShareId === share.id}
            showActions
            onConfirmAdult={() => actions.confirmAdult(share)}
            onRead={() => onRead(share)}
            onRemove={() => actions.remove(share)}
            onRestore={() => actions.restore(share)}
            onForget={() => actions.forget(share)}
          />
        </li>
      ))}
    </ul>
  );

  return (
    <div className="space-y-8">
      {invitations.length > 0 && (
        <section>
          <h2 className="mb-3 text-lg font-semibold">Pending invitations</h2>
          <ul className="space-y-3">
            {pendingDecisions.map(({ key, shares }) => {
              const share = shares[0];
              const props = {
                busy: actions.busyShareId === share.id,
                onConfirmAdult: () => actions.confirmAdult(share),
                onAccept: () => actions.accept(share),
                onDecline: () => actions.decline(share),
              };
              return (
              <li key={key}>
                {share.invitationBatchId
                  ? <PendingFolderInvitationCard shares={shares} {...props} />
                  : <PendingInvitationCard share={share} {...props} />}
              </li>
              );
            })}
          </ul>
        </section>
      )}

      {collection.length > 0 && (
        <section>
          <h2 className="mb-3 text-lg font-semibold">Shared comics</h2>
          {received(collection)}
        </section>
      )}

      {dead.length > 0 && (
        <section>
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-lg font-semibold">No longer available</h2>
            <Button variant="outline" size="sm" onClick={onCleanupDead}>
              <Trash2 className="mr-2 h-4 w-4" />
              Remove all dead shares ({dead.length})
            </Button>
          </div>
          {received(dead)}
        </section>
      )}
    </div>
  );
}
