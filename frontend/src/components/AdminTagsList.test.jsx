import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AdminTagsList } from "./AdminTagsList";
import { api } from "@/lib/api";

const { toast } = vi.hoisted(() => ({ toast: vi.fn() }));

vi.mock("@/lib/api", () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast }) }));

describe("the admin tags list", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(api.get).mockResolvedValue({
      tags: [{
        id: 3,
        name: "Fantasy",
        isGlobal: true,
        hideFromLibrary: false,
        comicCount: 4,
        creator: null,
        createdAt: "2026-08-01T10:00:00Z",
      }],
      pagination: { page: 1, limit: 25, totalItems: 1, totalPages: 1 },
    });
  });

  it("gives the edit and delete icon buttons accessible names and titles", async () => {
    render(<MemoryRouter><AdminTagsList /></MemoryRouter>);

    const row = within(await screen.findByRole("row", { name: /Fantasy/ }));
    expect(row.getByRole("button", { name: "Edit Fantasy" })).toHaveAttribute("title", "Edit tag");
    expect(row.getByRole("button", { name: "Delete Fantasy" })).toHaveAttribute("title", "Delete tag");
  });
});

describe("the admin tags list in bulk", () => {
  const tag = (id, name, comicCount) => ({
    id,
    name,
    isGlobal: true,
    hideFromLibrary: false,
    comicCount,
    creator: null,
    createdAt: "2026-08-01T10:00:00Z",
  });

  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(api.get).mockResolvedValue({
      tags: [tag(3, "Fantasy", 4), tag(4, "Horror", 2), tag(5, "Western", 1)],
      pagination: { page: 1, limit: 25, totalItems: 3, totalPages: 1 },
    });
  });

  const box = (name) => screen.getByRole("checkbox", { name: `Select ${name}` });

  it("deletes every ticked tag through the endpoint the row button uses", async () => {
    const user = userEvent.setup();
    vi.mocked(api.delete).mockResolvedValue({});
    render(<MemoryRouter><AdminTagsList /></MemoryRouter>);
    await screen.findByRole("row", { name: /Fantasy/ });

    await user.click(box("Fantasy"));
    await user.keyboard("{Shift>}");
    await user.click(box("Western"));
    await user.keyboard("{/Shift}");

    expect(screen.getByText("3 of 3 tags selected")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /Delete selected/ }));
    // The comics losing a tag are the part of this that cannot be undone, so
    // the total is stated before the confirmation is given.
    expect(screen.getByText(/removed from\s+the 7 comic\(s\)/)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Delete" }));

    await waitFor(() => expect(api.delete).toHaveBeenCalledTimes(3));
    expect(api.delete.mock.calls.map(([path]) => path))
      .toEqual(["/api/tags/3", "/api/tags/4", "/api/tags/5"]);
    expect(toast).toHaveBeenCalledWith({ title: "3 tags deleted" });
  });

  /** Renaming and hiding are one tag's own business; only deletion is offered. */
  it("offers deletion and nothing else", async () => {
    const user = userEvent.setup();
    render(<MemoryRouter><AdminTagsList /></MemoryRouter>);
    await screen.findByRole("row", { name: /Fantasy/ });

    await user.click(box("Fantasy"));

    expect(screen.getByRole("button", { name: /Delete selected/ })).toBeEnabled();
    expect(screen.queryByRole("button", { name: /Warn selected/ })).not.toBeInTheDocument();
  });
});

