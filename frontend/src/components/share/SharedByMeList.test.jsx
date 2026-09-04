import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { SharedByMeList } from "./SharedByMeList";
import { api } from "@/lib/api";

vi.mock("@/lib/api", () => ({ api: { post: vi.fn(), delete: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: vi.fn() }) }));

const share = (overrides = {}) => ({
  id: 1,
  comicId: 11,
  comicTitle: "Alpha Book",
  comicAuthor: "An Author",
  coverImagePath: null,
  explicitContent: false,
  recipientEmail: "jane@example.com",
  recipientLabel: "jane@example.com",
  recipientName: null,
  recipientUsername: null,
  recipientUserCode: null,
  status: "accepted",
  createdAt: "2026-08-01T12:00:00+00:00",
  canResend: false,
  canRevoke: true,
  canDelete: false,
  ...overrides,
});

const tableControls = () => ({
  columnFilters: {},
  headerProps: {
    sort: "createdAt",
    direction: "DESC",
    onSort: vi.fn(),
    onFilter: vi.fn(),
  },
});

const renderList = (props = {}) => render(
  <MemoryRouter>
    <SharedByMeList
      sharedByMe={[
        share(),
        share({
          id: 2,
          comicId: 12,
          comicTitle: "Beta Book",
          recipientEmail: "sam@example.com",
          recipientLabel: "sam@example.com",
          status: "declined",
          canRevoke: false,
          canDelete: true,
        }),
      ]}
      byMePagination={{ page: 1, limit: 25, totalItems: 2, totalPages: 1 }}
      byMeListKey="request-1"
      byMeIsLoading={false}
      searchInput=""
      tableControls={tableControls()}
      busyShareId={null}
      onSearch={vi.fn()}
      onPageChange={vi.fn()}
      onLimitChange={vi.fn()}
      onShare={vi.fn()}
      onStopSharing={vi.fn()}
      onResend={vi.fn()}
      onRevoke={vi.fn()}
      onDelete={vi.fn()}
      reload={vi.fn()}
      {...props}
    />
  </MemoryRouter>
);

describe("SharedByMeList", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(api.post).mockResolvedValue({});
    vi.mocked(api.delete).mockResolvedValue({});
  });

  it("renders one sortable and filterable table row per share", async () => {
    const user = userEvent.setup();
    const onSearch = vi.fn();
    const controls = tableControls();
    renderList({ onSearch, tableControls: controls });

    expect(screen.getByRole("table")).toBeInTheDocument();
    expect(screen.getAllByRole("row")).toHaveLength(3);

    fireEvent.change(screen.getByRole("searchbox", { name: /search your shares/i }), {
      target: { value: "jane" },
    });
    expect(onSearch).toHaveBeenCalledWith("jane");

    await user.click(screen.getByRole("button", { name: /comic sort and filter/i }));
    await user.click(screen.getByRole("button", { name: "Ascending" }));
    expect(controls.headerProps.onSort).toHaveBeenCalledWith("comicTitle", "ASC");
  });

  it("selects rows and confirms bulk actions against only eligible shares", async () => {
    const user = userEvent.setup();
    const reload = vi.fn();
    renderList({ reload });

    await user.click(screen.getByRole("checkbox", { name: /select all shares/i }));
    expect(screen.getByText("2 of 2 shares selected")).toBeInTheDocument();
    expect(screen.getByText(/revoke selected: 1 of 2 eligible/i)).toBeInTheDocument();
    expect(screen.getByText(/delete records: 1 of 2 eligible/i)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Revoke selected" }));
    expect(screen.getByRole("heading", { name: "Revoke 1 share?" })).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Revoke access" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith("/api/shares/1/revoke", {}));
    await waitFor(() => expect(reload).toHaveBeenCalled());
  });

  it("deletes only selected finished share records after confirmation", async () => {
    const user = userEvent.setup();
    renderList();

    await user.click(screen.getByRole("checkbox", { name: /share of beta book/i }));
    await user.click(screen.getByRole("button", { name: "Delete records" }));
    expect(screen.getByRole("heading", { name: "Delete 1 share record?" })).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Delete records" }));

    await waitFor(() => expect(api.delete).toHaveBeenCalledWith("/api/shares/2"));
    expect(api.post).not.toHaveBeenCalled();
  });

  it("cannot select stale grants while the next page is loading", async () => {
    renderList({ byMeIsLoading: true, byMeListKey: "request-2" });
    await userEvent.click(screen.getByRole("checkbox", { name: "Select all shares" }));
    expect(screen.getByRole("button", { name: "Revoke selected" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Delete records" })).toBeDisabled();
    expect(screen.getByText("0 of 0 shares selected")).toBeInTheDocument();
  });

  it("keeps the wide management table scrollable inside a narrow viewport", () => {
    renderList();

    expect(screen.getByRole("table").parentElement).toHaveClass("overflow-auto");
    expect(screen.getByText("0 of 2 shares selected").parentElement.parentElement).toHaveClass("flex-col");
  });
});
