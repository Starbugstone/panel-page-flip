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

describe("the admin shares list in bulk", () => {
  const LIVE = [
    share({ id: 5, comic: { id: 3, title: "Sandman #1" } }),
    share({ id: 6, comic: { id: 4, title: "Sandman #2" } }),
    share({ id: 7, comic: { id: 5, title: "Preacher #1" }, status: "revoked", canRevoke: false }),
  ];

  beforeEach(() => vi.clearAllMocks());

  const box = (title) => screen.getByRole("checkbox", { name: `Select the share of ${title}` });

  it("revokes every ticked share through the endpoint the row button uses", async () => {
    const user = userEvent.setup();
    stubList(LIVE);
    vi.mocked(api.post).mockResolvedValue({ message: "Revoked." });
    renderList();
    await screen.findByText("Sandman #1");

    await user.click(box("Sandman #1"));
    await user.click(box("Sandman #2"));
    await user.click(screen.getByRole("button", { name: /Revoke selected/ }));
    await user.click(screen.getByRole("button", { name: "Revoke access" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(2));
    expect(api.post.mock.calls.map(([path]) => path))
      .toEqual(["/api/admin/shares/5/revoke", "/api/admin/shares/6/revoke"]);
    expect(mocks.toast).toHaveBeenCalledWith({ title: "2 shares revoked" });
  });

  /**
   * A share already revoked has nothing left to take away. Skipping it here
   * keeps it out of a failure summary it would only add noise to.
   */
  it("leaves shares that are no longer live out of a whole-page revoke", async () => {
    const user = userEvent.setup();
    stubList(LIVE);
    vi.mocked(api.post).mockResolvedValue({ message: "Revoked." });
    renderList();
    await screen.findByText("Sandman #1");

    await user.click(screen.getByRole("checkbox", { name: "Select all shares" }));

    expect(screen.getByText(/Revoke selected: 2 of 3 eligible/)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /Revoke selected/ }));
    await user.click(screen.getByRole("button", { name: "Revoke access" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(2));
    expect(api.post).not.toHaveBeenCalledWith("/api/admin/shares/7/revoke", {});
  });

  it("warns whoever made each ticked share", async () => {
    const user = userEvent.setup();
    stubList(LIVE);
    vi.mocked(api.post).mockResolvedValue({ message: "Warning sent." });
    renderList();
    await screen.findByText("Sandman #1");

    await user.click(box("Sandman #1"));
    await user.click(box("Sandman #2"));
    await user.click(screen.getByRole("button", { name: /Warn sharers/ }));

    expect(screen.getByRole("heading", { name: "Warn about 2 shares" })).toBeInTheDocument();

    await user.type(screen.getByLabelText("Message"), "Stop sharing this.");
    await user.click(screen.getByRole("button", { name: "Send 2 warnings" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(2));
    expect(api.post.mock.calls.map(([, body]) => body.shareId)).toEqual([5, 6]);
    expect(mocks.toast).toHaveBeenCalledWith({ title: "2 warnings sent" });
  });
});

