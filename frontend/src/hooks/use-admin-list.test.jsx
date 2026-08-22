import { useState } from "react";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, useLocation } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { useAdminList } from "./use-admin-list";
import { api } from "@/lib/api";

const { toast } = vi.hoisted(() => ({ toast: vi.fn() }));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn() } }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast }) }));

function ListHarness() {
  const location = useLocation();
  const [status, setStatus] = useState("all");
  const list = useAdminList({
    basePath: "/api/users",
    filters: status === "all" ? {} : { status },
    urlKey: "users",
    itemsKey: "users",
  });

  return (
    <div>
      <output aria-label="query string">{location.search}</output>
      <output aria-label="current page">{list.page}</output>
      <button type="button" onClick={() => list.setPage(2)}>Page 2</button>
      <button type="button" onClick={() => list.setLimit(50)}>50 per page</button>
      <button type="button" onClick={() => setStatus("pending")}>Pending filter</button>
      <label>
        Search users
        <input value={list.searchInput} onChange={(event) => list.setSearch(event.target.value)} />
      </label>
    </div>
  );
}

function renderList(entry) {
  return render(
    <MemoryRouter initialEntries={[entry]}>
      <ListHarness />
    </MemoryRouter>,
  );
}

function queryParams() {
  return new URLSearchParams(screen.getByRole("status", { name: "query string" }).textContent);
}

async function expectParams(expected) {
  await waitFor(() => {
    const actual = queryParams();
    for (const [key, value] of Object.entries(expected)) {
      expect(actual.get(key)).toBe(value);
    }
  });
}

describe("useAdminList URL ownership", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(api.get).mockResolvedValue({
      users: [],
      pagination: { page: 1, limit: 25, totalItems: 0, totalPages: 1 },
    });
  });

  it("preserves the selected tab and another list's params when changing page", async () => {
    const user = userEvent.setup();
    renderList("/admin?tab=users&auditQ=role");

    await user.click(screen.getByRole("button", { name: "Page 2" }));

    await expectParams({ tab: "users", auditQ: "role", usersPage: "2" });
  });

  it("preserves unrelated params while changing limit and resetting page", async () => {
    const user = userEvent.setup();
    renderList("/admin?tab=users&usersPage=3&auditQ=role");

    await user.click(screen.getByRole("button", { name: "50 per page" }));

    await expectParams({ tab: "users", auditQ: "role", usersLimit: "50" });
    expect(queryParams().has("usersPage")).toBe(false);
  });

  it("preserves unrelated params while debounced search replaces its own page", async () => {
    const user = userEvent.setup();
    renderList("/admin?tab=users&usersPage=3&auditQ=role");

    await user.type(screen.getByRole("textbox", { name: "Search users" }), "smith");

    await expectParams({ tab: "users", auditQ: "role", usersQ: "smith" });
    expect(queryParams().has("usersPage")).toBe(false);
  });

  it("preserves unrelated params when a filter resets the current page", async () => {
    const user = userEvent.setup();
    renderList("/admin?tab=users&usersPage=3&auditQ=role");

    await user.click(screen.getByRole("button", { name: "Pending filter" }));

    await waitFor(() => expect(screen.getByRole("status", { name: "current page" })).toHaveTextContent("1"));
    await expectParams({ tab: "users", auditQ: "role" });
    expect(queryParams().has("usersPage")).toBe(false);
  });

  it("removes only its own search param when search is cleared", async () => {
    const user = userEvent.setup();
    renderList("/admin?tab=users&usersQ=smith&auditQ=role");

    await user.clear(screen.getByRole("textbox", { name: "Search users" }));

    await waitFor(() => expect(queryParams().has("usersQ")).toBe(false));
    await expectParams({ tab: "users", auditQ: "role" });
  });
});
