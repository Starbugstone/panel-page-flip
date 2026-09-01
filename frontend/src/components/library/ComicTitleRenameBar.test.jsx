import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { ComicTitleRenameBar } from "./ComicTitleRenameBar";

describe("ComicTitleRenameBar", () => {
  it("keeps preview acceptance and undo together in a sticky status bar", async () => {
    const user = userEvent.setup();
    const onAccept = vi.fn();
    const onUndo = vi.fn();

    render(<ComicTitleRenameBar session={{ phase: "preview", count: 4 }} onAccept={onAccept} onUndo={onUndo} />);

    const status = screen.getByRole("status");
    expect(status).toHaveClass("sticky");
    expect(status).toHaveTextContent("4 comic titles");
    await user.click(screen.getByRole("button", { name: "Undo preview" }));
    await user.click(screen.getByRole("button", { name: "Accept rename" }));
    expect(onUndo).toHaveBeenCalledOnce();
    expect(onAccept).toHaveBeenCalledOnce();
  });

  it("keeps a persisted rename undoable until the folder is left", async () => {
    const user = userEvent.setup();
    const onUndo = vi.fn();

    render(<ComicTitleRenameBar session={{ phase: "accepted", count: 1 }} onAccept={vi.fn()} onUndo={onUndo} />);

    expect(screen.queryByRole("button", { name: "Accept rename" })).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Undo rename" }));
    expect(onUndo).toHaveBeenCalledOnce();
  });
});
