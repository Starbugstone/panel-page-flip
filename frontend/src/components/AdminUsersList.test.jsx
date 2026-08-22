import { render, screen, within } from "@testing-library/react";
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

const GIB = 1024 ** 3;

const user = (overrides) => ({
  id: 1,
  name: "Alice",
  email: "alice@example.com",
  roles: ["ROLE_USER"],
  isEmailVerified: true,
  createdAt: "2026-01-05T10:00:00Z",
  lastLoginAt: null,
  comicCount: 25,
  storageUsedBytes: 8.3 * GIB,
  storageQuotaBytes: 10 * GIB,
  unmeasuredComicCount: 0,
  ...overrides,
});

const renderList = async (users) => {
  vi.mocked(api.get).mockResolvedValue({
    users,
    pagination: { page: 1, limit: 25, totalItems: users.length, totalPages: 1 },
  });

  render(
    <MemoryRouter>
      <AdminUsersList />
    </MemoryRouter>
  );

  return within(await screen.findByRole("row", { name: /alice@example\.com/ }));
};

const rowFor = (name) => within(screen.getByRole("row", { name: new RegExp(name) }));

describe("AdminUsersList storage column", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("shows how much of the quota each account has used", async () => {
    const row = await renderList([user()]);

    expect(screen.getByRole("columnheader", { name: "Storage" })).toBeInTheDocument();
    expect(row.getByText("8.30 GiB")).toBeInTheDocument();
    expect(row.getByText("/ 10.00 GiB")).toBeInTheDocument();
    expect(row.getByRole("progressbar")).toHaveAccessibleName(
      "Storage used: 8.30 GiB of 10.00 GiB, 83.0%."
    );
  });

  it("renders an account that has uploaded nothing", async () => {
    const row = await renderList([user({ comicCount: 0, storageUsedBytes: 0 })]);

    expect(row.getByText("0 B")).toBeInTheDocument();
    expect(row.getByRole("progressbar")).toHaveAttribute("aria-valuenow", "0");
  });

  it("keeps the real figure when an account is over its quota", async () => {
    const row = await renderList([user({ storageUsedBytes: 11.2 * GIB })]);

    expect(row.getByText("112.0%")).toBeInTheDocument();
    expect(row.getByRole("progressbar")).toHaveAttribute("aria-valuenow", "100");
  });

  it("warns when some comics have no recorded size", async () => {
    const row = await renderList([user({ storageUsedBytes: 6.4 * GIB, unmeasuredComicCount: 2 })]);

    expect(row.getByRole("progressbar")).toHaveAccessibleName(
      /Measured storage used.*2 comics have no stored file-size metadata/
    );
  });

  it("leaves the rest of the row alone", async () => {
    await renderList([user(), user({ id: 2, name: "Bob", email: "bob@example.com" })]);

    expect(rowFor("alice@example\\.com").getByRole("link", { name: "Manage Alice" })).toBeInTheDocument();
    expect(rowFor("bob@example\\.com").getByRole("button", { name: "Delete Bob" })).toBeInTheDocument();
    expect(screen.getByText(/Showing/)).toBeInTheDocument();
  });
});
