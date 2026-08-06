import { describe, expect, it } from "vitest";
import {
  describeTagSubmission,
  filterTags,
  findTagByName,
  normalizeTagName,
  tagKeyOf,
} from "@/lib/tag-suggestions.js";

const tags = [
  { id: 1, name: "Sci Fi", isGlobal: false },
  { id: 2, name: "Superhero", isGlobal: true },
  { id: 3, name: "Space Opera", isGlobal: false },
  { id: 4, name: "science", isGlobal: false },
];

describe("normalizeTagName", () => {
  it("trims and collapses whitespace", () => {
    expect(normalizeTagName("  Sci   Fi ")).toBe("Sci Fi");
  });

  it("treats null and undefined as empty", () => {
    expect(normalizeTagName(null)).toBe("");
    expect(normalizeTagName(undefined)).toBe("");
  });
});

describe("tagKeyOf", () => {
  it("reads the name off a tag object or a bare string alike", () => {
    expect(tagKeyOf({ name: "Sci Fi" })).toBe("sci fi");
    expect(tagKeyOf("Sci Fi")).toBe("sci fi");
  });
});

describe("findTagByName", () => {
  it("matches regardless of case and surrounding whitespace", () => {
    expect(findTagByName(tags, "  sci fi ")).toMatchObject({ id: 1 });
  });

  it("returns nothing for an empty query", () => {
    expect(findTagByName(tags, "   ")).toBeUndefined();
  });

  it("prefers the global tag when a personal tag shares its name", () => {
    const shadowed = [
      { id: 9, name: "Manga", isGlobal: false },
      { id: 10, name: "manga", isGlobal: true },
    ];

    expect(findTagByName(shadowed, "MANGA")).toMatchObject({ id: 10 });
  });
});

describe("filterTags", () => {
  it("shows everything when nothing has been typed yet", () => {
    expect(filterTags(tags, "")).toHaveLength(tags.length);
  });

  it("lists global tags first when nothing has been typed", () => {
    expect(filterTags(tags, "")[0]).toMatchObject({ id: 2 });
  });

  it("filters case-insensitively on a substring", () => {
    expect(filterTags(tags, "sci").map((tag) => tag.id)).toEqual([1, 4]);
  });

  it("ranks a prefix match above a mid-word match", () => {
    const list = [
      { id: 1, name: "Deep Space" },
      { id: 2, name: "Space Opera" },
    ];

    expect(filterTags(list, "space").map((tag) => tag.id)).toEqual([2, 1]);
  });

  it("returns nothing when no tag contains the query", () => {
    expect(filterTags(tags, "zzz")).toEqual([]);
  });

  it("caps the number of suggestions", () => {
    const many = Array.from({ length: 200 }, (_, index) => ({ id: index, name: `tag-${index}` }));

    expect(filterTags(many, "tag", { limit: 5 })).toHaveLength(5);
  });
});

describe("describeTagSubmission", () => {
  it("reports an empty input as nothing to submit", () => {
    expect(describeTagSubmission(tags, "   ").status).toBe("empty");
  });

  it("resolves a differently cased entry to the existing tag", () => {
    expect(describeTagSubmission(tags, "sci fi")).toMatchObject({
      status: "existing",
      name: "Sci Fi",
    });
  });

  it("resolves a whitespace variant to the existing tag", () => {
    expect(describeTagSubmission(tags, " Sci   Fi ")).toMatchObject({
      status: "existing",
      name: "Sci Fi",
    });
  });

  it("offers to create a tag only when nothing matches", () => {
    expect(describeTagSubmission(tags, "Noir")).toEqual({ status: "new", name: "Noir" });
  });

  it("reports a tag already applied to the target as a duplicate", () => {
    expect(describeTagSubmission(tags, "SCI FI", ["Sci Fi"]).status).toBe("duplicate");
  });

  it("catches a duplicate that differs only by case on the target", () => {
    expect(describeTagSubmission(tags, "Superhero", [{ name: "superhero" }]).status)
      .toBe("duplicate");
  });

  it("submits the stored spelling rather than what was typed", () => {
    expect(describeTagSubmission(tags, "SUPERHERO").name).toBe("Superhero");
  });
});
