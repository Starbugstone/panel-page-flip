import { act, renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { useFolderComicTitleRenamer } from "./use-folder-comic-title-renamer";
import { api } from "@/lib/api";

const { toast } = vi.hoisted(() => ({ toast: vi.fn() }));

vi.mock("@/lib/api", () => ({ api: { patch: vi.fn() } }));
vi.mock("@/hooks/use-toast.js", () => ({ useToast: () => ({ toast }) }));

const sourceComics = [
  { id: 1, title: "DragonBall 1", canEdit: true },
  { id: 2, title: "DragonBall 10", canEdit: true },
];

const renderRenamer = (locationKey = "folder:7") => {
  const refreshCurrent = vi.fn().mockResolvedValue(undefined);
  const hook = renderHook(
    ({ folder }) => useFolderComicTitleRenamer({
      locationKey: folder,
      comics: sourceComics,
      refreshCurrent,
    }),
    { initialProps: { folder: locationKey } },
  );
  return { ...hook, refreshCurrent };
};

describe("useFolderComicTitleRenamer", () => {
  beforeEach(() => vi.clearAllMocks());

  it("stages a reversible preview without writing", async () => {
    const { result } = renderRenamer();

    act(() => result.current.startPreview());
    expect(result.current.session).toEqual(expect.objectContaining({ phase: "preview", count: 1 }));
    expect(result.current.previewComics[0].title).toBe("DragonBall 01");

    await act(() => result.current.undo());
    expect(result.current.session).toBeNull();
    expect(api.patch).not.toHaveBeenCalled();
  });

  it("accepts the whole preview atomically and keeps an undo action", async () => {
    vi.mocked(api.patch).mockResolvedValue({ updatedComicIds: [1] });
    const { result, refreshCurrent } = renderRenamer();

    act(() => result.current.startPreview());
    await act(() => result.current.accept());

    expect(api.patch).toHaveBeenCalledWith("/api/comics/titles", {
      updates: [{ id: 1, currentTitle: "DragonBall 1", title: "DragonBall 01" }],
    });
    expect(refreshCurrent).toHaveBeenCalledOnce();
    expect(result.current.session).toEqual(expect.objectContaining({ phase: "accepted", count: 1 }));
    expect(result.current.previewComics[0].title).toBe("DragonBall 01");
  });

  it("undoes an accepted rename through the same stale-safe endpoint", async () => {
    vi.mocked(api.patch).mockResolvedValue({ updatedComicIds: [1] });
    const { result } = renderRenamer();

    act(() => result.current.startPreview());
    await act(() => result.current.accept());
    await act(() => result.current.undo());

    expect(api.patch).toHaveBeenLastCalledWith("/api/comics/titles", {
      updates: [{ id: 1, currentTitle: "DragonBall 01", title: "DragonBall 1" }],
    });
    expect(result.current.session).toBeNull();
  });

  it("keeps the preview available when acceptance fails", async () => {
    vi.mocked(api.patch).mockRejectedValue(new Error("Titles changed elsewhere"));
    const { result } = renderRenamer();

    act(() => result.current.startPreview());
    await act(() => result.current.accept());

    expect(result.current.session.phase).toBe("preview");
    expect(toast).toHaveBeenCalledWith(expect.objectContaining({
      title: "Rename failed",
      description: "Titles changed elsewhere",
      variant: "destructive",
    }));
  });

  it("drops folder-specific undo state permanently when navigation leaves the folder", async () => {
    vi.mocked(api.patch).mockResolvedValue({ updatedComicIds: [1] });
    const { result, rerender } = renderRenamer();

    act(() => result.current.startPreview());
    rerender({ folder: "folder:8" });
    await waitFor(() => expect(result.current.session).toBeNull());
    await act(() => Promise.resolve());
    rerender({ folder: "folder:7" });
    expect(result.current.session).toBeNull();
  });
});
