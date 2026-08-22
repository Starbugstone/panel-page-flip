import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AdminComicsList } from "./AdminComicsList";
import { api } from "@/lib/api";

const mocks = vi.hoisted(() => ({ toast: vi.fn(), navigate: vi.fn() }));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: mocks.toast }) }));
vi.mock("react-router-dom", async (importOriginal) => ({
  ...(await importOriginal()),
  useNavigate: () => mocks.navigate,
}));
// The edit dialog is rendered alongside the table and reaches for tag context
// this list never provides. It is a different component's subject.
vi.mock("@/components/ComicEditDialog", () => ({ ComicEditDialog: () => null }));

const comic = (overrides = {}) => ({
  id: 11,
  title: "Sandman #1",
  author: "N. Gaiman",
  uploadedAt: "2026-07-01T10:00:00+00:00",
  pageCount: 32,
  tags: [],
  explicitContent: false,
  owner: { id: 2, name: "Jo Owner", email: "jo@example.com" },
  ...overrides,
});

const stubList = (comics) => {
  vi.mocked(api.get).mockResolvedValue({
    comics,
    pagination: { page: 1, limit: 25, totalItems: comics.length, totalPages: 1 },
  });
};

const renderList = () => render(<MemoryRouter><AdminComicsList /></MemoryRouter>);

describe("the admin comics list", () => {
  beforeEach(() => vi.clearAllMocks());

  /**
   * An administrator checking whether a comic is classified correctly should
   * not have to open the edit dialog on every row to find out.
   */
  it("labels a comic marked 18+", async () => {
    stubList([comic({ explicitContent: true })]);
    renderList();

    await screen.findByText("Sandman #1");
    expect(screen.getByText("18+")).toBeInTheDocument();
  });

  /** Absence says it; a "not 18+" badge on every other row would be noise. */
  it("says nothing about a comic that is not", async () => {
    stubList([comic()]);
    renderList();

    await screen.findByText("Sandman #1");
    expect(screen.queryByText("18+")).not.toBeInTheDocument();
  });

  it("opens the comic in the reader so it can actually be checked", async () => {
    const user = userEvent.setup();
    stubList([comic()]);
    renderList();

    await user.click(await screen.findByRole("button", { name: "Read Sandman #1" }));

    expect(mocks.navigate).toHaveBeenCalledWith("/read/11");
  });

  it("warns the owner about a specific comic", async () => {
    const user = userEvent.setup();
    stubList([comic()]);
    renderList();

    await user.click(await screen.findByRole("button", { name: /warn the owner about Sandman #1/i }));

    expect(await screen.findByText("Warn about Sandman #1")).toBeInTheDocument();
    expect(screen.getByText(/Jo Owner will see this the next time they sign in/)).toBeInTheDocument();
  });
});
