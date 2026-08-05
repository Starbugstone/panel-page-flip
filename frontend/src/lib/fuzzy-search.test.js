import { describe, expect, it } from "vitest";
import { fuzzyFilter } from "./fuzzy-search";

describe("fuzzyFilter", () => {
  const comics = [
    { title: "The Savage Sword of Conan", author: "Roy Thomas", tags: ["Marvel"] },
    { title: "Vampirella", author: "Forrest J Ackerman", tags: ["Horror"] },
  ];

  it("compensates for typos in comic metadata", () => {
    expect(fuzzyFilter(comics, "conen", ["title", "author", "tags"]).map((comic) => comic.title))
      .toEqual(["The Savage Sword of Conan"]);
  });

  it("compensates for typos in tag names", () => {
    const tags = [{ name: "Marvel" }, { name: "Manga" }, { name: "Hidden" }];
    expect(fuzzyFilter(tags, "marvl", ["name"]).map((tag) => tag.name)).toEqual(["Marvel"]);
  });

  it("keeps the complete list when the query is empty", () => {
    expect(fuzzyFilter(comics, "", ["title"])).toEqual(comics);
  });
});
