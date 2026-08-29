import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import ShareInvitation from "./ShareInvitation";
import { api } from "@/lib/api";
import { EXPLICIT_GATE_BODY, EXPLICIT_GATE_TITLE } from "@/lib/sharing";

const auth = { isAuthenticated: true, loading: false };

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: vi.fn() }) }));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => auth }));
vi.mock("@/hooks/use-sharing", () => ({ useSharing: () => ({ refreshSummary: vi.fn() }) }));
vi.mock("@/hooks/use-comic-library", () => ({
  useComicLibrary: () => ({ loadLibrary: vi.fn() }),
}));

/** What the backend returns for an explicit comic nobody has confirmed for. */
const gatedInvitation = {
  comicTitle: null,
  comicAuthor: null,
  pageCount: null,
  coverImagePath: null,
  ownerName: "Alex Owner",
  recipientEmail: "jane@example.com",
  expiresAt: "2026-09-01T00:00:00+00:00",
  isForCurrentUser: true,
  explicitContent: true,
  requiresAdultConfirmation: true,
  adultConfirmed: false,
};

const unlockedInvitation = {
  ...gatedInvitation,
  comicTitle: "Now Visible",
  comicAuthor: "Some Author",
  pageCount: 42,
  coverImagePath: "/api/comics/cover/1/2/cover.png",
  requiresAdultConfirmation: false,
  adultConfirmed: true,
};

const ordinaryInvitation = {
  ...unlockedInvitation,
  comicTitle: "Ordinary Comic",
  explicitContent: false,
  requiresAdultConfirmation: false,
  adultConfirmed: false,
};

const renderPage = () => render(
  <MemoryRouter initialEntries={["/share/invitation/tok123"]}>
    <Routes>
      <Route path="/share/invitation/:token" element={<ShareInvitation />} />
    </Routes>
  </MemoryRouter>
);

describe("ShareInvitation explicit-content gate", () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset();
    vi.mocked(api.post).mockReset();
    auth.isAuthenticated = true;
    auth.loading = false;
  });

  it("shows only the warning and a placeholder for an unconfirmed explicit comic", async () => {
    vi.mocked(api.get).mockResolvedValue({ invitation: gatedInvitation });
    renderPage();

    expect(await screen.findByText(EXPLICIT_GATE_TITLE)).toBeInTheDocument();
    expect(screen.getByText(EXPLICIT_GATE_BODY)).toBeInTheDocument();
    expect(screen.getByText("Hidden until you confirm your age")).toBeInTheDocument();
    // Nothing that identifies the comic, and no cover to blur — the backend
    // sent no URL, so there are no bytes here to expose.
    expect(screen.queryByText("Now Visible")).not.toBeInTheDocument();
    expect(screen.queryByText("42 pages")).not.toBeInTheDocument();
    expect(screen.queryByRole("img")).not.toBeInTheDocument();
    // The owner and the expiry are still enough to decide with.
    expect(screen.getByText("Alex Owner")).toBeInTheDocument();
  });

  it("offers the age declaration instead of Accept", async () => {
    vi.mocked(api.get).mockResolvedValue({ invitation: gatedInvitation });
    renderPage();

    expect(await screen.findByRole("button", { name: /i am 18 or older/i })).toBeInTheDocument();
    // Accepting is refused by the backend until the declaration is made, so the
    // page must not offer it as though it would work.
    expect(screen.queryByRole("button", { name: /add to my collection/i })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /decline/i })).toBeInTheDocument();
  });

  it("reveals the comic only after the backend accepts the declaration", async () => {
    const user = userEvent.setup();
    vi.mocked(api.get)
      .mockResolvedValueOnce({ invitation: gatedInvitation })
      .mockResolvedValueOnce({ invitation: unlockedInvitation });
    vi.mocked(api.post).mockResolvedValue({ share: {} });

    renderPage();
    await user.click(await screen.findByRole("button", { name: /i am 18 or older/i }));

    expect(api.post).toHaveBeenCalledWith(
      "/api/shares/invitations/tok123/confirm-adult",
      { adultConfirmed: true }
    );
    // The unlocked metadata exists because the server chose to send it. The
    // page re-reads rather than revealing anything it decided for itself.
    expect(await screen.findByText("Now Visible")).toBeInTheDocument();
    expect(screen.getByText("42 pages")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /add to my collection/i })).toBeInTheDocument();
  });

  it("keeps the comic hidden when the declaration is refused", async () => {
    const user = userEvent.setup();
    vi.mocked(api.get).mockResolvedValue({ invitation: gatedInvitation });
    vi.mocked(api.post).mockRejectedValue(new Error("This invitation has expired."));

    renderPage();
    await user.click(await screen.findByRole("button", { name: /i am 18 or older/i }));

    expect(await screen.findByText("This invitation has expired.")).toBeInTheDocument();
    expect(screen.queryByText("Now Visible")).not.toBeInTheDocument();
  });

  it("warns a signed-out visitor without naming the comic", async () => {
    auth.isAuthenticated = false;
    vi.mocked(api.get).mockResolvedValue({
      invitation: { ...gatedInvitation, isForCurrentUser: false, recipientEmail: null },
    });

    renderPage();

    expect(await screen.findByText(EXPLICIT_GATE_TITLE)).toBeInTheDocument();
    // Holding the link is not an age declaration; nobody has said who they are.
    expect(screen.getByRole("button", { name: /log in to continue/i })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /i am 18 or older/i })).not.toBeInTheDocument();
    expect(screen.queryByText("Now Visible")).not.toBeInTheDocument();
  });

  it("describes a wrong recipient as another account without assuming an address", async () => {
    vi.mocked(api.get).mockResolvedValue({
      invitation: { ...ordinaryInvitation, isForCurrentUser: false, recipientEmail: null },
    });

    renderPage();

    expect(await screen.findByText("This invitation is for a different account")).toBeInTheDocument();
    expect(screen.getByText(/It belongs to another account\./)).toBeInTheDocument();
    expect(screen.queryByText(/another address/i)).not.toBeInTheDocument();
  });

  it("adds no extra step to an ordinary invitation", async () => {
    vi.mocked(api.get).mockResolvedValue({ invitation: ordinaryInvitation });
    renderPage();

    expect(await screen.findByText("Ordinary Comic")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /add to my collection/i })).toBeInTheDocument();
    await waitFor(() => {
      expect(screen.queryByText(EXPLICIT_GATE_TITLE)).not.toBeInTheDocument();
    });
    expect(screen.queryByRole("button", { name: /i am 18 or older/i })).not.toBeInTheDocument();
  });
});
