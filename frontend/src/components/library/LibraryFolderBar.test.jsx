import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { LibraryFolderBar } from "./LibraryFolderBar";

const actions = () => ({
  onNavigate: vi.fn(),
  onCreate: vi.fn(),
  onShare: vi.fn(),
  onMove: vi.fn(),
  onRename: vi.fn(),
  onDelete: vi.fn(),
});

describe("LibraryFolderBar", () => {
  it("offers a clearly named subfolder action inside a folder", async () => {
    const user = userEvent.setup();
    const props = actions();

    render(<LibraryFolderBar folders={[{ id: 7, name: "Manga", parentId: null }]} activeFolderId={7} {...props} />);
    await user.click(screen.getByRole("button", { name: "New subfolder" }));

    expect(props.onCreate).toHaveBeenCalledOnce();
  });

  it("calls the same action a new folder at the library root", () => {
    render(<LibraryFolderBar folders={[]} activeFolderId={null} {...actions()} />);

    expect(screen.getByRole("button", { name: "New folder" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Move" })).not.toBeInTheDocument();
  });

  it("offers the whole folder as one thing to share", async () => {
    const user = userEvent.setup();
    const props = actions();

    render(<LibraryFolderBar folders={[{ id: 7, name: "DragonBall", parentId: null }]} activeFolderId={7} {...props} />);
    await user.click(screen.getByRole("button", { name: "Share folder" }));

    expect(props.onShare).toHaveBeenCalledOnce();
  });

  it("has no folder to share at the library root", () => {
    render(<LibraryFolderBar folders={[]} activeFolderId={null} {...actions()} />);

    expect(screen.queryByRole("button", { name: "Share folder" })).not.toBeInTheDocument();
  });

  it("offers the jump to the last-read comic when given one", async () => {
    const user = userEvent.setup();
    const onJumpToLastRead = vi.fn();

    render(<LibraryFolderBar folders={[]} activeFolderId={null} {...actions()} onJumpToLastRead={onJumpToLastRead} />);
    await user.click(screen.getByRole("button", { name: "Last read" }));

    expect(onJumpToLastRead).toHaveBeenCalledOnce();
  });

  it("hides the jump when there is nowhere to jump to", () => {
    render(<LibraryFolderBar folders={[]} activeFolderId={null} {...actions()} onJumpToLastRead={null} />);

    expect(screen.queryByRole("button", { name: "Last read" })).not.toBeInTheDocument();
  });
});
