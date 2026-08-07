/**
 * Pure helpers behind the sharing UI.
 *
 * Kept free of React and of `api` so the wording and the classification rules —
 * the parts that are easy to get subtly wrong — can be tested directly.
 */

export const SHARE_STATUS = {
  PENDING: "pending",
  ACCEPTED: "accepted",
  DECLINED: "declined",
  REVOKED: "revoked",
};

/** Human labels for the states an owner sees against each recipient. */
export const SHARE_STATUS_LABELS = {
  [SHARE_STATUS.PENDING]: "Pending",
  [SHARE_STATUS.ACCEPTED]: "Accepted",
  [SHARE_STATUS.DECLINED]: "Declined",
  [SHARE_STATUS.REVOKED]: "Revoked",
};

/**
 * Why a shared comic stopped being available, in the recipient's words.
 *
 * The recipient never chose to lose it, so each of these explains what happened
 * rather than naming a state.
 */
const TOMBSTONE_REASONS = {
  owner_deleted: "the owner removed it",
  owner_account_deleted: "the owner deleted their account",
  file_missing: "its file is missing",
  administratively_removed: "an administrator removed it",
};

export function tombstoneExplanation(reason) {
  const cause = TOMBSTONE_REASONS[reason] || "it is no longer available";

  return `This comic is no longer available because ${cause}.`;
}

/**
 * The single sentence that describes a "Shared with me" entry.
 *
 * Expiry is derived from `expiresAt` rather than stored as a status, so an
 * invitation that has run out is still `pending` on the wire and has to be
 * recognised here.
 */
export function describeReceivedShare(share) {
  if (share.isTombstoned) {
    return tombstoneExplanation(share.tombstoneReason);
  }

  switch (share.status) {
    case SHARE_STATUS.REVOKED:
      return "The owner has stopped sharing this comic with you.";
    case SHARE_STATUS.DECLINED:
      return "You declined this invitation.";
    case SHARE_STATUS.PENDING:
      return share.isExpired
        ? "This invitation expired before you answered it."
        : "Waiting for you to accept or decline.";
    case SHARE_STATUS.ACCEPTED:
      return share.removedFromCollection
        ? "Removed from your collection. You can restore it while the owner keeps sharing it."
        : "In your collection.";
    default:
      return "";
  }
}

/**
 * Split "Shared with me" into the three groups the page renders.
 *
 * A dead entry is one nothing can be done with any more. The server already
 * decides that — `isDead` — so the two sides cannot disagree about what a
 * bulk clear is about to remove.
 */
export function groupReceivedShares(shares = []) {
  const invitations = [];
  const collection = [];
  const dead = [];

  shares.forEach((share) => {
    if (share.isDead) {
      dead.push(share);
    } else if (share.status === SHARE_STATUS.PENDING) {
      invitations.push(share);
    } else if (share.status === SHARE_STATUS.ACCEPTED) {
      collection.push(share);
    }
  });

  return { invitations, collection, dead };
}

/**
 * Count each state within one comic's recipients, for the summary line under a
 * "Shared by me" card.
 */
export function summariseRecipients(recipients = []) {
  const counts = {
    [SHARE_STATUS.PENDING]: 0,
    [SHARE_STATUS.ACCEPTED]: 0,
    [SHARE_STATUS.DECLINED]: 0,
    [SHARE_STATUS.REVOKED]: 0,
    total: recipients.length,
  };

  recipients.forEach((recipient) => {
    if (counts[recipient.status] !== undefined) {
      counts[recipient.status] += 1;
    }
  });

  return counts;
}

/**
 * The confirmation text for "Remove all dead shares".
 *
 * Says how many records are going and, explicitly, that live shares are not
 * touched — the whole risk of a bulk action is the user not knowing its reach.
 */
export function describeDeadShareCleanup(count) {
  if (count === 1) {
    return {
      title: "Remove 1 unavailable shared comic?",
      body:
        "This comic can no longer be accessed. Removing it only clears the dead entry "
        + "from your sharing history; comics that are still shared with you are not affected.",
    };
  }

  return {
    title: `Remove ${count} unavailable shared comics?`,
    body:
      "These comics can no longer be accessed because their owners removed them or stopped "
      + "sharing them. This only clears the dead entries from your sharing history; comics "
      + "that are still shared with you are not affected.",
  };
}

/**
 * The warning an owner sees before deleting a comic other people can read.
 *
 * Returns null when nobody is affected, so the caller can keep the ordinary
 * delete wording rather than special-casing a zero.
 */
export function describeShareImpactOfDeletion(comic) {
  const count = comic?.sharedWithCount || 0;
  if (count <= 0) return null;

  return `This comic is currently shared with ${count} ${count === 1 ? "person" : "people"}. `
    + "Deleting it will immediately remove their access and leave them an unavailable entry "
    + "in their sharing history.";
}

/** Email validation matching what the backend accepts, plus the RFC 5321 limit. */
export function isValidShareEmail(email) {
  if (typeof email !== "string") return false;
  const trimmed = email.trim();

  return trimmed.length <= 254
    && /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/
      .test(trimmed);
}
