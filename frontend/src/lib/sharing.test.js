import { describe, expect, it } from "vitest";
import {
  SHARE_STATUS,
  describeBulkShareImpactOfDeletion,
  describeDeadShareCleanup,
  describeReceivedShare,
  describeShareImpactOfDeletion,
  groupReceivedShares,
  isValidShareEmail,
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
