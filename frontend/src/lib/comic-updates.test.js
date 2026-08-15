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

  /**
   * The same regression, one slice later: the review flow gained credits, an
   * age rating and a language, and this payload is where they would go missing.
   */
  it("carries the fields the provider review flow can fill", () => {
    const { changes } = buildComicUpdatePayload(edited({
      issueCount: 75,
      languageCode: "en",
      ageRating: "Mature 17+",
    }));

    expect(changes).toMatchObject({
      issueCount: 75,
      languageCode: "en",
      ageRating: "Mature 17+",
    });
  });

  /**
   * Credits and the accepted provider match are only sent when the editor
   * resolved them. Defaulting them to null the way the scalar fields do would
   * wipe both on every ordinary save.
   */
  it("leaves credits and the provider match out when the editor did not touch them", () => {
    const { changes } = buildComicUpdatePayload(edited());

    expect(changes).not.toHaveProperty("creators");
    expect(changes).not.toHaveProperty("metadataProvider");
    expect(changes).not.toHaveProperty("metadataExternalId");
  });

  it("carries the accepted provider match so a refresh can find it again", () => {
    const { changes } = buildComicUpdatePayload(edited({
      creators: { writer: ["Neil Gaiman"] },
      metadataProvider: "metron",
      metadataExternalId: "123925",
    }));

    expect(changes).toMatchObject({
      creators: { writer: ["Neil Gaiman"] },
      metadataProvider: "metron",
      metadataExternalId: "123925",
    });
  });
});
