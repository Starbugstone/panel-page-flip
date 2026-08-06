import { describe, expect, it } from "vitest";
import {
  applyProgressUpdate,
  libraryRequestKey,
  normaliseComic,
  normaliseComics,
  removeComics,
} from "./comic-library";

describe("normaliseComic", () => {
  it("flattens tags while keeping the badge metadata", () => {
    const comic = normaliseComic({
      id: 1,
      title: "Conan",
      tags: [
        { id: 7, name: "Marvel", hideFromLibrary: false },
        { id: 8, name: "Secret", hideFromLibrary: true },
      ],
      readingProgress: null,
    });

    expect(comic.tags).toEqual(["Marvel", "Secret"]);
    expect(comic.hiddenTagNames).toEqual(["Secret"]);
    expect(comic.tagDetails).toHaveLength(2);
  });

  it("exposes the stored page as lastReadPage, and leaves it unset without progress", () => {
    expect(normaliseComic({ id: 1, readingProgress: { currentPage: 12 } }).lastReadPage).toBe(12);
    expect(normaliseComic({ id: 1, readingProgress: null }).lastReadPage).toBeUndefined();
  });

  it("survives a comic that has no tags at all", () => {
    expect(normaliseComics([{ id: 1 }])[0].tags).toEqual([]);
    expect(normaliseComics(undefined)).toEqual([]);
  });
});

describe("libraryRequestKey", () => {
  it("separates a filtered list from the full library", () => {
    expect(libraryRequestKey("/api/comics")).not.toBe(libraryRequestKey("/api/comics?tags=Marvel"));
  });

  it("separates two searches that share a URL", () => {
    expect(libraryRequestKey("/api/comics", "conan")).not.toBe(libraryRequestKey("/api/comics", "vampirella"));
  });
});

describe("applyProgressUpdate", () => {
  const comics = [
    { id: 1, title: "Conan", readingProgress: { currentPage: 2, revision: 1 }, lastReadPage: 2 },
    { id: 2, title: "Vampirella", readingProgress: null },
  ];

  it("records the page the reader just saved", () => {
    const updated = applyProgressUpdate(comics, 1, { currentPage: 9, revision: 4, completed: false });

    expect(updated[0].lastReadPage).toBe(9);
    expect(updated[0].readingProgress).toMatchObject({ currentPage: 9, revision: 4 });
    expect(updated[1]).toBe(comics[1]);
  });

  it("matches a comic id that arrives as a string from the route", () => {
    expect(applyProgressUpdate(comics, "2", { currentPage: 5 })[1].lastReadPage).toBe(5);
  });

  it("clears the position when progress is reset", () => {
    const updated = applyProgressUpdate(comics, 1, null);

    expect(updated[0].readingProgress).toBeNull();
    expect(updated[0].lastReadPage).toBeUndefined();
  });

  it("returns the same list when the comic is not in it, so nothing re-renders", () => {
    expect(applyProgressUpdate(comics, 99, { currentPage: 1 })).toBe(comics);
  });
});

describe("removeComics", () => {
  const comics = [{ id: 1 }, { id: 2 }, { id: 3 }];

  it("drops every deleted comic", () => {
    expect(removeComics(comics, [1, 3]).map((comic) => comic.id)).toEqual([2]);
  });

  it("returns the same list when nothing matched", () => {
    expect(removeComics(comics, [99])).toBe(comics);
    expect(removeComics(comics, [])).toBe(comics);
  });
});
