import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AdminUsersList } from "./AdminUsersList";
import { api } from "@/lib/api";

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() } }));
// One identity for the whole run: the list hook lists `toast` among its effect
// dependencies, so a fresh function per render would refetch for ever.
const { toast } = vi.hoisted(() => ({ toast: vi.fn() }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast }) }));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => ({ user: { id: 1, roles: ["ROLE_ADMIN"] } }) }));

const account = (id, name, overrides = {}) => ({
  id,
  name,
  email: `${name.toLowerCase()}@example.com`,
  roles: ["ROLE_USER"],
  isEmailVerified: true,
  createdAt: "2026-01-05T10:00:00Z",
  lastLoginAt: null,
  comicCount: 0,
  storageUsedBytes: 0,
  storageQuotaBytes: 0,
  unmeasuredComicCount: 0,
  ...overrides,
});

// Id 1 is the signed-in administrator, so it is deliberately never eligible.
const ACCOUNTS = [
  account(1, "Admin"),
  account(2, "Bob"),
  account(3, "Carol"),
  account(4, "Dave"),
];

const renderList = async (props = {}, users = ACCOUNTS) => {
  vi.mocked(api.get).mockResolvedValue({
    users,
    pagination: { page: 1, limit: 25, totalItems: users.length, totalPages: 1 },
  });

  const view = render(
    <MemoryRouter>
      <AdminUsersList {...props} />
    </MemoryRouter>
  );

  await screen.findByRole("row", { name: /bob@example\.com/ });
  return view;
};

const box = (name) => screen.getByRole("checkbox", { name: `Select ${name}` });
const bulkButton = (name) => screen.getByRole("button", { name: new RegExp(name) });

const shiftClick = async (user, name) => {
  await user.keyboard("{Shift>}");
  await user.click(box(name));
  await user.keyboard("{/Shift}");
};

const confirm = (user) => user.click(screen.getByRole("button", { name: "Confirm" }));

describe("AdminUsersList bulk actions", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("deletes every ticked account through the endpoint the row button uses", async () => {
    const user = userEvent.setup();
    vi.mocked(api.delete).mockResolvedValue({});
    await renderList();

    await user.click(box("Bob"));
    await user.click(box("Carol"));
    await user.click(bulkButton("Delete selected"));
    await confirm(user);

    await waitFor(() => expect(api.delete).toHaveBeenCalledTimes(2));
    expect(api.delete).toHaveBeenCalledWith("/api/users/2");
    expect(api.delete).toHaveBeenCalledWith("/api/users/3");
    expect(toast).toHaveBeenCalledWith({ title: "2 users deleted" });
  });

  /** Range selection, as any file manager does it. */
  it("shift-clicking takes every row between the two clicks", async () => {
    const user = userEvent.setup();
    vi.mocked(api.delete).mockResolvedValue({});
    await renderList();

    await user.click(box("Bob"));
    await shiftClick(user, "Dave");

    expect(screen.getByText("3 of 4 users selected")).toBeInTheDocument();

    await user.click(bulkButton("Delete selected"));
    await confirm(user);

    await waitFor(() => expect(api.delete).toHaveBeenCalledTimes(3));
    expect(api.delete.mock.calls.map(([path]) => path))
      .toEqual(["/api/users/2", "/api/users/3", "/api/users/4"]);
  });

  it("takes the whole page from the header box", async () => {
    const user = userEvent.setup();
    await renderList();

    await user.click(screen.getByRole("checkbox", { name: "Select all users" }));

    expect(screen.getByText("4 of 4 users selected")).toBeInTheDocument();
  });

  /**
   * The server refuses this outright, and finding out one row at a time from a
   * failure summary is worse than never offering it.
   */
  it("leaves the signed-in administrator out of a whole-page delete", async () => {
    const user = userEvent.setup();
    vi.mocked(api.delete).mockResolvedValue({});
    await renderList();

    await user.click(screen.getByRole("checkbox", { name: "Select all users" }));

    expect(screen.getByText(/Delete selected: 3 of 4 eligible/)).toBeInTheDocument();

    await user.click(bulkButton("Delete selected"));
    await confirm(user);

    await waitFor(() => expect(api.delete).toHaveBeenCalledTimes(3));
    expect(api.delete).not.toHaveBeenCalledWith("/api/users/1");
  });

  it("offers nothing to do with a selection of only your own account", async () => {
    const user = userEvent.setup();
    await renderList();

    await user.click(box("Admin"));

    expect(bulkButton("Delete selected")).toBeDisabled();
    expect(bulkButton("Warn selected")).toBeDisabled();
  });

  /**
   * A partial run is the normal outcome — accounts that own comics cannot be
   * deleted — so it is reported as partial rather than as a flat success.
   */
  it("names the accounts the server refused and keeps the ones it took", async () => {
    const user = userEvent.setup();
    vi.mocked(api.delete).mockImplementation((path) => (
      path === "/api/users/3"
        ? Promise.reject(new Error("This user still owns comics."))
        : Promise.resolve({})
    ));
    await renderList();

    await user.click(box("Bob"));
    await user.click(box("Carol"));
    await user.click(bulkButton("Delete selected"));
    await confirm(user);

    await waitFor(() => expect(toast).toHaveBeenCalledWith({
      title: "1 of 2 users deleted",
      description: "Carol: This user still owns comics.",
      variant: "destructive",
    }));
  });

  it("sends one warning per ticked account, with the message written once", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockResolvedValue({ message: "Warning sent." });
    await renderList();

    await user.click(box("Bob"));
    await user.click(box("Carol"));
    await user.click(bulkButton("Warn selected"));

    expect(screen.getByRole("heading", { name: "Warn about 2 users" })).toBeInTheDocument();

    await user.type(screen.getByLabelText("Message"), "Please stop.");
    await user.click(screen.getByRole("button", { name: "Send 2 warnings" }));

    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(2));
    expect(api.post).toHaveBeenCalledWith("/api/admin/warnings", {
      userId: 2,
      message: "Please stop.",
      sendEmail: false,
    });
    expect(api.post).toHaveBeenCalledWith("/api/admin/warnings", {
      userId: 3,
      message: "Please stop.",
      sendEmail: false,
    });
    expect(toast).toHaveBeenCalledWith({ title: "2 warnings sent" });
  });

  it("forgets the selection once the list it was made against is reloaded", async () => {
    const user = userEvent.setup();
    vi.mocked(api.delete).mockResolvedValue({});
    await renderList();

    await user.click(box("Bob"));
    await user.click(bulkButton("Delete selected"));
    await confirm(user);

    await waitFor(() => expect(screen.getByText("0 of 4 users selected")).toBeInTheDocument());
  });
});

describe("AdminUsersList bulk actions on the pending tab", () => {
  const PENDING = [
    account(2, "Bob", { isEmailVerified: false }),
    account(3, "Carol", { isEmailVerified: false }),
  ];

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("verifies every ticked account at once", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockResolvedValue({ user: {} });
    await renderList({ showOnlyUnverified: true }, PENDING);

    await user.click(screen.getByRole("checkbox", { name: "Select all pending users" }));
    await user.click(bulkButton("Verify selected"));

    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(2));
    expect(api.post).toHaveBeenCalledWith("/api/users/2/verify", {});
    expect(api.post).toHaveBeenCalledWith("/api/users/3/verify", {});
    expect(toast).toHaveBeenCalledWith({ title: "2 accounts verified" });
  });

  it("resends the verification email to every ticked account", async () => {
    const user = userEvent.setup();
    vi.mocked(api.post).mockResolvedValue({});
    await renderList({ showOnlyUnverified: true }, PENDING);

    await user.click(box("Bob"));
    await user.click(bulkButton("Resend verification"));

    await waitFor(() => expect(api.post).toHaveBeenCalledWith(
      "/api/email-verification/resend",
      { email: "bob@example.com" }
    ));
    expect(toast).toHaveBeenCalledWith({ title: "1 email sent" });
  });

  it("does not offer verification on the tab where every account is already verified", async () => {
    await renderList();

    expect(screen.queryByRole("button", { name: /Verify selected/ })).not.toBeInTheDocument();
  });
});
