import { render, screen, within } from "@testing-library/react";
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
