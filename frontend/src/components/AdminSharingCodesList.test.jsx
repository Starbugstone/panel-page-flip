import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AdminSharingCodesList } from "./AdminSharingCodesList";
import { api } from "@/lib/api";

const { toast } = vi.hoisted(() => ({ toast: vi.fn() }));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast }) }));

const liveCode = {
  id: 12,
  ownerId: 3,
  ownerName: "Issuer",
  ownerEmail: "issuer@example.com",
  comicCount: 2,
  comics: [
    { id: 8, title: "Batman #1", explicitContent: false },
    { id: 9, title: "Superman #1", explicitContent: false },
  ],
  maxUses: 5,
  usesRemaining: 3,
  timesUsed: 2,
  createdAt: "2026-08-09T09:00:00+00:00",
  expiresAt: "2026-08-10T09:00:00+00:00",
  deletableAfter: "2026-09-09T09:00:00+00:00",
  isExpired: false,
  isRevoked: false,
  isRedeemable: true,
  deadReason: null,
  revokedAt: null,
};

const withdrawnCode = {
  ...liveCode,
  id: 13,
  isRedeemable: false,
  isRevoked: true,
  deadReason: "withdrawn",
  revokedAt: "2026-08-09T10:00:00+00:00",
};

const respondWith = (items) => {
  vi.mocked(api.get).mockResolvedValue({
    items,
    pagination: { page: 1, limit: 25, totalItems: items.length, totalPages: 1 },
    retentionAfterExpiry: "30 days",
  });
};

const renderList = () => render(
  <MemoryRouter><AdminSharingCodesList /></MemoryRouter>
);

describe("AdminSharingCodesList", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    respondWith([liveCode]);
  });

  it("identifies a code by its record, never by the code itself", async () => {
    renderList();

    expect(await screen.findByText("issuer@example.com · #3")).toBeInTheDocument();
    expect(screen.getByText("2 / 5")).toBeInTheDocument();
    expect(screen.getByText(/#8 Batman #1, #9 Superman #1/)).toBeInTheDocument();

    // Only the hash is stored, so there is nothing to show and no column that
    // could ever hold one.
    expect(screen.queryByText(/····|[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}/)).not.toBeInTheDocument();
  });

  it("warns that withdrawing closes the way in without taking access back", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockResolvedValue({ message: "Sharing code withdrawn." });

    renderList();
    await screen.findByText("issuer@example.com · #3");

    await user.click(screen.getByRole("button", { name: "Withdraw" }));

    expect(await screen.findByText(/stay with the people who claimed them/)).toBeInTheDocument();
    expect(api.post).not.toHaveBeenCalled();

    await user.click(screen.getByRole("button", { name: "Withdraw code" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/admin/sharing-codes/12/revoke",
      {}
    ));
  });

  it("offers no withdraw action on a code that has already stopped working", async () => {
    respondWith([withdrawnCode]);
    renderList();

    // Located by its id cell rather than by the status text, which also names
    // one of the filter buttons above the table.
    const row = (await screen.findByRole("cell", { name: "13" })).closest("tr");
    expect(within(row).getByText("Withdrawn")).toBeInTheDocument();
    expect(within(row).queryByRole("button", { name: "Withdraw" })).not.toBeInTheDocument();
  });

  it("filters by status through the backend rather than in the table", async () => {
    const user = userEvent.setup();
    renderList();
    await screen.findByText("issuer@example.com · #3");

    await user.click(screen.getByRole("button", { name: "Withdrawn" }));

    // The table can grow past one page between cleanups, so narrowing has to
    // happen in the query and not in what has already been fetched.
    await waitFor(() => expect(vi.mocked(api.get).mock.calls.at(-1)[0])
      .toContain("status=withdrawn"));
  });

  it("says what the cleanup will and will not remove before running it", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockResolvedValue({
      message: "0 expired invitation(s) and 2 dead sharing code(s) removed.",
      claimCodesRemoved: 2,
    });

    renderList();
    await screen.findByText("issuer@example.com · #3");

    await user.click(screen.getByRole("button", { name: /run cleanup/i }));

    expect(await screen.findByText(/same sweep the scheduled job runs/)).toBeInTheDocument();
    expect(await screen.findByText(/died more than 30 days ago/)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Run cleanup" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/admin/sharing-codes/cleanup",
      {}
    ));
    expect(toast).toHaveBeenCalledWith(expect.objectContaining({
      title: "Cleanup complete",
      description: "0 expired invitation(s) and 2 dead sharing code(s) removed.",
    }));
  });
});
