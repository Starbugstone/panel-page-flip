import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import Sharing from "./Sharing";
import { api } from "@/lib/api";
import { EXPLICIT_GATE_TITLE, SHARING_PAGE_RESPONSIBILITY_REMINDER } from "@/lib/sharing";

const emptyPagination = () => ({ page: 1, limit: 25, totalItems: 0, totalPages: 1 });
const lists = {
  sharedByMe: [],
  sharedWithMe: [],
  byMePagination: emptyPagination(),
  byMeListKey: "sharing-list-1",
  byMeIsLoading: false,
  byMeSearchInput: "",
  isLoading: false,
  error: null,
  reload: vi.fn(),
  setByMeSearchInput: vi.fn(),
  setByMePage: vi.fn(),
  setByMeLimit: vi.fn(),
};

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } }));

/**
 * The page asks for the account's own sharing code as soon as it mounts, and
 * the picker asks for the library. Neither is what most of these tests are
 * about, so they answer emptily unless a test says otherwise.
 */
const stubGets = (overrides = {}) => {
  vi.mocked(api.get).mockImplementation((url) => {
    if (url in overrides) return Promise.resolve(overrides[url]);
    if (url === "/api/shares/user-code") {
      return Promise.resolve({ name: "Test Reader", username: "TestReader1234", label: "Test Reader (@TestReader1234)", userCode: "U-AAAA-BBBB-CCCC" });
    }
    if (url === "/api/shares/content-codes") return Promise.resolve({ codes: [] });
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

const ownerShare = (overrides = {}) => ({
  id: 1,
  comicId: 5,
  comicTitle: "Sandman",
  comicAuthor: "Neil Gaiman",
  coverImagePath: null,
  explicitContent: false,
  recipientEmail: "jane@example.com",
  recipientLabel: "jane@example.com",
  recipientName: null,
  recipientUsername: null,
  recipientUserCode: null,
  status: "pending",
  createdAt: "2026-08-01T12:00:00+00:00",
  canResend: true,
  canRevoke: true,
  canDelete: false,
  ...overrides,
});

const renderPage = () => render(<MemoryRouter><Sharing /></MemoryRouter>);
const settleSharingCard = () => screen.findByText("U-AAAA-BBBB-CCCC");

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
    lists.byMePagination = emptyPagination();
    lists.byMeListKey = "sharing-list-1";
    lists.byMeIsLoading = false;
    lists.byMeSearchInput = "";
    lists.isLoading = false;
    lists.error = null;
  });

  it("describes both claiming a code and accepting a direct invitation", async () => {
    const user = userEvent.setup();
    renderPage();

    await openSharedByMe(user);

    expect(screen.getByText(/Recipients must claim a code or accept an invitation before reading/))
      .toBeInTheDocument();
  });

  it("reminds the sender that shared content is their responsibility", async () => {
    const user = userEvent.setup();
    lists.sharedByMe = [ownerShare()];

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
    lists.sharedByMe = [ownerShare({
      comicTitle: "Owner Can See This",
      comicAuthor: "Someone",
      explicitContent: true,
    })];

    renderPage();
    await openSharedByMe(user);

    // An age gate protects a recipient from content they have not agreed to
    // see. It is not a lock on somebody's own library.
    expect(screen.getByText("Owner Can See This")).toBeInTheDocument();
    expect(screen.getByText("Explicit content (18+)")).toBeInTheDocument();
  });

  it("gates a pending explicit invitation instead of offering to accept it", async () => {
    lists.sharedWithMe = [receivedShare()];
    renderPage();
    await settleSharingCard();

    expect(screen.getByText("Hidden until you confirm your age")).toBeInTheDocument();
    expect(screen.getAllByText(EXPLICIT_GATE_TITLE).length).toBeGreaterThan(0);
    expect(screen.getByRole("button", { name: /i am 18 or older/i })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /add to my collection/i })).not.toBeInTheDocument();
    // Declining never needs an age declaration.
    expect(screen.getByRole("button", { name: /decline/i })).toBeInTheDocument();
  });

  it("shows a folder batch as one invitation and accepts it once", async () => {
    const user = userEvent.setup();
    const batch = {
      invitationBatchId: "batch-123",
      invitationBatchName: "DragonBall",
      invitationBatchSize: 2,
      explicitContent: false,
      requiresAdultConfirmation: false,
      comicId: 1,
      comicTitle: "Volume 1",
    };
    lists.sharedWithMe = [
      receivedShare({ ...batch, id: 41 }),
      receivedShare({ ...batch, id: 42, comicId: 2, comicTitle: "Volume 2" }),
    ];
    vi.mocked(api.post).mockResolvedValue({ acceptedCount: 2 });

    renderPage();

    expect(screen.getByText("DragonBall")).toBeInTheDocument();
    expect(screen.getByText(/share 2 comics with you/i)).toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: /add all to my collection/i })).toHaveLength(1);
    expect(screen.getAllByRole("button", { name: /decline all/i })).toHaveLength(1);

    await user.click(screen.getByRole("button", { name: /add all to my collection/i }));
    expect(api.post).toHaveBeenCalledTimes(1);
    expect(api.post).toHaveBeenCalledWith("/api/shares/41/accept", {});
  });

  it("gates an accepted share the owner has since marked explicit", async () => {
    lists.sharedWithMe = [receivedShare({ status: "accepted", canAnswer: false, canRemove: true })];
    renderPage();
    await settleSharingCard();

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

  it("explains a dead explicit share rather than offering a gate to nowhere", async () => {
    lists.sharedWithMe = [receivedShare({ status: "revoked", isDead: true, canAnswer: false })];
    renderPage();
    await settleSharingCard();

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
    lists.sharedByMe = [ownerShare({ status: "accepted", canResend: false })];
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
    lists.sharedByMe = [ownerShare({
      // What the server sends for somebody reached by their code: no address,
      // because withholding it was the whole point.
      recipientEmail: null,
      recipientLabel: "Jane Reader",
      recipientUserCode: "U-7RFX-KP3M-Q82D",
      status: "accepted",
      canResend: false,
    })];

    renderPage();
    await openSharedByMe(user);

    expect(screen.getByText("Jane Reader")).toBeInTheDocument();
    expect(screen.getByText("User code U-7RFX-KP3M-Q82D")).toBeInTheDocument();

    // Sharing again reaches them the same way, by code rather than by address.
    await user.click(screen.getByRole("button", { name: /share another comic with jane reader/i }));
    expect(await screen.findByLabelText(/their u- code/i)).toHaveValue("U-7RFX-KP3M-Q82D");
    expect(screen.queryByLabelText(/recipient email/i)).not.toBeInTheDocument();
  });

  it("offers delete only on a share that is already over, and deletes its record", async () => {
    const user = userEvent.setup();
    lists.sharedByMe = [
      ownerShare({ id: 1, status: "revoked", canResend: false, canRevoke: false, canDelete: true }),
      ownerShare({
        id: 2,
        recipientEmail: "bob@example.com",
        recipientLabel: "bob@example.com",
        status: "accepted",
        canResend: false,
      }),
    ];
    lists.byMePagination = { ...emptyPagination(), totalItems: 2 };
    vi.mocked(api.delete).mockResolvedValue({ message: "Share record deleted." });

    renderPage();
    await openSharedByMe(user);

    // A live share offers Revoke; deleting its record is not a quieter way to
    // cut somebody off.
    expect(screen.queryByRole("button", { name: /delete the share record for bob@example.com/i }))
      .not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /delete the share record for jane@example.com/i }));

    expect(api.delete).toHaveBeenCalledWith("/api/shares/1");
    await waitFor(() => expect(lists.reload).toHaveBeenCalled());
  });

  it("pages the shared-by-me list the way the admin tables do", async () => {
    const user = userEvent.setup();
    lists.sharedByMe = [ownerShare()];
    lists.byMePagination = { page: 1, limit: 25, totalItems: 30, totalPages: 2 };

    renderPage();

    // The tab counts everything shared, not just the page on screen.
    expect(screen.getByRole("tab", { name: "Shared by me (30)" })).toBeInTheDocument();

    await openSharedByMe(user);
    expect(screen.getByText("Showing 1–1 of 30 shares")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /next page/i }));
    expect(lists.setByMePage).toHaveBeenCalledWith(2);
  });

  it("shows no pager before anything has been shared", async () => {
    const user = userEvent.setup();
    renderPage();
    await openSharedByMe(user);

    expect(screen.queryByRole("button", { name: /next page/i })).not.toBeInTheDocument();
  });

  it("leaves a non-explicit share entirely alone", async () => {
    lists.sharedWithMe = [receivedShare({
      comicId: 5,
      comicTitle: "Ordinary Comic",
      explicitContent: false,
      requiresAdultConfirmation: false,
    })];

    renderPage();
    await settleSharingCard();

    const card = screen.getByText("Ordinary Comic").closest("li");
    expect(within(card).getByRole("button", { name: /add to my collection/i })).toBeInTheDocument();
    expect(screen.queryByText(EXPLICIT_GATE_TITLE)).not.toBeInTheDocument();
  });
});
