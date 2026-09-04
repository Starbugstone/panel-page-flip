import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { SharedWithMeList } from "./SharedWithMeList";
import { api } from "@/lib/api";

vi.mock("@/lib/api", () => ({ api: { delete: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: vi.fn() }) }));

const share = (overrides = {}) => ({
  id: 1,
  status: "accepted",
  comicId: 11,
  comicTitle: "Alpha Book",
  comicAuthor: "An Author",
  ownerName: "Alex Owner",
  ownerLabel: "Alex Owner",
  createdAt: "2026-08-01T12:00:00+00:00",
  explicitContent: false,
  requiresAdultConfirmation: false,
  isExpired: false,
  isDead: false,
  canAnswer: false,
  canRead: true,
  canRemove: true,
  canRestore: false,
  ...overrides,
});

const actions = {
  busyShareId: null,
  confirmAdult: vi.fn(),
  accept: vi.fn(),
  decline: vi.fn(),
  remove: vi.fn(),
  restore: vi.fn(),
  forget: vi.fn(),
};

describe("SharedWithMeList", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(api.delete).mockResolvedValue({});
  });

  it("renders received grants in the same management-table structure as sent grants", () => {
    const accepted = share();
    const pending = share({
      id: 2,
      comicTitle: "Beta Book",
      ownerName: "Blair Owner",
      ownerLabel: "Blair Owner",
      status: "pending",
      canAnswer: true,
      canRead: false,
      canRemove: false,
    });

    render(
      <SharedWithMeList
        sharedWithMe={[accepted, pending]}
        pagination={{ page: 1, limit: 25, totalItems: 2, totalPages: 1 }}
        listKey="received-1"
        isLoading={false}
        searchInput=""
        tableControls={{
          columnFilters: {},
          headerProps: { sort: "createdAt", direction: "DESC", onSort: vi.fn(), onFilter: vi.fn() },
        }}
        actions={actions}
        onSearch={vi.fn()}
        onPageChange={vi.fn()}
        onLimitChange={vi.fn()}
        onRead={vi.fn()}
        onCleanupDead={vi.fn()}
        reload={vi.fn()}
      />,
    );

    expect(screen.getByRole("table")).toBeInTheDocument();
    expect(screen.getAllByRole("row")).toHaveLength(3);
    expect(screen.getByRole("columnheader", { name: /comic/i })).toBeInTheDocument();
    expect(screen.getByRole("columnheader", { name: /shared by/i })).toBeInTheDocument();
    expect(screen.getByRole("columnheader", { name: /status/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Read Alpha Book" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Add to my collection: Beta Book" })).toBeInTheDocument();
    expect(screen.getByRole("table").parentElement).toHaveClass("overflow-auto");
  });

  it("removes only selected unavailable records after confirmation", async () => {
    const user = userEvent.setup();
    const reload = vi.fn();
    const unavailable = share({
      comicTitle: "Unavailable Book",
      status: "revoked",
      isDead: true,
      canRead: false,
      canRemove: false,
    });

    render(
      <SharedWithMeList
        sharedWithMe={[unavailable]}
        pagination={{ page: 1, limit: 25, totalItems: 1, totalPages: 1 }}
        listKey="received-dead"
        isLoading={false}
        searchInput=""
        tableControls={{
          columnFilters: {},
          headerProps: { sort: "createdAt", direction: "DESC", onSort: vi.fn(), onFilter: vi.fn() },
        }}
        actions={actions}
        onSearch={vi.fn()}
        onPageChange={vi.fn()}
        onLimitChange={vi.fn()}
        onRead={vi.fn()}
        onCleanupDead={vi.fn()}
        reload={reload}
      />,
    );

    await user.click(screen.getByRole("checkbox", { name: /select unavailable book/i }));
    await user.click(screen.getByRole("button", { name: "Remove records" }));
    expect(screen.getByRole("heading", { name: "Remove 1 unavailable share?" })).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Remove records" }));

    await waitFor(() => expect(api.delete).toHaveBeenCalledWith(
      "/api/shares/tombstones",
      { body: { shareIds: [1] } },
    ));
    await waitFor(() => expect(reload).toHaveBeenCalled());
  });
});
