import { describe, expect, it } from "vitest";
import {
  applyViewFilter,
  buildLibraryUrl,
  resolveLibraryLocation,
  sortComics,
} from "./library-view";

const params = (search) => new URLSearchParams(search);
const folders = [{ id: 7, name: "Reprints" }];

describe("resolveLibraryLocation", () => {
  it("reads the whole library when the URL asks for nothing", () => {
    const location = resolveLibraryLocation(params(""), folders, false);

    expect(location).toMatchObject({
      isFolderView: false,
      activeFolderId: null,
      activeView: "all",
      ownership: "all",
      invalidFolder: false,
    });
  });

  it("treats the root folder as a location without an id", () => {
    const location = resolveLibraryLocation(params("folder=root"), folders, false);

    expect(location).toMatchObject({ isFolderView: true, activeFolderId: null, invalidFolder: false });
  });

  it("resolves a folder that exists", () => {
    const location = resolveLibraryLocation(params("folder=7"), folders, false);

    expect(location).toMatchObject({ isFolderView: true, activeFolderId: 7, invalidFolder: false });
  });

  it("rejects a folder id that is not a number", () => {
    expect(resolveLibraryLocation(params("folder=../etc"), folders, false).invalidFolder).toBe(true);
  });

  it("rejects a folder that no longer exists", () => {
    expect(resolveLibraryLocation(params("folder=99"), folders, false).invalidFolder).toBe(true);
  });

  // Otherwise a bookmarked folder is thrown away on every reload, in the gap
  // before the tree that would have vouched for it arrives.
  it("does not call a folder missing while the tree is still loading", () => {
    expect(resolveLibraryLocation(params("folder=99"), [], true).invalidFolder).toBe(false);
  });

  it("narrows ownership only for the two views that mean ownership", () => {
    expect(resolveLibraryLocation(params("view=mine"), folders, false).ownership).toBe("mine");
    expect(resolveLibraryLocation(params("view=shared"), folders, false).ownership).toBe("shared");
    expect(resolveLibraryLocation(params("view=reading"), folders, false).ownership).toBe("all");
  });

  it("falls back to every comic when the URL names a view that does not exist", () => {
    expect(resolveLibraryLocation(params("view=favourites"), folders, false).activeView).toBe("all");
  });
});

describe("buildLibraryUrl", () => {
  it("asks for everything when nothing narrows it", () => {
    expect(buildLibraryUrl({ ownership: "all", isFolderView: false, activeFolderId: null }))
      .toBe("/api/comics");
  });

  it("names the root folder explicitly, so loose comics are not confused with all of them", () => {
    expect(buildLibraryUrl({ ownership: "all", isFolderView: true, activeFolderId: null }))
      .toBe("/api/comics?folder=root");
  });

  it("carries both the ownership and the folder", () => {
    expect(buildLibraryUrl({ ownership: "shared", isFolderView: true, activeFolderId: 7 }))
      .toBe("/api/comics?ownership=shared&folder=7");
  });
});

describe("applyViewFilter", () => {
  const comics = [
    { id: 1, title: "Started", readingProgress: { currentPage: 4 }, pageCount: 20 },
    { id: 2, title: "Untouched", pageCount: 20 },
    { id: 3, title: "Synced", pageCount: 20, tags: ["Dropbox"] },
  ];
  const inView = (activeView) => applyViewFilter(comics, { activeView, isSearchActive: false, isFolderView: false })
    .map((comic) => comic.id);

  it("keeps only comics somebody is part way through", () => {
    expect(inView("reading")).toEqual([1]);
  });

  it("keeps only comics nobody has opened", () => {
    expect(inView("unread")).toEqual([2, 3]);
  });

  it("keeps only what came from Dropbox", () => {
    expect(inView("dropbox")).toEqual([3]);
  });

  it("keeps everything for the views that do not mean reading state", () => {
    expect(inView("all")).toEqual([1, 2, 3]);
    expect(inView("mine")).toEqual([1, 2, 3]);
  });

  // A search and a folder have each already chosen the candidates. Filtering
  // them again by a view the user cannot see would hide results silently.
  it("leaves a search result alone", () => {
    const result = applyViewFilter(comics, { activeView: "unread", isSearchActive: true, isFolderView: false });
    expect(result).toEqual(comics);
  });

  it("leaves a folder's contents alone", () => {
    const result = applyViewFilter(comics, { activeView: "unread", isSearchActive: false, isFolderView: true });
    expect(result).toEqual(comics);
  });
});

describe("sortComics", () => {
  const comics = [
    { title: "Beta", uploadedAt: "2024-01-02", updatedAt: "2024-03-01" },
    { title: "Alpha", uploadedAt: "2024-01-03", updatedAt: "2024-01-01" },
    { title: "Gamma", uploadedAt: "2024-01-01", updatedAt: "2024-02-01" },
  ];
  const titles = (sort) => sortComics(comics, sort).map((comic) => comic.title);

  it("sorts by title in both directions", () => {
    expect(titles("title-asc")).toEqual(["Alpha", "Beta", "Gamma"]);
    expect(titles("title-desc")).toEqual(["Gamma", "Beta", "Alpha"]);
  });

  it("sorts by when a comic arrived, newest or oldest first", () => {
    expect(titles("uploaded-desc")).toEqual(["Alpha", "Beta", "Gamma"]);
    expect(titles("uploaded-asc")).toEqual(["Gamma", "Beta", "Alpha"]);
  });

  it("sorts by when a comic last changed", () => {
    expect(titles("updated-desc")).toEqual(["Beta", "Gamma", "Alpha"]);
  });

  it("falls back to title order rather than leaving an unknown sort unsorted", () => {
    expect(titles("nonsense")).toEqual(["Alpha", "Beta", "Gamma"]);
  });

  it("does not reorder the array it was given", () => {
    const original = [...comics];
    sortComics(comics, "title-desc");
    expect(comics).toEqual(original);
  });
});
