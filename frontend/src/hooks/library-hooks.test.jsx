import { act, renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { api } from "@/lib/api";
import { useLibraryComicActions } from "./use-library-comic-actions";
import { useLibraryContents } from "./use-library-contents";
import { useLibraryFolders } from "./use-library-folders";
import { useLibrarySearch } from "./use-library-search";

const { toast } = vi.hoisted(() => ({ toast: vi.fn() }));

vi.mock("@/lib/api", () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}));
vi.mock("@/hooks/use-toast.js", () => ({ useToast: () => ({ toast }) }));

describe("library hooks", () => {
  beforeEach(() => vi.clearAllMocks());

  it("derives sorted comics, child folders, and folder labels", () => {
    const { result } = renderHook(() => useLibraryContents({
      comics: [{ id: 2, title: "Zulu" }, { id: 1, title: "Alpha" }],
      folders: [
        { id: 8, parentId: 7, name: "Child" },
        { id: 7, parentId: null, name: "Parent" },
      ],
      activeView: "all",
      activeFolderId: 7,
      isSearchActive: false,
      isFolderView: true,
      sort: "title-asc",
    }));

    expect(result.current.visibleComics.map((comic) => comic.title)).toEqual(["Alpha", "Zulu"]);
    expect(result.current.childFolders).toEqual([{ id: 8, parentId: 7, name: "Child" }]);
    expect(result.current.folderNames.get(7)).toBe("Parent");
  });

  it("loads folders and appends a newly created one", async () => {
    vi.mocked(api.get).mockResolvedValue({ folders: [{ id: 1, name: "Manga" }] });
    vi.mocked(api.post).mockResolvedValue({ folder: { id: 2, name: "Comics" } });
    const { result } = renderHook(() => useLibraryFolders());

    await waitFor(() => expect(result.current.isLoading).toBe(false));
    expect(result.current.folders).toEqual([{ id: 1, name: "Manga" }]);

    await act(() => result.current.createFolder("Comics"));
    expect(api.post).toHaveBeenCalledWith("/api/library/folders", { name: "Comics", parentId: null });
    expect(result.current.folders).toHaveLength(2);
  });

  it("resets reading progress through the API and local library", async () => {
    vi.mocked(api.post).mockResolvedValue({});
    const updateComicProgress = vi.fn();
    const { result } = renderHook(() => useLibraryComicActions({
      refreshCurrent: vi.fn(),
      updateComicProgress,
      removeComicsFromLibrary: vi.fn(),
      refreshSummary: vi.fn(),
      moveComics: vi.fn(),
    }));

    await act(() => result.current.resetReadingProgress(9));

    expect(api.post).toHaveBeenCalledWith("/api/comics/9/reading-progress/reset", {});
    expect(updateComicProgress).toHaveBeenCalledWith(9, null);
  });

  it("resets every selected comic through the API and local library", async () => {
    vi.mocked(api.post).mockResolvedValue({});
    const updateComicProgress = vi.fn();
    const { result } = renderHook(() => useLibraryComicActions({
      refreshCurrent: vi.fn(),
      updateComicProgress,
      removeComicsFromLibrary: vi.fn(),
      refreshSummary: vi.fn(),
      moveComics: vi.fn(),
    }));

    await act(() => result.current.resetReadingProgressForComics([9, 12]));

    expect(api.post).toHaveBeenCalledWith("/api/comics/9/reading-progress/reset", {});
    expect(api.post).toHaveBeenCalledWith("/api/comics/12/reading-progress/reset", {});
    expect(updateComicProgress).toHaveBeenCalledWith(9, null);
    expect(updateComicProgress).toHaveBeenCalledWith(12, null);
    expect(toast).toHaveBeenCalledWith({
      title: "2 comics reset",
    });
  });

  it("continues resetting the selection after one comic fails and reports the partial result", async () => {
    vi.mocked(api.post).mockImplementation((path) => path.includes("/9/")
      ? Promise.reject(new Error("Comic unavailable"))
      : Promise.resolve({}));
    const updateComicProgress = vi.fn();
    const { result } = renderHook(() => useLibraryComicActions({
      refreshCurrent: vi.fn(),
      updateComicProgress,
      removeComicsFromLibrary: vi.fn(),
      refreshSummary: vi.fn(),
      moveComics: vi.fn(),
    }));

    await act(async () => {
      await expect(result.current.resetReadingProgressForComics([9, 12])).rejects.toThrow();
    });

    expect(api.post).toHaveBeenCalledWith("/api/comics/12/reading-progress/reset", {});
    expect(updateComicProgress).not.toHaveBeenCalledWith(9, null);
    expect(updateComicProgress).toHaveBeenCalledWith(12, null);
    expect(toast).toHaveBeenCalledWith(expect.objectContaining({
      title: "1 of 2 comics reset",
      description: "Comic unavailable",
      variant: "destructive",
    }));
  });

  it("searches the whole library with the selected tags", async () => {
    const loadLibrary = vi.fn().mockResolvedValue([]);
    const { result } = renderHook(() => useLibrarySearch({
      loadLibrary,
      ownership: "mine",
      isFolderView: true,
      activeFolderId: 7,
      foldersLoading: false,
      invalidFolder: false,
    }));
    await waitFor(() => expect(loadLibrary).toHaveBeenCalledWith({
      url: "/api/comics?ownership=mine&folder=7",
      fuzzyQuery: "",
    }));

    await act(() => result.current.search({ query: "hero", tags: ["Manga"] }));

    expect(loadLibrary).toHaveBeenLastCalledWith({
      url: "/api/comics?tags=Manga",
      fuzzyQuery: "hero",
    });
    expect(result.current.isSearchActive).toBe(true);
  });

  it("does not reload an unfiltered library when only the folder tree finishes loading", async () => {
    const loadLibrary = vi.fn().mockResolvedValue([]);
    const { rerender } = renderHook(
      ({ foldersLoading }) => useLibrarySearch({
        loadLibrary,
        ownership: "all",
        isFolderView: false,
        activeFolderId: null,
        foldersLoading,
        invalidFolder: false,
      }),
      { initialProps: { foldersLoading: true } },
    );

    await waitFor(() => expect(loadLibrary).toHaveBeenCalledTimes(1));
    rerender({ foldersLoading: false });
    await waitFor(() => expect(loadLibrary).toHaveBeenCalledTimes(1));
  });
});
