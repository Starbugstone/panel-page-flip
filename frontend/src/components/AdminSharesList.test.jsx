import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AdminSharesList } from "./AdminSharesList";
import { api } from "@/lib/api";

const mocks = vi.hoisted(() => ({ toast: vi.fn() }));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: mocks.toast }) }));

const share = (overrides = {}) => ({
  id: 5,
  status: "accepted",
  createdAt: "2026-08-01T10:00:00+00:00",
  comic: { id: 3, title: "Sandman #1", explicitContent: false, sharingRestricted: false, quarantined: false },
  owner: { id: 1, name: "Jo Owner", email: "jo@example.com" },
  recipient: { id: 2, name: "Sam Reader", email: "sam@example.com", username: "SamReader1" },
  recipientEmail: "sam@example.com",
  canRevoke: true,
  ...overrides,
});

const stubList = (items) => {
  vi.mocked(api.get).mockResolvedValue({
    items,
    pagination: { page: 1, limit: 25, totalItems: items.length, totalPages: 1 },
  });
};

const renderList = () => render(<MemoryRouter><AdminSharesList /></MemoryRouter>);

describe("the admin shares list", () => {
  beforeEach(() => vi.clearAllMocks());

  it("names the comic, who shared it and who holds it", async () => {
    stubList([share()]);
    renderList();

    expect(await screen.findByText("Sandman #1")).toBeInTheDocument();
    expect(screen.getByText("Jo Owner")).toBeInTheDocument();
    expect(screen.getByText("Sam Reader")).toBeInTheDocument();
    expect(screen.getByText("@SamReader1")).toBeInTheDocument();
  });

  /**
   * The 18+ redaction an owner's view applies is for a recipient's benefit. An
   * administrator checking what adult material is moving between accounts is
   * exactly the person who has to see it.
   */
  it("labels a comic marked 18+", async () => {
    stubList([share({ comic: { ...share().comic, explicitContent: true } })]);
    renderList();

    expect(await screen.findByText("18+")).toBeInTheDocument();
  });

  /** Somebody with no account yet is only identifiable by the address. */
  it("falls back to the address for a recipient with no account", async () => {
    stubList([share({ recipient: null, recipientEmail: "nobody@example.com" })]);
    renderList();

    expect(await screen.findByText("nobody@example.com")).toBeInTheDocument();
  });

  it("revokes a share after confirming, and says what it did not do", async () => {
    const user = userEvent.setup();
    stubList([share()]);
    vi.mocked(api.post).mockResolvedValue({ message: "Share revoked." });
    renderList();

    await user.click(await screen.findByRole("button", { name: /revoke the share of Sandman #1/i }));
    expect(await screen.findByText(/stays with its owner and nothing is deleted/i)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Revoke access" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith("/api/admin/shares/5/revoke", {}));
    expect(mocks.toast).toHaveBeenCalledWith(expect.objectContaining({ title: "Share revoked" }));
  });

  it("offers no revoke for a share that is already over", async () => {
    stubList([share({ status: "revoked", canRevoke: false })]);
    renderList();

    await screen.findByText("Sandman #1");
    expect(screen.queryByRole("button", { name: /revoke the share/i })).not.toBeInTheDocument();
  });

  it("narrows the list to explicit comics on request", async () => {
    const user = userEvent.setup();
    stubList([share()]);
    renderList();

    await screen.findByText("Sandman #1");
    await user.click(screen.getByLabelText(/only comics marked 18\+/i));

    await waitFor(() => expect(
      vi.mocked(api.get).mock.calls.some(([url]) => url.includes("explicitOnly=true"))
    ).toBe(true));
  });

  it("opens the warn dialog against the sharer", async () => {
    const user = userEvent.setup();
    stubList([share()]);
    renderList();

    await user.click(await screen.findByRole("button", { name: /warn the sharer of Sandman #1/i }));

    expect(await screen.findByText(/Jo Owner will see this the next time they sign in/))
      .toBeInTheDocument();
  });
});
