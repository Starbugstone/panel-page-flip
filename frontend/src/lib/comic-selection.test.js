import { describe, expect, it } from "vitest";
import { describeComicSelection, MAX_SHAREABLE_SELECTION } from "./comic-selection";

const owned = (id) => ({ id, title: `Comic ${id}` });
const received = (id) => ({ id, title: `Comic ${id}`, canEdit: false, canDelete: false, canShare: false });

const select = (comics, ids, options) => describeComicSelection(comics, new Set(ids), options);

describe("describeComicSelection", () => {
  const comics = [owned(1), owned(2), received(3)];

  it("counts only the selected comics still in the list", () => {
    // 99 was selected before the list changed under it. It is not a comic here.
    expect(select(comics, [1, 99]).selectedComicIds).toEqual([1]);
  });

  it("allows owner actions only when every selected comic is the viewer's own", () => {
    expect(select(comics, [1, 2]).ownerActionsAllowed).toBe(true);
    expect(select(comics, [1, 3]).ownerActionsAllowed).toBe(false);
    expect(select(comics, []).ownerActionsAllowed).toBe(false);
  });

  /**
   * A comic shared with you cannot be passed on. The mixed case is refused
   * outright rather than quietly narrowed: sharing 2 of 3 while the sender
   * believes they shared all 3 is worse than sharing none.
   */
  it("blocks sharing when the selection mixes owned and received comics", () => {
    expect(select(comics, [1, 3]).shareBlocked).toBe(true);
    expect(select(comics, [1, 3]).canShareSelection).toBe(false);
    expect(select(comics, [1, 2]).shareBlocked).toBe(false);
    expect(select(comics, [1, 2]).canShareSelection).toBe(true);
  });

  it("refuses a selection larger than the server will accept", () => {
    const many = Array.from({ length: MAX_SHAREABLE_SELECTION + 1 }, (_, index) => owned(index));
    const all = many.map((comic) => comic.id);

    expect(select(many, all).shareOverLimit).toBe(true);
    expect(select(many, all).canShareSelection).toBe(false);
    expect(select(many, all.slice(0, MAX_SHAREABLE_SELECTION)).shareOverLimit).toBe(false);
  });

  it("cannot share when the caller offers no way to", () => {
    expect(select(comics, [1, 2], { canShare: false }).canShareSelection).toBe(false);
  });

  it("cannot share nothing", () => {
    expect(select(comics, []).canShareSelection).toBe(false);
  });
});
