import { useState } from "react";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { LibraryDialogs } from "./LibraryDialogs";
import { api } from "@/lib/api";

const { toast } = vi.hoisted(() => ({ toast: vi.fn() }));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast }) }));
vi.mock("@/hooks/use-toast.js", () => ({ useToast: () => ({ toast }) }));

const dragonBall = { id: 7, name: "DragonBall", parentId: null };
const folderPreview = {
  folderId: dragonBall.id,
  folderName: dragonBall.name,
  comicIds: ["1", "2"],
  unshareableCount: 0,
  limit: 200,
};

/**
 * The library's dialog state, held where the library holds it.
 *
 * The share dialog and the folder-move dialog are siblings keyed on the same
 * folder, so nothing short of mounting both together can show whether closing
 * one actually unmounts it.
 */
function LibraryWithDialogs() {
  const [sharingComicIds, setSharingComicIds] = useState(null);
  const [sharingFolder, setSharingFolder] = useState(null);
  const [movingComic, setMovingComic] = useState(null);
  const [movingFolder, setMovingFolder] = useState(false);
  const [creatingFolder, setCreatingFolder] = useState(false);

  return (
    <>
      <button onClick={() => setSharingFolder(folderPreview)}>Share folder</button>
      <button onClick={() => setSharingComicIds(["1"])}>Share comic</button>
      <LibraryDialogs
        folders={[dragonBall]}
        editing={{ comic: null, onClose: vi.fn() }}
        sharing={{
          comicIds: sharingComicIds,
          folder: sharingFolder,
          onClose: () => { setSharingComicIds(null); setSharingFolder(null); },
        }}
        movingComic={{ comic: movingComic, onClose: () => setMovingComic(null) }}
        movingFolder={{ open: movingFolder, folderId: dragonBall.id, onOpenChange: setMovingFolder }}
        creatingFolder={{ open: creatingFolder, parent: dragonBall, onOpenChange: setCreatingFolder }}
        folderActions={{
          currentFolder: () => dragonBall,
          createLibraryFolder: vi.fn(),
          moveCurrentFolder: vi.fn(),
        }}
        onMoveComics={vi.fn()}
        onSaveComic={vi.fn()}
        onShared={vi.fn()}
      />
    </>
  );
}

const openFolderShare = async (user) => {
  await user.click(screen.getByRole("button", { name: "Share folder" }));
  await screen.findByRole("heading", { name: /Share “DragonBall”/ });
};

/**
 * Two siblings keyed alike are one element to React, and the one it keeps is
 * not the one the state says should be there — so a share dialog keyed on the
 * folder it shares survived being closed while the move dialog held the same
 * key. Both ways out are asserted because a user who finds one shut reaches
 * for the other.
 */
describe("closing the folder share dialog", () => {
  beforeEach(() => {
    vi.mocked(api.get).mockResolvedValue({ recipients: [] });
  });

  it("closes on Cancel", async () => {
    const user = userEvent.setup();
    render(<LibraryWithDialogs />);
    await openFolderShare(user);

    await user.click(screen.getByRole("button", { name: "Cancel" }));

    expect(screen.queryByRole("heading", { name: /Share “DragonBall”/ })).toBeNull();
  });

  it("closes on the cross", async () => {
    const user = userEvent.setup();
    render(<LibraryWithDialogs />);
    await openFolderShare(user);

    await user.click(screen.getByRole("button", { name: "Close" }));

    expect(screen.queryByRole("heading", { name: /Share “DragonBall”/ })).toBeNull();
  });

  it("closes a comic share the same way", async () => {
    vi.mocked(api.get).mockImplementation((url) => Promise.resolve(
      url === "/api/shares/recent-recipients" ? { recipients: [] } : { comics: [] }
    ));
    const user = userEvent.setup();
    render(<LibraryWithDialogs />);
    await user.click(screen.getByRole("button", { name: "Share comic" }));
    await screen.findByRole("heading", { name: "Share comics" });

    await user.click(screen.getByRole("button", { name: "Cancel" }));

    expect(screen.queryByRole("heading", { name: "Share comics" })).toBeNull();
  });
});
