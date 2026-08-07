import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import Sharing from "./Sharing";
import { api } from "@/lib/api";
import { EXPLICIT_GATE_TITLE, SHARING_PAGE_RESPONSIBILITY_REMINDER } from "@/lib/sharing";

const lists = { sharedByMe: [], sharedWithMe: [], isLoading: false, error: null, reload: vi.fn() };

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: vi.fn() }) }));
vi.mock("@/hooks/use-sharing", () => ({
  useSharing: () => ({ refreshSummary: vi.fn() }),
  useSharingLists: () => lists,
}));
vi.mock("@/hooks/use-comic-library", () => ({
  useComicLibrary: () => ({ loadLibrary: vi.fn() }),
}));

const receivedShare = (overrides = {}) => ({
  id: 11,
  status: "pending",
  comicId: null,
  comicTitle: null,
  comicAuthor: null,
  pageCount: null,
  coverImagePath: null,
  ownerName: "Alex Owner",
  explicitContent: true,
  requiresAdultConfirmation: true,
  adultConfirmed: false,
  isExpired: false,
  isTombstoned: false,
  isDead: false,
  canAnswer: true,
  canRead: false,
  canRemove: false,
  canRestore: false,
  removedFromCollection: null,
  tombstoneReason: null,
  ...overrides,
});

const renderPage = () => render(<MemoryRouter><Sharing /></MemoryRouter>);

/** Move to the owner's half of the page. */
const openSharedByMe = async (user) => {
  await user.click(screen.getByRole("tab", { name: /shared by me/i }));
};

describe("Sharing page", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    lists.sharedByMe = [];
    lists.sharedWithMe = [];
    lists.isLoading = false;
    lists.error = null;
  });

  it("reminds the sender that shared content is their responsibility", async () => {
    const user = userEvent.setup();
    lists.sharedByMe = [{
      comicId: 5,
      title: "Sandman",
      author: "Neil Gaiman",
      coverImagePath: null,
      explicitContent: false,
      recipients: [{ id: 1, recipientEmail: "jane@example.com", status: "pending" }],
    }];

    renderPage();
    await openSharedByMe(user);

    expect(screen.getByText(SHARING_PAGE_RESPONSIBILITY_REMINDER)).toBeInTheDocument();
  });

  it("shows the reminder even when nothing has been shared yet", async () => {
    const user = userEvent.setup();
    renderPage();
    await openSharedByMe(user);

    // The expectation is worth stating before the first share, not only after.
    expect(screen.getByText(SHARING_PAGE_RESPONSIBILITY_REMINDER)).toBeInTheDocument();
  });

  it("badges the owner's own explicit comics without hiding them", async () => {
    const user = userEvent.setup();
    lists.sharedByMe = [{
      comicId: 5,
      title: "Owner Can See This",
      author: "Someone",
      coverImagePath: null,
      explicitContent: true,
      recipients: [{ id: 1, recipientEmail: "jane@example.com", status: "pending" }],
    }];

    renderPage();
    await openSharedByMe(user);

    // An age gate protects a recipient from content they have not agreed to
    // see. It is not a lock on somebody's own library.
    expect(screen.getByText("Owner Can See This")).toBeInTheDocument();
    expect(screen.getByText("Explicit content (18+)")).toBeInTheDocument();
  });

  it("gates a pending explicit invitation instead of offering to accept it", () => {
    lists.sharedWithMe = [receivedShare()];
    renderPage();

    expect(screen.getByText("Hidden until you confirm your age")).toBeInTheDocument();
    expect(screen.getAllByText(EXPLICIT_GATE_TITLE).length).toBeGreaterThan(0);
    expect(screen.getByRole("button", { name: /i am 18 or older/i })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /add to my collection/i })).not.toBeInTheDocument();
    // Declining never needs an age declaration.
    expect(screen.getByRole("button", { name: /decline/i })).toBeInTheDocument();
  });

  it("gates an accepted share the owner has since marked explicit", () => {
    lists.sharedWithMe = [receivedShare({ status: "accepted", canAnswer: false, canRemove: true })];
    renderPage();

    // The relationship survived; reading did not, until they confirm again.
    expect(screen.getByText(/confirm your age to read it again/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /i am 18 or older/i })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^read$/i })).not.toBeInTheDocument();
  });

  it("records the declaration against that share and reloads", async () => {
    const user = userEvent.setup();
    lists.sharedWithMe = [receivedShare({ id: 42 })];
    vi.mocked(api.post).mockResolvedValue({ share: {} });

    renderPage();
    await user.click(screen.getByRole("button", { name: /i am 18 or older/i }));

    expect(api.post).toHaveBeenCalledWith("/api/shares/42/confirm-adult", { adultConfirmed: true });
    await waitFor(() => expect(lists.reload).toHaveBeenCalled());
  });

  it("explains a dead explicit share rather than offering a gate to nowhere", () => {
    lists.sharedWithMe = [receivedShare({ status: "revoked", isDead: true, canAnswer: false })];
    renderPage();

    expect(screen.getByText(/the owner has stopped sharing/i)).toBeInTheDocument();
    // Confirming for a share the owner has withdrawn achieves nothing.
    expect(screen.queryByRole("button", { name: /i am 18 or older/i })).not.toBeInTheDocument();
    // It stays redacted: the recipient never passed the gate, and the share
    // ending is not the same as them having done so.
    expect(screen.getByText("Hidden — explicit content (18+)")).toBeInTheDocument();
  });

  it("leaves a non-explicit share entirely alone", () => {
    lists.sharedWithMe = [receivedShare({
      comicId: 5,
      comicTitle: "Ordinary Comic",
      explicitContent: false,
      requiresAdultConfirmation: false,
    })];

    renderPage();

    const card = screen.getByText("Ordinary Comic").closest("li");
    expect(within(card).getByRole("button", { name: /add to my collection/i })).toBeInTheDocument();
    expect(screen.queryByText(EXPLICIT_GATE_TITLE)).not.toBeInTheDocument();
  });
});
