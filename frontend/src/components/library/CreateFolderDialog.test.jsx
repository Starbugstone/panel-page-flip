import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { CreateFolderDialog } from "./CreateFolderDialog";

describe("CreateFolderDialog", () => {
  it("creates a subfolder under the folder being viewed", async () => {
    const user = userEvent.setup();
    const onCreate = vi.fn().mockResolvedValue(true);
    const onOpenChange = vi.fn();

    render(
      <CreateFolderDialog
        open
        onOpenChange={onOpenChange}
        parentFolder={{ id: 7, name: "Manga" }}
        onCreate={onCreate}
      />
    );

    expect(screen.getByRole("dialog")).toHaveAccessibleDescription(/inside.*Manga/i);
    await user.type(screen.getByLabelText("Folder name"), "  Shonen  ");
    await user.click(screen.getByRole("button", { name: "Create subfolder" }));

    expect(onCreate).toHaveBeenCalledWith("Shonen", 7);
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });

  it("keeps the dialog open when creation is rejected", async () => {
    const user = userEvent.setup();
    const onOpenChange = vi.fn();

    render(
      <CreateFolderDialog
        open
        onOpenChange={onOpenChange}
        parentFolder={{ id: 7, name: "Manga" }}
        onCreate={vi.fn().mockResolvedValue(false)}
      />
    );

    await user.type(screen.getByLabelText("Folder name"), "Shonen");
    await user.click(screen.getByRole("button", { name: "Create subfolder" }));

    expect(onOpenChange).not.toHaveBeenCalled();
    expect(screen.getByLabelText("Folder name")).toHaveValue("Shonen");
  });
});
