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
  const cause = TOMBSTONE_REASONS[reason];

  // A reason added on the server that this build does not know about must still
  // read as a sentence, so it drops the clause rather than explaining the
  // unavailability with itself.
  return cause
    ? `This comic is no longer available because ${cause}.`
    : "This comic is no longer available.";
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

  if (share.sharingRestricted || share.contentQuarantined) {
    return "Access to this shared comic has been temporarily restricted by the service administrator.";
  }

  switch (share.status) {
    case SHARE_STATUS.REVOKED:
      return "The owner has stopped sharing this comic with you.";
    case SHARE_STATUS.DECLINED:
      return "You declined this invitation.";
    default:
      break;
  }

  // After the endings, before the ordinary states. An age gate is something the
  // recipient can act on, so it is worth saying — but only while there is still
  // something behind it. Offering to unlock a share the owner has already
  // withdrawn would be an invitation to a dead end.
  if (share.requiresAdultConfirmation) {
    return share.status === SHARE_STATUS.ACCEPTED
      ? "This comic is marked 18+. Confirm your age to read it again."
      : "This comic is marked 18+. Confirm your age to see what is being shared.";
  }

  switch (share.status) {
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

/**
 * The same warning for a bulk deletion, summed over the comics being removed.
 *
 * Counts comics rather than people: one recipient may hold access to several of
 * them, and adding those together would overstate how many people are affected.
 */
export function describeBulkShareImpactOfDeletion(comics = []) {
  const shared = comics.filter((comic) => (comic?.sharedWithCount || 0) > 0);
  if (shared.length === 0) return null;

  return `${shared.length} of these ${shared.length === 1 ? "comics is" : "comics are"} currently shared. `
    + "Deleting them will immediately remove their recipients' access and leave those recipients an "
    + "unavailable entry in their sharing history.";
}

/** Email validation matching what the backend accepts, plus the RFC 5321 limit. */
export function isValidShareEmail(email) {
  if (typeof email !== "string") return false;
  const trimmed = email.trim();

  return trimmed.length <= 254
    && /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/
      .test(trimmed);
}

/* -------------------------------------------------------------------------- */
/* Sharing codes                                                               */
/* -------------------------------------------------------------------------- */

/**
 * Mirrors SharingCodeFormat on the server: twelve characters from Crockford's
 * alphabet, shown in threes. No I, L, O or U, so a code read off one screen and
 * typed into another survives the trip.
 */
export const SHARING_CODE_LENGTH = 12;

const SHARING_CODE_ALPHABET = "0123456789ABCDEFGHJKMNPQRSTVWXYZ";

/**
 * What somebody typed, reduced to the form the server compares.
 *
 * Lowercase, spaces, missing dashes and the letters the alphabet leaves out are
 * all somebody transcribing a code by hand rather than holding the wrong one,
 * so they are corrected here exactly as they are on the server.
 */
export function normaliseSharingCode(value) {
  if (typeof value !== "string") return "";

  return value
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, "")
    .replace(/I|L/g, "1")
    .replace(/O/g, "0")
    .replace(/U/g, "V")
    .slice(0, SHARING_CODE_LENGTH);
}

/** The grouped form, which is the only form anybody is shown. */
export function formatSharingCode(value) {
  const normalised = normaliseSharingCode(value);

  return (normalised.match(/.{1,4}/g) || []).join("-");
}

export function isValidSharingCode(value) {
  const normalised = normaliseSharingCode(value);

  return normalised.length === SHARING_CODE_LENGTH
    && [...normalised].every((character) => SHARING_CODE_ALPHABET.includes(character));
}

/** How the two kinds of code are described wherever they are offered. */
export const SHARING_CODE_COPY = {
  mine: "Give this to someone so they can share comics with you. It only ever shows them "
    + "your name — never your email address.",
  rotate: "Replace your code if it has ended up somewhere you did not intend. The old one stops "
    + "working immediately, and anyone who still has it will need the new one. Comics already "
    + "shared with you are not affected.",
  recipient: "Share with someone by their code instead of their email address. "
    + "You will see their name to check you have the right person.",
  claim: "Create a code instead of naming anyone. Anyone you give it to can claim these "
    + "comics until it runs out of uses, and it expires after 24 hours.",
  redeem: "Someone sent you a code? Redeem it here to add their comics to your collection.",
  handedOut: "Withdraw a code at any time to stop anyone else using it. Anyone who already "
    + "claimed a comic keeps it until you revoke them. Codes that have stopped working are kept "
    + "for a month so you can see who took them up.",
};

/**
 * What an owner is shown against a recipient.
 *
 * A recipient reached by code has no address to show — that was the point —
 * so the server sends a label instead and this prefers it wherever it exists.
 */
export function recipientLabel(recipient) {
  return recipient?.recipientLabel || recipient?.recipientEmail || "Shared by code";
}

/** Whether this recipient can be reached again, and how. */
export function recipientTarget(recipient) {
  if (recipient?.recipientSharingCode) {
    return { sharingCode: recipient.recipientSharingCode, email: "" };
  }

  return { sharingCode: "", email: recipient?.recipientEmail || "" };
}

/* -------------------------------------------------------------------------- */
/* Explicit content                                                            */
/* -------------------------------------------------------------------------- */

/**
 * The wording for both halves of the classification feature, kept here rather
 * than inline in the components.
 *
 * These sentences are the feature: they are what tells a sender what they are
 * taking on and a recipient what they are about to open. Keeping them in one
 * place is what lets them be asserted directly, and stops the share modal and
 * the sharing page drifting into saying different things.
 */
export const SHARE_RESPONSIBILITY_NOTICE = "You are responsible for the content you share. "
  + "Only share material you are allowed to distribute, and make sure adult or explicit comics "
  + "are correctly marked as Explicit content (18+) before sending them.";

export const SHARE_RESPONSIBILITY_ACK_LABEL =
  "I confirm that I have the necessary rights or authorization to share this material with the recipient.";

export const SHARING_PAGE_RESPONSIBILITY_REMINDER =
  "You are responsible for the content you share, for having the necessary rights or authorization to distribute it, and for marking explicit material correctly.";

export const EXPLICIT_FLAG_LABEL = "Explicit content (18+)";

export const EXPLICIT_FLAG_DESCRIPTION = "Mark this comic as containing adult or explicit material. "
  + "Recipients will have to confirm they are 18 or older before the comic is revealed or accepted.";

export const EXPLICIT_GATE_TITLE = "Explicit content — 18+";

export const EXPLICIT_GATE_BODY = "This shared comic has been marked by its owner as containing "
  + "explicit adult content. You must be 18 or older to continue. The cover and comic details will "
  + "remain hidden until you confirm.";

export const EXPLICIT_GATE_CONFIRM_LABEL = "I am 18 or older — continue";

/**
 * Whether an invitation or share is still waiting on the recipient's age
 * declaration.
 *
 * Reads the server's answer rather than deriving one from `explicitContent`:
 * the backend is the thing that decides what it has withheld, and a client that
 * worked it out separately could show a title the server had redacted, or hide
 * one it had not.
 */
export function requiresAdultConfirmation(share) {
  return share?.requiresAdultConfirmation === true;
}

/**
 * What to call a comic whose title the server is withholding.
 *
 * There is genuinely nothing to show — the redaction is the point — so this
 * names the state instead of leaving an empty heading.
 */
export function shareDisplayTitle(share) {
  if (requiresAdultConfirmation(share)) {
    // A dead entry stays redacted — the recipient never passed the gate, and
    // the share ending is not the same as them having done so — but there is no
    // longer anything to confirm, so it must not promise one.
    return share?.isDead
      ? "Hidden — explicit content (18+)"
      : "Hidden until you confirm your age";
  }

  return share?.comicTitle || "Untitled comic";
}

/**
 * Whether "Send invitation" may be pressed.
 *
 * Both conditions, always. The acknowledgement is not a formality the UI can
 * skip once an address looks right — the backend rejects a share without it —
 * and putting the rule here keeps the button and the request in agreement.
 */
export function canSendInvitation({ email, responsibilityAccepted }) {
  return isValidShareEmail(email) && responsibilityAccepted === true;
}

/**
 * The body of an invitation request.
 *
 * The acknowledgement goes on the wire as a literal `true`; the server accepts
 * nothing else, and the timestamp it stores is its own.
 */
export function buildInvitationRequest({ email, responsibilityAccepted }) {
  return {
    email: typeof email === "string" ? email.trim() : "",
    senderResponsibilityAccepted: responsibilityAccepted === true,
  };
}
