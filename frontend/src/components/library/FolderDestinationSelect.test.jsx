import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { FolderDestinationSelect } from "./FolderDestinationSelect";

const mocks = vi.hoisted(() => ({ toast: vi.fn() }));

vi.mock("@/hooks/use-toast", () => ({
  useToast: () => ({ toast: mocks.toast }),
}));

describe("FolderDestinationSelect", () => {
  it("creates a top-level folder and selects it as the destination", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    const onCreateFolder = vi.fn().mockResolvedValue({ id: 9, name: "New arrivals", parentId: null });

    render(
      <FolderDestinationSelect
        folders={[]}
        value={null}
        onChange={onChange}
        onCreateFolder={onCreateFolder}
      />
    );

    await user.click(screen.getByRole("button", { name: "New folder" }));
    await user.type(screen.getByLabelText("Folder name"), "  New arrivals  ");
    await user.click(screen.getByRole("button", { name: "Create folder" }));

    expect(onCreateFolder).toHaveBeenCalledWith("New arrivals", null);
    expect(onChange).toHaveBeenCalledWith(9);
    expect(mocks.toast).toHaveBeenCalledWith(expect.objectContaining({ title: "Folder created" }));
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
  });

  it("creates a subfolder beneath the selected destination", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    const onCreateFolder = vi.fn().mockResolvedValue({ id: 12, name: "Shonen", parentId: 7 });

    render(
      <FolderDestinationSelect
        folders={[{ id: 7, name: "Manga", parentId: null }]}
        value={7}
        onChange={onChange}
        onCreateFolder={onCreateFolder}
      />
    );

    await user.click(screen.getByRole("button", { name: "New subfolder" }));
    expect(screen.getByRole("dialog")).toHaveAccessibleDescription(/inside.*Manga/i);
    await user.type(screen.getByLabelText("Folder name"), "Shonen");
    await user.click(screen.getByRole("button", { name: "Create subfolder" }));

    expect(onCreateFolder).toHaveBeenCalledWith("Shonen", 7);
    expect(onChange).toHaveBeenCalledWith(12);
  });

  it("keeps the dialog open and reports a failed creation", async () => {
    const user = userEvent.setup();
    const onCreateFolder = vi.fn().mockRejectedValue(new Error("That name is already used"));

    render(
      <FolderDestinationSelect
        folders={[]}
        value={null}
        onChange={vi.fn()}
        onCreateFolder={onCreateFolder}
      />
    );

    await user.click(screen.getByRole("button", { name: "New folder" }));
    await user.type(screen.getByLabelText("Folder name"), "Duplicates");
    await user.click(screen.getByRole("button", { name: "Create folder" }));

    expect(screen.getByRole("dialog")).toBeInTheDocument();
    expect(mocks.toast).toHaveBeenCalledWith(expect.objectContaining({
      title: "Could not create folder",
      description: "That name is already used",
      variant: "destructive",
    }));
  });
});
