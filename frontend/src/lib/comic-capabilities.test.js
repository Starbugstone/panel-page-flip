import { describe, expect, it } from "vitest";
import { describeComicCapabilities } from "./comic-capabilities";

describe("describeComicCapabilities", () => {
  // The flags are absent on an ordinary owned comic; absent must not mean "no".
  it("allows everything on a comic with no flags at all", () => {
    expect(describeComicCapabilities({ id: 1 })).toEqual({
      isSharedWithMe: false,
      canEdit: true,
      canDelete: true,
      canShare: true,
    });
  });

  it("honours each flag the server withheld", () => {
    expect(describeComicCapabilities({ canEdit: false }).canEdit).toBe(false);
    expect(describeComicCapabilities({ canDelete: false }).canDelete).toBe(false);
    expect(describeComicCapabilities({ canShare: false }).canShare).toBe(false);
  });

  /**
   * A comic somebody shared with you is not yours. Even if the server sent
   * permissive flags, no owner action is offered: editing another person's
   * comic, deleting it, or passing it on are all things a recipient cannot do.
   */
  it("withholds every owner action on a comic shared with the viewer", () => {
    const shared = describeComicCapabilities({ isShared: true, canEdit: true, canDelete: true, canShare: true });

    expect(shared).toEqual({ isSharedWithMe: true, canEdit: false, canDelete: false, canShare: false });
  });
});
