import { SHARE_STATUS } from "@/lib/sharing";

// Mirrors SharingWorkflowService::MAX_BULK_COMICS and ShareClaimCode's group
// ceiling, which are the same number. The backend remains the authority; this
// only stops the UI inviting somebody to select work the request will reject.
export const MAX_BULK_COMICS = 20;

// SharingWorkflowService::MAX_FOLDER_COMICS. Only reachable by pointing at a
// folder, because that ceiling is only offered to a request the server resolves
// itself — a hand-assembled list of this many ids is still refused.
export const MAX_FOLDER_COMICS = 200;
export const MIN_GROUP_COMICS = 2;
export const MAX_CODE_USES = 10;

/**
 * The two things a share can be.
 *
 * **Direct** names a person — by username, by `U-` code, by address, or by
 * picking somebody the owner has shared with before. **Code** names nobody and
 * produces a `C-` or a `G-` depending on how many comics are selected, because
 * that is the whole difference between the two prefixes.
 */
export const MODES = {
  DIRECT: "direct",
  CODE: "code",
};

/** How the recipient of a direct share is being named. */
export const TARGETS = {
  USERNAME: "username",
  CODE: "code",
  EMAIL: "email",
};

const normaliseEmail = (value) => (typeof value === "string" ? value.trim().toLowerCase() : "");

/**
 * Comics this address already has a live share for.
 *
 * "Live" is accepted, or pending and not yet expired: an expired invitation is
 * not something to stop somebody re-offering.
 */
export function liveComicIdsForRecipient(sharedByMe, email) {
  const wanted = normaliseEmail(email);
  const ids = new Set();
  if (!wanted) return ids;

  (sharedByMe || []).forEach((group) => {
    const alreadyLive = (group.recipients || []).some((recipient) => {
      if (normaliseEmail(recipient.recipientEmail) !== wanted) return false;
      if (recipient.status === SHARE_STATUS.ACCEPTED) return true;
      return recipient.status === SHARE_STATUS.PENDING && !recipient.isExpired;
    });

    if (alreadyLive) ids.add(String(group.comicId));
  });

  return ids;
}
