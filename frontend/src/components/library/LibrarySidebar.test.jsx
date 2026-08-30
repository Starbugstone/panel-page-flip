import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { LibrarySidebar } from "./LibrarySidebar";

vi.mock("@/hooks/use-storage-usage", () => ({
  useStorageUsage: () => ({ usage: null }),
}));

const folders = [{ id: 7, name: "Manga", parentId: null }];

const renderSidebar = (props = {}) => {
  const onCreateFolder = vi.fn();
  render(
    <LibrarySidebar
      folders={folders}
      activeFolderId={null}
      activeView="all"
      onFolderSelect={vi.fn()}
      onViewSelect={vi.fn()}
      onCreateFolder={onCreateFolder}
      {...props}
    />
  );
  return { onCreateFolder };
};

describe("LibrarySidebar folder creation", () => {
  it("creates a root folder when the library root is active", async () => {
    const user = userEvent.setup();
    const { onCreateFolder } = renderSidebar();
    onCreateFolder.mockResolvedValue(true);

    await user.click(screen.getByRole("button", { name: "Create folder" }));
    await user.type(screen.getByLabelText("Folder name"), "  New arrivals  ");
    await user.click(screen.getByRole("button", { name: "Add" }));

    expect(onCreateFolder).toHaveBeenCalledWith("New arrivals", null);
    expect(screen.queryByLabelText("Folder name")).not.toBeInTheDocument();
  });

  it("passes the open folder as the parent of a subfolder", async () => {
    const user = userEvent.setup();
    const { onCreateFolder } = renderSidebar({ activeFolderId: 7, activeView: "folders" });
    onCreateFolder.mockResolvedValue(true);

    await user.click(screen.getByRole("button", { name: "Create subfolder in Manga" }));
    await user.type(screen.getByLabelText("Folder name"), "Shonen");
    await user.click(screen.getByRole("button", { name: "Add" }));

    expect(onCreateFolder).toHaveBeenCalledWith("Shonen", 7);
  });

  it("keeps the typed name when creation is rejected", async () => {
    const user = userEvent.setup();
    const { onCreateFolder } = renderSidebar();
    onCreateFolder.mockResolvedValue(false);

    await user.click(screen.getByRole("button", { name: "Create folder" }));
    await user.type(screen.getByLabelText("Folder name"), "Duplicates");
    await user.click(screen.getByRole("button", { name: "Add" }));

    expect(screen.getByLabelText("Folder name")).toHaveValue("Duplicates");
  });
});
