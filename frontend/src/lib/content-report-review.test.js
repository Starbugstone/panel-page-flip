import { describe, expect, it } from "vitest";

import {
  contentReportActionSupports,
  contentReportReviewPayload,
  createContentReportReview,
} from "./content-report-review";

describe("content report review model", () => {
  it("moves a newly received case into review without changing its target", () => {
    expect(createContentReportReview({ status: "received" })).toEqual(expect.objectContaining({
      status: "under_review",
      pendingTarget: undefined,
      action: "none",
    }));
  });

  it("omits untouched targets but serializes an explicit unlink", () => {
    const review = createContentReportReview({ status: "under_review" });
    expect(contentReportReviewPayload(review)).not.toHaveProperty("targetType");

    expect(contentReportReviewPayload({ ...review, pendingTarget: null })).toEqual(expect.objectContaining({
      targetType: null,
      targetId: null,
    }));
  });

  it("accepts share-owned comics for comic and account actions", () => {
    const share = { type: "share", owner: { id: 7 } };
    expect(contentReportActionSupports(share, "comic")).toBe(true);
    expect(contentReportActionSupports(share, "user")).toBe(true);
    expect(contentReportActionSupports(null, "comic")).toBe(false);
  });
});
