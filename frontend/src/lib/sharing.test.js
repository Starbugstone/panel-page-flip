import { describe, expect, it } from "vitest";
import {
  EXPLICIT_FLAG_DESCRIPTION,
  EXPLICIT_FLAG_LABEL,
  NOTIFICATION_STATE,
  SHARE_CODE_TYPES,
  SHARE_RESPONSIBILITY_ACK_LABEL,
  SHARE_RESPONSIBILITY_NOTICE,
  SHARE_STATUS,
  SHARING_CODE_COPY,
  SHARING_PAGE_RESPONSIBILITY_REMINDER,
  buildInvitationRequest,
  canSendInvitation,
  describeNotification,
  formatShareCode,
  isValidShareCode,
  isValidUsername,
  parseShareCode,
  recipientLabel,
  recipientTarget,
  shareCodeMisuse,
  stripUsernamePrefix,
  usernameHandle,
  validateUsername,
  describeBulkShareImpactOfDeletion,
  describeDeadShareCleanup,
  describeReceivedShare,
  describeShareImpactOfDeletion,
  groupReceivedShares,
  isValidShareEmail,
  requiresAdultConfirmation,
  shareDisplayTitle,
  summariseRecipients,
  tombstoneExplanation,
} from "./sharing";

const share = (overrides = {}) => ({
  id: 1,
  status: SHARE_STATUS.ACCEPTED,
  isTombstoned: false,
  isDead: false,
  isExpired: false,
  removedFromCollection: null,
  tombstoneReason: null,
  ...overrides,
});

describe("tombstoneExplanation", () => {
  it("names the cause it was given", () => {
    expect(tombstoneExplanation("owner_deleted")).toBe(
      "This comic is no longer available because the owner removed it."
    );
    expect(tombstoneExplanation("owner_account_deleted")).toContain("deleted their account");
    expect(tombstoneExplanation("file_missing")).toContain("its file is missing");
    expect(tombstoneExplanation("administratively_removed")).toContain("an administrator removed it");
  });

  it("drops the clause for a reason it does not recognise", () => {
    // A new reason added on the server must neither produce "undefined" on
    // screen nor explain the unavailability with itself.
    expect(tombstoneExplanation("something_new")).toBe("This comic is no longer available.");
    expect(tombstoneExplanation(null)).toBe("This comic is no longer available.");
  });
});

describe("describeReceivedShare", () => {
  it("explains a tombstone rather than describing its status", () => {
    const description = describeReceivedShare(
      share({ isTombstoned: true, status: SHARE_STATUS.ACCEPTED, tombstoneReason: "owner_deleted" })
    );

    expect(description).toContain("the owner removed it");
  });

  it("distinguishes an expired invitation from one still waiting", () => {
    expect(describeReceivedShare(share({ status: SHARE_STATUS.PENDING }))).toContain(
      "Waiting for you"
    );
    expect(
      describeReceivedShare(share({ status: SHARE_STATUS.PENDING, isExpired: true }))
    ).toContain("expired");
  });

  it("says an accepted share is in the collection, or that it was hidden", () => {
    expect(describeReceivedShare(share())).toBe("In your collection.");
    expect(
      describeReceivedShare(share({ removedFromCollection: "2026-08-01T00:00:00+00:00" }))
    ).toContain("restore it");
  });

  it("attributes a revocation to the owner and a decline to the recipient", () => {
    expect(describeReceivedShare(share({ status: SHARE_STATUS.REVOKED }))).toContain(
      "The owner has stopped sharing"
    );
    expect(describeReceivedShare(share({ status: SHARE_STATUS.DECLINED }))).toContain("You declined");
  });

  it("explains an administrative restriction without exposing the complainant", () => {
    const description = describeReceivedShare(share({ sharingRestricted: true }));

    expect(description).toContain("temporarily restricted by the service administrator");
    expect(description).not.toContain("reporter");
  });

  it("uses the same neutral explanation for quarantined content", () => {
    expect(describeReceivedShare(share({ contentQuarantined: true })))
      .toContain("temporarily restricted by the service administrator");
  });
});

describe("groupReceivedShares", () => {
  it("routes each share to exactly one group", () => {
    const shares = [
      share({ id: 1, status: SHARE_STATUS.PENDING }),
      share({ id: 2, status: SHARE_STATUS.ACCEPTED }),
      share({ id: 3, status: SHARE_STATUS.REVOKED, isDead: true }),
      share({ id: 4, status: SHARE_STATUS.ACCEPTED, isTombstoned: true, isDead: true }),
    ];

    const { invitations, collection, dead } = groupReceivedShares(shares);

    expect(invitations.map((s) => s.id)).toEqual([1]);
    expect(collection.map((s) => s.id)).toEqual([2]);
    expect(dead.map((s) => s.id)).toEqual([3, 4]);
  });

  it("treats deadness as the server reports it, whatever the status says", () => {
    // A tombstoned share keeps status "accepted"; classifying on status alone
    // would put an unreadable comic back in the collection.
    const { collection, dead } = groupReceivedShares([
      share({ id: 9, status: SHARE_STATUS.ACCEPTED, isTombstoned: true, isDead: true }),
    ]);

    expect(collection).toHaveLength(0);
    expect(dead).toHaveLength(1);
  });

  it("handles no shares at all", () => {
    expect(groupReceivedShares()).toEqual({ invitations: [], collection: [], dead: [] });
  });
});

describe("summariseRecipients", () => {
  it("counts every state and the total", () => {
    const counts = summariseRecipients([
      { status: SHARE_STATUS.ACCEPTED },
      { status: SHARE_STATUS.ACCEPTED },
      { status: SHARE_STATUS.PENDING },
      { status: SHARE_STATUS.DECLINED },
    ]);

    expect(counts).toEqual({
      accepted: 2,
      pending: 1,
      declined: 1,
      revoked: 0,
      total: 4,
    });
  });

  it("ignores a status it does not know without losing it from the total", () => {
    const counts = summariseRecipients([{ status: "something_else" }]);

    expect(counts.total).toBe(1);
    expect(counts.accepted).toBe(0);
  });
});

describe("describeDeadShareCleanup", () => {
  it("says how many records are going", () => {
    expect(describeDeadShareCleanup(7).title).toBe("Remove 7 unavailable shared comics?");
    expect(describeDeadShareCleanup(1).title).toBe("Remove 1 unavailable shared comic?");
  });

  it("promises that live shares are untouched", () => {
    expect(describeDeadShareCleanup(3).body).toContain("still shared with you are not affected");
    expect(describeDeadShareCleanup(1).body).toContain("still shared with you are not affected");
  });
});

describe("describeShareImpactOfDeletion", () => {
  it("says nothing when nobody else can read the comic", () => {
    expect(describeShareImpactOfDeletion({ sharedWithCount: 0 })).toBeNull();
    expect(describeShareImpactOfDeletion({})).toBeNull();
    expect(describeShareImpactOfDeletion(null)).toBeNull();
  });

  it("names the number of people who lose access", () => {
    expect(describeShareImpactOfDeletion({ sharedWithCount: 3 })).toContain("shared with 3 people");
    expect(describeShareImpactOfDeletion({ sharedWithCount: 1 })).toContain("shared with 1 person");
  });
});

describe("describeBulkShareImpactOfDeletion", () => {
  it("says nothing when none of the selection is shared", () => {
    expect(describeBulkShareImpactOfDeletion([{ sharedWithCount: 0 }, {}])).toBeNull();
    expect(describeBulkShareImpactOfDeletion([])).toBeNull();
    expect(describeBulkShareImpactOfDeletion()).toBeNull();
  });

  it("counts comics rather than adding recipients up", () => {
    // One person may hold access to several of them; summing the counts would
    // claim more people are affected than there are.
    const warning = describeBulkShareImpactOfDeletion([
      { sharedWithCount: 3 },
      { sharedWithCount: 2 },
      { sharedWithCount: 0 },
    ]);

    expect(warning).toContain("2 of these comics are currently shared");
    expect(warning).not.toContain("5");
  });

  it("reads correctly for a single shared comic", () => {
    expect(describeBulkShareImpactOfDeletion([{ sharedWithCount: 1 }, { sharedWithCount: 0 }]))
      .toContain("1 of these comics is currently shared");
  });
});

describe("isValidShareEmail", () => {
  it("accepts an ordinary address, with or without surrounding space", () => {
    expect(isValidShareEmail("jane@example.com")).toBe(true);
    expect(isValidShareEmail("  jane@example.com  ")).toBe(true);
  });

  it("rejects malformed addresses and non-strings", () => {
    expect(isValidShareEmail("jane@")).toBe(false);
    expect(isValidShareEmail("jane")).toBe(false);
    expect(isValidShareEmail("")).toBe(false);
    expect(isValidShareEmail(null)).toBe(false);
    expect(isValidShareEmail(undefined)).toBe(false);
  });

  it("rejects an address past the RFC 5321 length limit", () => {
    expect(isValidShareEmail(`${"a".repeat(250)}@example.com`)).toBe(false);
  });
});

describe("the sender responsibility acknowledgement", () => {
  it("is worded as a statement about the sender's own obligations", () => {
    // The wording is the feature. It has to say who is responsible and what
    // they are being asked to have done, not merely that rules exist.
    expect(SHARE_RESPONSIBILITY_NOTICE).toContain("You are responsible for the content you share");
    expect(SHARE_RESPONSIBILITY_NOTICE).toContain("allowed to distribute");
    expect(SHARE_RESPONSIBILITY_NOTICE).toContain(EXPLICIT_FLAG_LABEL);
    expect(SHARE_RESPONSIBILITY_ACK_LABEL).toContain("necessary rights or authorization to share");
  });

  it("is reflected on the sharing page as a reminder", () => {
    expect(SHARING_PAGE_RESPONSIBILITY_REMINDER)
      .toContain("You are responsible for the content you share");
    expect(SHARING_PAGE_RESPONSIBILITY_REMINDER).toContain("marking explicit material correctly");
  });
});

describe("canSendInvitation", () => {
  it("needs both a valid address and the acknowledgement", () => {
    expect(canSendInvitation({ email: "jane@example.com", responsibilityAccepted: true })).toBe(true);
  });

  it("refuses a valid address without the acknowledgement", () => {
    // The whole point of the tick box: a well-formed address is not consent.
    expect(canSendInvitation({ email: "jane@example.com", responsibilityAccepted: false })).toBe(false);
    expect(canSendInvitation({ email: "jane@example.com" })).toBe(false);
  });

  it("refuses an acknowledgement without a valid address", () => {
    expect(canSendInvitation({ email: "jane@", responsibilityAccepted: true })).toBe(false);
    expect(canSendInvitation({ email: "", responsibilityAccepted: true })).toBe(false);
  });

  it("treats anything other than a literal true as unacknowledged", () => {
    expect(canSendInvitation({ email: "jane@example.com", responsibilityAccepted: "true" })).toBe(false);
    expect(canSendInvitation({ email: "jane@example.com", responsibilityAccepted: 1 })).toBe(false);
  });
});

describe("buildInvitationRequest", () => {
  it("sends the trimmed address and the acknowledgement", () => {
    expect(buildInvitationRequest({ email: "  jane@example.com ", responsibilityAccepted: true }))
      .toEqual({ email: "jane@example.com", senderResponsibilityAccepted: true });
  });

  it("never claims an acknowledgement that was not given", () => {
    expect(buildInvitationRequest({ email: "jane@example.com" }).senderResponsibilityAccepted)
      .toBe(false);
    expect(
      buildInvitationRequest({ email: "jane@example.com", responsibilityAccepted: "yes" })
        .senderResponsibilityAccepted
    ).toBe(false);
  });

  it("carries no timestamp of its own", () => {
    // Both acknowledgement timestamps are the server's. A client that sent one
    // would be writing its own audit trail.
    expect(Object.keys(buildInvitationRequest({ email: "jane@example.com", responsibilityAccepted: true })))
      .toEqual(["email", "senderResponsibilityAccepted"]);
  });
});

describe("requiresAdultConfirmation", () => {
  it("follows the server's answer rather than deriving one", () => {
    // Only the backend knows what it redacted, so an explicit comic that has
    // already been confirmed for is not gated, and nothing here second-guesses
    // that from `explicitContent` alone.
    expect(requiresAdultConfirmation({ explicitContent: true, requiresAdultConfirmation: true })).toBe(true);
    expect(requiresAdultConfirmation({ explicitContent: true, requiresAdultConfirmation: false })).toBe(false);
    expect(requiresAdultConfirmation({ explicitContent: false })).toBe(false);
    expect(requiresAdultConfirmation(null)).toBe(false);
  });
});

describe("shareDisplayTitle", () => {
  it("names the redaction rather than showing an empty heading", () => {
    expect(shareDisplayTitle({ requiresAdultConfirmation: true, comicTitle: null }))
      .toBe("Hidden until you confirm your age");
  });

  it("uses the title once the server is willing to send one", () => {
    expect(shareDisplayTitle({ requiresAdultConfirmation: false, comicTitle: "Sandman" }))
      .toBe("Sandman");
    expect(shareDisplayTitle({ comicTitle: "" })).toBe("Untitled comic");
  });
});

describe("describeReceivedShare, for explicit shares", () => {
  it("asks a pending invitation's recipient to confirm before anything is revealed", () => {
    const line = describeReceivedShare(share({
      status: SHARE_STATUS.PENDING,
      requiresAdultConfirmation: true,
      comicTitle: null,
    }));

    expect(line).toContain("marked 18+");
    expect(line).toContain("Confirm your age");
  });

  it("explains a comic that was re-gated after being accepted", () => {
    // The owner marked an already-shared comic explicit. The share survived;
    // reading it did not, until the recipient confirms again.
    const line = describeReceivedShare(share({
      status: SHARE_STATUS.ACCEPTED,
      requiresAdultConfirmation: true,
    }));

    expect(line).toContain("Confirm your age to read it again");
  });

  it("says nothing extra once the gate is passed", () => {
    expect(describeReceivedShare(share({
      status: SHARE_STATUS.ACCEPTED,
      explicitContent: true,
      requiresAdultConfirmation: false,
    }))).toBe("In your collection.");
  });

  it("still explains a tombstone rather than the gate", () => {
    // Nothing can be confirmed for a comic that is gone; the recipient needs
    // the explanation, which is all the tombstone has left.
    expect(describeReceivedShare(share({
      isTombstoned: true,
      tombstoneReason: "owner_deleted",
      requiresAdultConfirmation: true,
    }))).toContain("no longer available");
  });
});

describe("explicit shares that have ended", () => {
  it("explains the ending rather than offering a gate that leads nowhere", () => {
    // Confirming an age for a share the owner has withdrawn achieves nothing;
    // saying so would be an invitation to a dead end.
    expect(describeReceivedShare(share({
      status: SHARE_STATUS.REVOKED,
      isDead: true,
      requiresAdultConfirmation: true,
    }))).toContain("The owner has stopped sharing");

    expect(describeReceivedShare(share({
      status: SHARE_STATUS.DECLINED,
      isDead: true,
      requiresAdultConfirmation: true,
    }))).toContain("You declined");
  });

  it("stays redacted without promising a confirmation", () => {
    // The recipient never passed the gate, and the share ending is not the same
    // as them having done so.
    expect(shareDisplayTitle({ requiresAdultConfirmation: true, isDead: true }))
      .toBe("Hidden — explicit content (18+)");
  });
});

/* -------------------------------------------------------------------------- */
/* Usernames                                                                   */
/* -------------------------------------------------------------------------- */

describe("usernames", () => {
  it("is written with an at sign wherever it stands for a person", () => {
    expect(usernameHandle("SilverOtter4821")).toBe("@SilverOtter4821");
    expect(usernameHandle("")).toBe("");
    expect(usernameHandle(undefined)).toBe("");
  });

  it("treats a pasted at sign as punctuation rather than part of the name", () => {
    expect(stripUsernamePrefix("@SilverOtter4821")).toBe("SilverOtter4821");
    expect(stripUsernamePrefix("  @SilverOtter4821  ")).toBe("SilverOtter4821");
    expect(stripUsernamePrefix("SilverOtter4821")).toBe("SilverOtter4821");
  });

  it("accepts the names the server would", () => {
    ["SilverOtter4821", "abc", "quiet_falcon", "copper-mantis", "2000AD"]
      .forEach((username) => expect(validateUsername(username)).toBeNull());
  });

  it("says why an unusable name cannot be used", () => {
    expect(validateUsername("")).toMatch(/Choose a username/);
    expect(validateUsername("ab")).toMatch(/between 3 and 32/);
    expect(validateUsername("a".repeat(33))).toMatch(/between 3 and 32/);
    expect(validateUsername("silver otter")).toMatch(/letters, numbers/);
    expect(validateUsername("-silverotter")).toMatch(/must start with/);
    expect(isValidUsername("silver.otter")).toBe(false);
  });
});

/* -------------------------------------------------------------------------- */
/* Sharing codes                                                               */
/* -------------------------------------------------------------------------- */

describe("share codes", () => {
  it("describes the identity, reveal, and explicit-code workflows", () => {
    expect(SHARING_CODE_COPY.mine).toMatch(/Give your U- code/);
    expect(SHARING_CODE_COPY.handedOut).toMatch(/Show a code again/);
    expect(SHARING_CODE_COPY.redeem).toMatch(
      /18\+ comics need age confirmation and acceptance/
    );
    expect(EXPLICIT_FLAG_DESCRIPTION).toMatch(
      /New shares must also be accepted before they can be read/
    );
  });

  it("splits a code into the type it claims and its token", () => {
    expect(parseShareCode("U-7RFX-KP3M-Q82D")).toEqual({
      type: SHARE_CODE_TYPES.USER,
      token: "7RFXKP3MQ82D",
      code: "U-7RFX-KP3M-Q82D",
    });
    expect(parseShareCode("C-7RFX-KP3M-Q82D").type).toBe(SHARE_CODE_TYPES.COMIC);
    expect(parseShareCode("G-7RFX-KP3M-Q82D").type).toBe(SHARE_CODE_TYPES.GROUP);
  });

  it("corrects transcription rather than refusing it", () => {
    // Lowercase, missing dashes, stray spaces and the letters the alphabet
    // leaves out are all somebody writing a code down by hand.
    ["u7rfxkp3mq82d", "U 7RFX KP3M Q82D", "u-7rfx-kp3m-q82d"]
      .forEach((typed) => expect(parseShareCode(typed)?.code).toBe("U-7RFX-KP3M-Q82D"));

    expect(parseShareCode("U-ILOU-1234-5678")?.token).toBe("110V12345678");
  });

  it("does not recognise the old unprefixed form", () => {
    // Accepting it would be the compatibility layer this release avoided.
    expect(parseShareCode("7RFX-KP3M-Q82D")).toBeNull();
    expect(parseShareCode("X-7RFX-KP3M-Q82D")).toBeNull();
    expect(parseShareCode("U-7RFX-KP3M-Q82")).toBeNull();
    expect(parseShareCode("")).toBeNull();
    expect(parseShareCode(undefined)).toBeNull();
  });

  it("formats as it is typed, left to right", () => {
    expect(formatShareCode("u7rf")).toBe("U-7RF");
    expect(formatShareCode("u7rfxkp3mq82d")).toBe("U-7RFX-KP3M-Q82D");
    expect(formatShareCode("")).toBe("");
  });

  it("checks a code against the kind a field is asking for", () => {
    expect(isValidShareCode("U-7RFX-KP3M-Q82D", SHARE_CODE_TYPES.USER)).toBe(true);
    expect(isValidShareCode("C-7RFX-KP3M-Q82D", SHARE_CODE_TYPES.USER)).toBe(false);
    expect(isValidShareCode("C-7RFX-KP3M-Q82D")).toBe(true);
    expect(isValidShareCode("G-7RFX-KP3M-Q82D", [SHARE_CODE_TYPES.COMIC, SHARE_CODE_TYPES.GROUP]))
      .toBe(true);
  });

  it("says where a code of the wrong kind actually belongs", () => {
    expect(shareCodeMisuse("C-7RFX-KP3M-Q82D", SHARE_CODE_TYPES.USER))
      .toMatch(/comic code. Redeem it under Shared with me/);
    expect(shareCodeMisuse("U-7RFX-KP3M-Q82D", [SHARE_CODE_TYPES.COMIC, SHARE_CODE_TYPES.GROUP]))
      .toMatch(/user code. Use it when sharing directly/);

    // The right kind, and a half-typed one, are both silence: interrupting
    // somebody mid-keystroke is not guidance.
    expect(shareCodeMisuse("U-7RFX-KP3M-Q82D", SHARE_CODE_TYPES.USER)).toBeNull();
    expect(shareCodeMisuse("U-7RF", SHARE_CODE_TYPES.USER)).toBeNull();
  });
});

describe("naming a recipient", () => {
  it("prefers the public identity of a registered account", () => {
    expect(recipientLabel({
      recipientLabel: "Jane Reader (@SilverOtter4821)",
      recipientEmail: null,
    })).toBe("Jane Reader (@SilverOtter4821)");

    expect(recipientLabel({ recipientUsername: "SilverOtter4821" })).toBe("@SilverOtter4821");
  });

  it("falls back to an address only for somebody with no account", () => {
    expect(recipientLabel({ recipientEmail: "newcomer@example.com" })).toBe("newcomer@example.com");
    expect(recipientLabel({})).toBe("Shared by code");
  });

  it("reaches somebody again the way they were reached before", () => {
    expect(recipientTarget({ recipientUsername: "SilverOtter4821" }))
      .toEqual({ username: "SilverOtter4821", userCode: "", email: "" });
    expect(recipientTarget({ recipientUserCode: "U-7RFX-KP3M-Q82D" }))
      .toEqual({ username: "", userCode: "U-7RFX-KP3M-Q82D", email: "" });
    expect(recipientTarget({ recipientEmail: "newcomer@example.com" }))
      .toEqual({ username: "", userCode: "", email: "newcomer@example.com" });
  });
});

describe("describeNotification", () => {
  it("says nothing when the notice went out", () => {
    expect(describeNotification({ notificationState: NOTIFICATION_STATE.SENT })).toBeNull();
    expect(describeNotification({})).toBeNull();
  });

  it("distinguishes a notice still queued from one that failed", () => {
    // The share is real in both. What differs is whether anybody has been told.
    expect(describeNotification({ notificationState: NOTIFICATION_STATE.PENDING }))
      .toMatch(/has not gone out yet/);
    expect(describeNotification({ notificationState: NOTIFICATION_STATE.FAILED }))
      .toMatch(/could not be delivered/);
  });
});
