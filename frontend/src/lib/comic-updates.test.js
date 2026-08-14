import { describe, expect, it } from "vitest";

import { buildComicUpdatePayload } from "./comic-updates";

const edited = (overrides = {}) => ({
  id: 3,
  title: "Sandman",
  author: "Neil Gaiman",
  publisher: "Vertigo",
  description: "Dreams.",
  tags: ["noir"],
  explicitContent: false,
  ...overrides,
});

describe("buildComicUpdatePayload", () => {
  /**
   * The regression this exists for: the edit form gained structured metadata
   * and this payload did not, so an accepted suggestion was staged, saved, and
   * silently dropped.
   */
  it("carries the structured fields the edit form collects", () => {
    const { changes } = buildComicUpdatePayload(edited({
      series: "The Sandman",
      issueNumber: "17",
      volume: 1989,
      publishedAt: "1990-07-01",
    }));

    expect(changes).toMatchObject({
      series: "The Sandman",
      issueNumber: "17",
      volume: 1989,
      publishedAt: "1990-07-01",
    });
  });

  it("still carries everything it carried before", () => {
    const { id, changes } = buildComicUpdatePayload(edited());

    expect(id).toBe(3);
    expect(changes).toMatchObject({
      title: "Sandman",
      author: "Neil Gaiman",
      publisher: "Vertigo",
      description: "Dreams.",
      tags: ["noir"],
      explicitContent: false,
    });
  });

  /** undefined disappears in JSON, and an absent key means "leave it alone". */
  it("sends an absent field as null so it clears rather than persists", () => {
    const { changes } = buildComicUpdatePayload(edited());

    for (const field of ["series", "issueNumber", "volume", "publishedAt"]) {
      expect(changes[field], field).toBeNull();
    }
  });

  it("treats anything but true as not explicit", () => {
    expect(buildComicUpdatePayload(edited({ explicitContent: undefined })).changes.explicitContent).toBe(false);
    expect(buildComicUpdatePayload(edited({ explicitContent: "yes" })).changes.explicitContent).toBe(false);
    expect(buildComicUpdatePayload(edited({ explicitContent: true })).changes.explicitContent).toBe(true);
  });
});
