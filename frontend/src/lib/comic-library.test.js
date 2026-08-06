import { describe, expect, it } from "vitest";
import {
  applyProgressUpdate,
  createMutationLog,
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

describe("createMutationLog", () => {
  // What the server would answer for a library it believes still has both.
  const serverAnswer = () => [
    { id: 1, title: "Conan", readingProgress: { currentPage: 2 }, lastReadPage: 2 },
    { id: 2, title: "Vampirella", readingProgress: null },
  ];

  const remove = (ids) => (comics) => removeComics(comics, ids);
  const setPage = (id, page) => (comics) => applyProgressUpdate(comics, id, { currentPage: page });

  it("replays a deletion that happened while the fetch was out", () => {
    const log = createMutationLog();

    const mark = log.beginLoad();
    log.record(remove([2]));
    const rebased = log.rebase(serverAnswer(), mark);
    log.endLoad();

    expect(rebased.map((comic) => comic.id)).toEqual([1]);
  });

  it("replays a reading position saved while the fetch was out", () => {
    const log = createMutationLog();

    const mark = log.beginLoad();
    log.record(setPage(1, 40));
    const rebased = log.rebase(serverAnswer(), mark);
    log.endLoad();

    expect(rebased[0].lastReadPage).toBe(40);
  });

  it("leaves a fetch alone when the change came after it landed", () => {
    const log = createMutationLog();

    const mark = log.beginLoad();
    const rebased = log.rebase(serverAnswer(), mark);
    log.endLoad();
    // Past endLoad nothing is in flight, so this change has no response left to
    // correct; the store applies it directly.
    log.record(remove([2]));

    expect(rebased.map((comic) => comic.id)).toEqual([1, 2]);
    expect(log.rebase(serverAnswer(), 0).map((comic) => comic.id)).toEqual([1, 2]);
  });

  it("does not replay a change that predates the load", () => {
    const log = createMutationLog();

    const first = log.beginLoad();
    log.record(remove([2]));
    // A second load starts after the deletion, so the server already knows.
    const second = log.beginLoad();

    expect(log.rebase(serverAnswer(), second).map((comic) => comic.id)).toEqual([1, 2]);
    expect(log.rebase(serverAnswer(), first).map((comic) => comic.id)).toEqual([1]);
  });

  it("keeps the log until the last overlapping load has finished", () => {
    const log = createMutationLog();

    const first = log.beginLoad();
    log.beginLoad();
    log.record(remove([2]));
    log.endLoad();

    expect(log.rebase(serverAnswer(), first).map((comic) => comic.id)).toEqual([1]);

    // Nothing is in flight now, so the log starts over rather than growing for
    // the rest of the session.
    log.endLoad();
    expect(log.rebase(serverAnswer(), 0).map((comic) => comic.id)).toEqual([1, 2]);
  });

  it("forgets everything when the library is reset on logout", () => {
    const log = createMutationLog();

    const mark = log.beginLoad();
    log.record(remove([2]));
    log.reset();

    expect(log.rebase(serverAnswer(), mark).map((comic) => comic.id)).toEqual([1, 2]);
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
