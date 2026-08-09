import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import Sharing from "./Sharing";
import { api } from "@/lib/api";
import { EXPLICIT_GATE_TITLE, SHARING_PAGE_RESPONSIBILITY_REMINDER } from "@/lib/sharing";

const lists = { sharedByMe: [], sharedWithMe: [], isLoading: false, error: null, reload: vi.fn() };

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } }));

/**
 * The page asks for the account's own sharing code as soon as it mounts, and
 * the picker asks for the library. Neither is what most of these tests are
 * about, so they answer emptily unless a test says otherwise.
 */
const stubGets = (overrides = {}) => {
  vi.mocked(api.get).mockImplementation((url) => {
    if (url in overrides) return Promise.resolve(overrides[url]);
    if (url === "/api/shares/my-code") {
      return Promise.resolve({ name: "Test Reader", sharingCode: "AAAA-BBBB-CCCC" });
    }
    if (url === "/api/comics?ownership=mine") return Promise.resolve({ comics: [] });
    if (url === "/api/shares/recent-recipients") return Promise.resolve({ recipients: [] });
    return Promise.reject(new Error(`Unexpected GET ${url}`));
  });
};
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
    stubGets();
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

  it("offers to start a share without sending anyone back to their library", async () => {
    const user = userEvent.setup();
    renderPage();

    // On the header, so it is there before anything has ever been shared…
    expect(screen.getByRole("button", { name: /^share comics$/i })).toBeInTheDocument();

    // …and again in the empty state, which used to dead-end into /dashboard.
    await openSharedByMe(user);
    expect(screen.getAllByRole("button", { name: /^share comics$/i })).toHaveLength(2);
    expect(screen.queryByRole("link", { name: /your library/i })).not.toBeInTheDocument();
  });

  it("preselects the recipient when sharing another comic with someone", async () => {
    const user = userEvent.setup();
    lists.sharedByMe = [{
      comicId: 5,
      title: "Sandman",
      author: "Neil Gaiman",
      coverImagePath: null,
      explicitContent: false,
      recipients: [{ id: 1, recipientEmail: "jane@example.com", status: "accepted" }],
    }];
    renderPage();
    await openSharedByMe(user);
    await user.click(screen.getByRole("button", { name: /share another comic with jane@example.com/i }));

    const email = await screen.findByLabelText(/recipient email/i);
    expect(email).toHaveValue("jane@example.com");
    // The picker asks for the caller's own comics and their own share history —
    // never for a list of registered users.
    expect(api.get).toHaveBeenCalledWith("/api/comics?ownership=mine");
    expect(api.get).toHaveBeenCalledWith("/api/shares/recent-recipients");
    expect(vi.mocked(api.get).mock.calls.every(([url]) => !url.startsWith("/api/users"))).toBe(true);
  });

  it("names a code recipient instead of showing an address the sender never had", async () => {
    const user = userEvent.setup();
    lists.sharedByMe = [{
      comicId: 5,
      title: "Sandman",
      author: "Neil Gaiman",
      coverImagePath: null,
      explicitContent: false,
      recipients: [{
        id: 1,
        // What the server sends for somebody reached by their code: no address,
        // because withholding it was the whole point.
        recipientEmail: null,
        recipientLabel: "Jane Reader",
        recipientSharingCode: "7RFX-KP3M-Q82D",
        status: "accepted",
      }],
    }];

    renderPage();
    await openSharedByMe(user);

    expect(screen.getByText("Jane Reader")).toBeInTheDocument();
    expect(screen.getByText("Sharing code 7RFX-KP3M-Q82D")).toBeInTheDocument();

    // Sharing again reaches them the same way, by code rather than by address.
    await user.click(screen.getByRole("button", { name: /share another comic with jane reader/i }));
    expect(await screen.findByLabelText(/their sharing code/i)).toHaveValue("7RFX-KP3M-Q82D");
    expect(screen.queryByLabelText(/recipient email/i)).not.toBeInTheDocument();
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
