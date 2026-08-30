import { useState } from "react";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { recipientLabel } from "@/lib/sharing";

/**
 * Every transition a share can be put through from the sharing page.
 *
 * `busyShareId` names the share being acted on, not the action being taken.
 * Accept and Decline are alternatives, as are Resend and Revoke, so keying it
 * by action would leave the opposite button live and let two conflicting
 * transitions be requested for one share at once.
 *
 * Every action changes what the collection contains, so the library is
 * reloaded alongside the sharing lists. Without that, accepting an invitation
 * would leave the dashboard showing a collection that predates it.
 */
export function useSharingActions({ reload, loadLibrary }) {
  const { toast } = useToast();
  const [busyShareId, setBusyShareId] = useState(null);

  const runAction = async (shareId, action, successMessage) => {
    setBusyShareId(shareId);
    try {
      await action();
      await reload();
      await loadLibrary();
      if (successMessage) toast({ title: successMessage });
    } catch (err) {
      logger.error("Sharing action failed:", err);
      toast({
        title: "Something went wrong",
        description: err.message || "The action could not be completed.",
        variant: "destructive",
      });
    } finally {
      setBusyShareId(null);
    }
  };

  const post = (share, path, message) => runAction(share.id, () => api.post(`/api/shares/${share.id}/${path}`, {}), message);

  return {
    busyShareId,
    runAction,

    accept: (share) => post(
      share,
      "accept",
      share.invitationBatchId
        ? `${share.invitationBatchSize || "Folder"} comics added to your collection.`
        : "Comic added to your collection."
    ),
    decline: (share) => post(share, "decline", share.invitationBatchId ? "Folder invitation declined." : "Invitation declined."),
    remove: (share) => post(share, "remove", "Removed from your collection."),
    restore: (share) => post(share, "restore", "Restored to your collection."),
    resend: (recipient) => post(recipient, "resend", `Invitation resent to ${recipientLabel(recipient)}.`),
    revoke: (recipient) => post(recipient, "revoke", `Access revoked for ${recipientLabel(recipient)}.`),

    /**
     * The same endpoint the invitation page uses, because it is the same
     * declaration: the gate follows the share, not the screen it is met on.
     */
    confirmAdult: (share) => runAction(
      share.id,
      () => api.post(`/api/shares/${share.id}/confirm-adult`, { adultConfirmed: true }),
      "Age confirmed."
    ),

    forget: (share) => runAction(
      share.id,
      () => api.delete("/api/shares/tombstones", { body: { shareIds: [share.id] } }),
      "Entry removed."
    ),

    // The two below act on a comic or on the whole history rather than on one
    // share, so they pass no id and the row spinners stay put.
    removeAllDead: () => runAction(null, () => api.delete("/api/shares/tombstones"), "Unavailable shared comics removed."),
    stopSharing: (comicId) => runAction(null, () => api.delete(`/api/shares/comics/${comicId}`), "Sharing stopped."),
  };
}
