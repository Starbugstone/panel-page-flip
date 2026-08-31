import { renderHook } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { useLibraryFolderActions } from "./use-library-folder-actions";
import { api } from "@/lib/api";

const { toast } = vi.hoisted(() => ({ toast: vi.fn() }));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn() } }));
vi.mock("@/hooks/use-toast.js", () => ({ useToast: () => ({ toast }) }));

const folders = [{ id: 7, name: "DragonBall", parentId: null }];

const actions = (activeFolderId = 7) => renderHook(() => useLibraryFolderActions({
  folders,
  activeFolderId,
  navigateFolder: vi.fn(),
  createFolder: vi.fn(),
  updateFolder: vi.fn(),
  deleteFolder: vi.fn(),
})).result.current;

/**
 * Sharing a folder starts by asking the server what is in it.
 *
 * The library page may be showing a search, a subfolder or a table selection,
 * and none of those describe the folder somebody just pointed at — so the ids
 * are never assembled from what happens to be on screen.
 */
describe("useLibraryFolderActions.shareCurrentFolder", () => {
  beforeEach(() => vi.clearAllMocks());

  it("asks the server what the folder holds rather than reading the page", async () => {
    vi.mocked(api.get).mockResolvedValue({
      folder: { id: 7, name: "DragonBall" },
      comicIds: [11, 12],
      comicCount: 2,
      folderCount: 1,
      unshareableCount: 1,
      limit: 200,
    });

    const share = await actions().shareCurrentFolder();

    expect(api.get).toHaveBeenCalledWith("/api/shares/folders/7/comics");
    expect(share).toEqual({
      folderId: 7,
      folderName: "DragonBall",
      // Strings, because that is what the selection is keyed on.
      comicIds: ["11", "12"],
      unshareableCount: 1,
      limit: 200,
    });
  });

  it("says so rather than opening an empty dialog", async () => {
    vi.mocked(api.get).mockResolvedValue({
      folder: { id: 7, name: "DragonBall" }, comicIds: [], comicCount: 0,
      folderCount: 1, unshareableCount: 2, limit: 200,
    });

    expect(await actions().shareCurrentFolder()).toBeNull();
    expect(toast).toHaveBeenCalledWith(expect.objectContaining({ title: "Nothing to share" }));
  });

  /**
   * Refused before anybody is named. Filling in a recipient and only then being
   * told the folder is too big is the same refusal arriving after the work.
   */
  it("refuses a folder past the ceiling before the dialog opens", async () => {
    vi.mocked(api.get).mockResolvedValue({
      folder: { id: 7, name: "DragonBall" }, comicIds: [], comicCount: 201,
      folderCount: 1, unshareableCount: 0, limit: 200,
    });

    expect(await actions().shareCurrentFolder()).toBeNull();
    expect(toast).toHaveBeenCalledWith(expect.objectContaining({
      title: "Too much to share at once",
      description: expect.stringContaining("201"),
      variant: "destructive",
    }));
  });

  it("opens an oversized folder when the server marks an admin share as unlimited", async () => {
    vi.mocked(api.get).mockResolvedValue({
      folder: { id: 7, name: "DragonBall" }, comicIds: [11, 12], comicCount: 201,
      folderCount: 1, unshareableCount: 0, limit: null,
    });

    expect(await actions().shareCurrentFolder()).toEqual(expect.objectContaining({
      folderId: 7,
      limit: null,
    }));
    expect(toast).not.toHaveBeenCalled();
  });

  it("reports a failed read instead of opening on nothing", async () => {
    vi.mocked(api.get).mockRejectedValue(new Error("Network is down"));

    expect(await actions().shareCurrentFolder()).toBeNull();
    expect(toast).toHaveBeenCalledWith(expect.objectContaining({
      title: "Could not read that folder",
      variant: "destructive",
    }));
  });

  it("has nothing to share at the library root", async () => {
    expect(await actions(null).shareCurrentFolder()).toBeNull();
    expect(api.get).not.toHaveBeenCalled();
  });
});
