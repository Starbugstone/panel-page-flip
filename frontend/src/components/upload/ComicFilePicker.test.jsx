import { fireEvent, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { ComicFilePicker } from "./ComicFilePicker";

describe("comic file selection", () => {
  it("uses a native button for keyboard selection and accepts configured formats", async () => {
    render(<ComicFilePicker formats={["cbz", "pdf"]} onFiles={vi.fn()} />);
    const input = screen.getByLabelText("Comic files");
    expect(input).toHaveAttribute("accept", expect.stringContaining(".cbz"));
    expect(input).toHaveAttribute("accept", expect.stringContaining(".pdf"));
    const click = vi.spyOn(input, "click").mockImplementation(() => {});
    screen.getByRole("button", { name: "Choose file" }).focus();
    await userEvent.keyboard("{Enter}");
    expect(click).toHaveBeenCalledTimes(1);
    click.mockRestore();
  });

  it("allows choosing the same file again after removing it", async () => {
    const onFiles = vi.fn();
    render(<ComicFilePicker formats={["cbz"]} onFiles={onFiles} />);
    const file = new File(["comic"], "issue.cbz", { type: "application/zip" });
    const input = screen.getByLabelText("Comic files");
    await userEvent.upload(input, file);
    await userEvent.upload(input, file);
    expect(onFiles).toHaveBeenCalledTimes(2);
    expect(onFiles).toHaveBeenLastCalledWith([file]);
  });

  it("accepts dropped files and prevents the browser opening them", () => {
    const onFiles = vi.fn();
    render(<ComicFilePicker multiple formats={["cbz"]} onFiles={onFiles} />);
    const file = new File(["comic"], "issue.cbz");
    const zone = screen.getByRole("group", { name: "Comic file selection" });
    fireEvent.dragEnter(zone);
    expect(zone).toHaveAttribute("data-dragging", "true");
    expect(fireEvent.drop(zone, { dataTransfer: { files: [file] } })).toBe(false);
    expect(zone).toHaveAttribute("data-dragging", "false");
    expect(onFiles).toHaveBeenCalledWith([file]);
  });

  it("blocks selection and drops while an upload is running", () => {
    const onFiles = vi.fn();
    render(<ComicFilePicker multiple disabled formats={["cbz"]} onFiles={onFiles} />);
    fireEvent.drop(screen.getByRole("group"), { dataTransfer: { files: [new File(["x"], "a.cbz")] } });
    expect(screen.getByRole("button", { name: "Choose files" })).toBeDisabled();
    expect(onFiles).not.toHaveBeenCalled();
  });
});
