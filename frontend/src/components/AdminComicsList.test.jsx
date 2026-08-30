import { render, screen, waitFor } from "@testing-library/react";
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

  it("warns that deletion is permanent and ends active shares", async () => {
    const user = userEvent.setup();
    stubList([comic()]);
    renderList();

    await user.click(await screen.findByRole("button", { name: "Delete Sandman #1" }));

    expect(screen.getByRole("alertdialog")).toHaveTextContent(
      /permanently deletes the comic and its files.*active shares.*end/i
    );
  });
});

describe("the admin comics list in bulk", () => {
  const LIBRARY = [
    comic({ id: 11, title: "Sandman #1" }),
    comic({ id: 12, title: "Sandman #2" }),
    comic({ id: 13, title: "Preacher #1", owner: { id: 3, name: "Sam Owner", email: "sam@example.com" } }),
  ];

  beforeEach(() => vi.clearAllMocks());

  const box = (title) => screen.getByRole("checkbox", { name: `Select ${title}` });

  it("deletes every ticked comic through the endpoint the row button uses", async () => {
    const user = userEvent.setup();
    stubList(LIBRARY);
    vi.mocked(api.delete).mockResolvedValue({});
    renderList();
    await screen.findByText("Sandman #1");

    await user.click(box("Sandman #1"));
    await user.click(box("Sandman #2"));
    await user.click(screen.getByRole("button", { name: /Delete selected/ }));
    await user.click(screen.getByRole("button", { name: "Delete" }));

    await waitFor(() => expect(api.delete).toHaveBeenCalledTimes(2));
    expect(api.delete.mock.calls.map(([path]) => path)).toEqual(["/api/comics/11", "/api/comics/12"]);
    expect(mocks.toast).toHaveBeenCalledWith({ title: "2 comics deleted" });
  });

  it("shift-clicking takes every comic between the two clicks", async () => {
    const user = userEvent.setup();
    stubList(LIBRARY);
    renderList();
    await screen.findByText("Sandman #1");

    await user.click(box("Sandman #1"));
    await user.keyboard("{Shift>}");
    await user.click(box("Preacher #1"));
    await user.keyboard("{/Shift}");

    expect(screen.getByText("3 of 3 comics selected")).toBeInTheDocument();
  });

  /**
   * One message, sent once per comic, so the owner of two of them hears about
   * both. Naming the recipients by owner rather than by comic keeps the
   * heading honest when a selection spans several libraries.
   */
  it("warns the owner of each ticked comic", async () => {
    const user = userEvent.setup();
    stubList(LIBRARY);
    vi.mocked(api.post).mockResolvedValue({ message: "Warning sent." });
    renderList();
    await screen.findByText("Sandman #1");

    await user.click(screen.getByRole("checkbox", { name: "Select all comics" }));
    await user.click(screen.getByRole("button", { name: /Warn owners/ }));

    expect(screen.getByRole("heading", { name: "Warn about 3 comics" })).toBeInTheDocument();
    expect(screen.getByText(/2 owners will see this/)).toBeInTheDocument();

    await user.type(screen.getByLabelText("Message"), "Take these down.");
    await user.click(screen.getByRole("button", { name: "Send 3 warnings" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(3));
    expect(api.post.mock.calls.map(([, body]) => body.comicId)).toEqual([11, 12, 13]);
    expect(mocks.toast).toHaveBeenCalledWith({ title: "3 warnings sent" });
  });
});
