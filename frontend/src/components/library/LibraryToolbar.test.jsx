import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";
import { LibraryToolbar } from "./LibraryToolbar";

const renderToolbar = (props = {}) => {
  const onSortChange = vi.fn();
  render(
    <MemoryRouter>
      <LibraryToolbar
        isRefreshing={false}
        sort="title-asc"
        onSortChange={onSortChange}
        viewMode="grid"
        onViewModeChange={vi.fn()}
        onOpenSidebar={vi.fn()}
        uploadUrl="/upload"
        {...props}
      />
    </MemoryRouter>
  );

  return { onSortChange };
};

describe("LibraryToolbar", () => {
  it("offers reading recency as a way to sort", async () => {
    const user = userEvent.setup();
    const { onSortChange } = renderToolbar();

    await user.selectOptions(screen.getByRole("combobox", { name: "Sort comics" }), "Recently read");

    expect(onSortChange).toHaveBeenCalledWith("last-read-desc");
  });

  it("shows the sort it was given as the current choice", () => {
    renderToolbar({ sort: "last-read-desc" });

    expect(screen.getByRole("combobox", { name: "Sort comics" })).toHaveValue("last-read-desc");
  });

  it("gives every library control enough room on narrow screens", () => {
    renderToolbar();

    const sort = screen.getByRole("combobox", { name: "Sort comics" });
    const controls = sort.parentElement;
    expect(controls).toHaveClass("grid", "grid-cols-2", "sm:flex");
    expect(sort).toHaveClass("w-full", "sm:w-auto");
    expect(screen.getByRole("group", { name: "Library view" })).toHaveClass(
      "col-span-2",
      "grid",
      "grid-cols-2",
      "sm:col-span-1",
      "sm:flex",
    );
    expect(screen.getByRole("link", { name: /upload/i })).toHaveClass(
      "col-span-2",
      "w-full",
      "sm:col-span-1",
      "sm:w-auto",
    );
    expect(screen.getByRole("heading", { level: 1, name: "My Comic Library" })).toHaveClass("page-title");
  });
});
