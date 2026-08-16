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
/* Usernames                                                                   */
/* -------------------------------------------------------------------------- */

/**
 * Mirrors UsernamePolicy on the server.
 *
 * The server is the authority — it owns the reserved list and the unique index
 * — and this exists so the registration form can say "too short" before it asks
 * anybody to wait for a round trip.
 */
export const USERNAME_MIN_LENGTH = 3;
export const USERNAME_MAX_LENGTH = 32;

/** How a username is written wherever it stands for a person. */
export function usernameHandle(username) {
  return typeof username === "string" && username !== "" ? `@${username}` : "";
}

/** The @ is how a username is written, not part of it. */
export function stripUsernamePrefix(value) {
  return typeof value === "string" ? value.trim().replace(/^@+/, "") : "";
}

/** Why this username cannot be used, or null. The server checks again. */
export function validateUsername(value) {
  const username = stripUsernamePrefix(value);

  if (username === "") return "Choose a username.";
  if (username.length < USERNAME_MIN_LENGTH || username.length > USERNAME_MAX_LENGTH) {
    return `A username must be between ${USERNAME_MIN_LENGTH} and ${USERNAME_MAX_LENGTH} characters.`;
  }
  if (!/^[A-Za-z0-9][A-Za-z0-9_-]*$/.test(username)) {
    return "A username can use letters, numbers, hyphens and underscores, and must start with a letter or number.";
  }

  return null;
}

export function isValidUsername(value) {
  return validateUsername(value) === null;
}

/* -------------------------------------------------------------------------- */
/* Sharing codes                                                               */
/* -------------------------------------------------------------------------- */

/**
 * Mirrors SharingCodeFormat on the server: a type letter, then twelve
 * characters from Crockford's alphabet shown in threes. No I, L, O or U, so a
 * code read off one screen and typed into another survives the trip.
 */
export const SHARING_CODE_LENGTH = 12;

const SHARING_CODE_ALPHABET = "0123456789ABCDEFGHJKMNPQRSTVWXYZ";

/**
 * What each prefix means.
 *
 * The letter is outside the random token, so it costs no entropy and buys the
 * one thing entropy cannot: a code can be classified before anything is looked
 * up, and somebody who pastes a comic code into the recipient box can be told
 * what they are holding instead of being told it is invalid.
 */
export const SHARE_CODE_TYPES = {
  USER: "U",
  COMIC: "C",
  GROUP: "G",
};

/** Where a code of each type actually belongs, for when one is in the wrong box. */
export const SHARE_CODE_MISUSE = {
  [SHARE_CODE_TYPES.USER]: "This is a user code. Use it when sharing directly with another user.",
  [SHARE_CODE_TYPES.COMIC]: "This is a comic code. Redeem it under Shared with me.",
  [SHARE_CODE_TYPES.GROUP]: "This is a group code. Redeem it under Shared with me.",
};

/**
 * The token half of what somebody typed, reduced to the form the server
 * compares.
 *
 * Lowercase, spaces, missing dashes and the letters the alphabet leaves out are
 * all somebody transcribing a code by hand rather than holding the wrong one,
 * so they are corrected here exactly as they are on the server.
 */
function normaliseToken(value) {
  return String(value)
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, "")
    .replace(/I|L/g, "1")
    .replace(/O/g, "0")
    .replace(/U/g, "V")
    .slice(0, SHARING_CODE_LENGTH);
}

/**
 * What somebody typed, split into the type it claims and its token.
 *
 * Returns null for anything that is not one of the three shapes — including the
 * old unprefixed form, which this release deliberately stopped accepting.
 */
export function parseShareCode(value) {
  if (typeof value !== "string") return null;

  const condensed = value.toUpperCase().replace(/[^A-Z0-9]/g, "");
  if (condensed.length !== SHARING_CODE_LENGTH + 1) return null;

  const type = condensed.slice(0, 1);
  if (!Object.values(SHARE_CODE_TYPES).includes(type)) return null;

  const token = normaliseToken(condensed.slice(1));
  if (token.length !== SHARING_CODE_LENGTH) return null;
  if (![...token].every((character) => SHARING_CODE_ALPHABET.includes(character))) return null;

  return { type, token, code: `${type}-${(token.match(/.{1,4}/g) || []).join("-")}` };
}

/**
 * The grouped form, as somebody types it.
 *
 * Keeps a prefix the moment one is recognisable and leaves the rest alone, so
 * the field can be typed into left to right rather than rewriting itself into
 * something the typist did not mean.
 */
export function formatShareCode(value) {
  if (typeof value !== "string") return "";

  const condensed = value.toUpperCase().replace(/[^A-Z0-9]/g, "");
  if (condensed === "") return "";

  const type = Object.values(SHARE_CODE_TYPES).includes(condensed.slice(0, 1))
    ? condensed.slice(0, 1)
    : null;
  const token = normaliseToken(type ? condensed.slice(1) : condensed);
  const grouped = (token.match(/.{1,4}/g) || []).join("-");

  return type ? [type, grouped].filter(Boolean).join("-") : grouped;
}

/**
 * Whether this is a complete code, optionally of a particular kind.
 *
 * @param {string|string[]} [expected] one type or several; omitted accepts any
 */
export function isValidShareCode(value, expected) {
  const parsed = parseShareCode(value);
  if (parsed === null) return false;
  if (expected === undefined) return true;

  return (Array.isArray(expected) ? expected : [expected]).includes(parsed.type);
}

/**
 * The guidance for a code of the wrong kind, or null.
 *
 * Returns null both when the code is the right kind and when it is not a code
 * at all: a half-typed one is not a mistake worth interrupting somebody over.
 */
export function shareCodeMisuse(value, expected) {
  const parsed = parseShareCode(value);
  if (parsed === null) return null;

  const wanted = Array.isArray(expected) ? expected : [expected];

  return wanted.includes(parsed.type) ? null : SHARE_CODE_MISUSE[parsed.type];
}

/** How the three kinds of code are described wherever they are offered. */
export const SHARING_CODE_COPY = {
  mine: "Give this to someone so they can share comics with you. It only ever shows them "
    + "your username — never your email address.",
  rotate: "Replace your code if it has ended up somewhere you did not intend. The old one stops "
    + "working immediately, and anyone who still has it will need the new one. Your username and "
    + "the comics already shared with you are not affected.",
  recipient: "Share with someone by their username or their U- code instead of their email "
    + "address. You will see who they are before anything is sent.",
  comicCode: "Create a C- code for this one comic instead of naming anyone. Anyone you give it "
    + "to can claim it until it runs out of uses or expires.",
  groupCode: "Create a G- code for these comics as one package — a story arc handed over with a "
    + "single code. Redeeming it costs one use however many comics it carries.",
  redeem: "Someone sent you a C- or G- code? Redeem it here to add their comics to your collection.",
  handedOut: "Withdraw a code at any time to stop anyone else using it. Anyone who already "
    + "claimed a comic keeps it until you revoke them. Codes that have stopped working are kept "
    + "for a month so you can see who took them up.",
};

/**
 * What an owner is shown against a recipient.
 *
 * Username first wherever there is one: it is the public identity of a
 * registered account and, unlike a display name, it identifies exactly one
 * person. An address only appears for somebody with no account yet, where it is
 * genuinely all either side knows.
 */
export function recipientLabel(recipient) {
  return recipient?.recipientLabel
    || usernameHandle(recipient?.recipientUsername)
    || recipient?.recipientEmail
    || "Shared by code";
}

/** Whether this recipient can be reached again, and how. */
export function recipientTarget(recipient) {
  if (recipient?.recipientUsername) {
    return { username: recipient.recipientUsername, userCode: "", email: "" };
  }
  if (recipient?.recipientUserCode) {
    return { username: "", userCode: recipient.recipientUserCode, email: "" };
  }

  return { username: "", userCode: "", email: recipient?.recipientEmail || "" };
}

/* -------------------------------------------------------------------------- */
/* Notification delivery                                                       */
/* -------------------------------------------------------------------------- */

export const NOTIFICATION_STATE = {
  PENDING: "pending",
  SENT: "sent",
  FAILED: "failed",
};

/**
 * What to say about the email that announces a share, or null when there is
 * nothing worth saying.
 *
 * The share is real in every one of these states. This exists so an owner whose
 * mail server was having a bad afternoon is told the notice did not arrive,
 * rather than being left to wonder why nobody answered.
 */
export function describeNotification(recipient) {
  switch (recipient?.notificationState) {
    case NOTIFICATION_STATE.PENDING:
      return "The share is ready. Its email has not gone out yet.";
    case NOTIFICATION_STATE.FAILED:
      // Resend is the route back, and deliberately the only one the owner has:
      // the invitation link is minted as the email is written and is never
      // handed to them, so telling them to pass it on themselves would be
      // advice they cannot follow. A recipient who already has an account does
      // not need the email at all — the share is waiting on their own page.
      return "The share was created, but its email could not be delivered. Resend it. If they already have an account, it is waiting on their Sharing page.";
    default:
      return null;
  }
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
