import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { createMemoryRouter, RouterProvider } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import AdminDashboard from "./AdminDashboard";
import { TagProvider } from "@/hooks/use-tags";
import { api } from "@/lib/api";

const { toast } = vi.hoisted(() => ({ toast: vi.fn() }));

vi.mock("@/hooks/use-auth", () => ({
  useAuth: () => ({
    isAdmin: true,
    loading: false,
    user: { id: 1, roles: ["ROLE_ADMIN"] },
  }),
}));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast }) }));
vi.mock("@/lib/api", () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn() } }));

const tabs = [
  { value: "overview", label: "Overview", content: "Total users", empty: null },
  { value: "pending", label: "Pending", content: "Pending Verifications", empty: "No pending verifications found" },
  { value: "users", label: "Users", content: "Users Management", empty: "No users found matching your search" },
  { value: "comics", label: "Comics", content: "Comics Management", empty: "No comics found matching your search" },
  { value: "formats", label: "Formats", content: "Comic formats", empty: null },
  { value: "metadata", label: "Metadata", content: "Metadata providers", empty: null },
  { value: "tags", label: "Tags", content: "Tags Management", empty: "No tags available" },
  { value: "sharing-codes", label: "Sharing codes", content: "Sharing codes", empty: "No sharing codes match these filters." },
  { value: "content-reports", label: "Content reports", content: "Content reports", empty: "No reports match these filters." },
  { value: "dropbox", label: "Dropbox", content: "Dropbox Imports", empty: "No connected Dropbox users" },
  { value: "audit", label: "Audit", content: "Admin Audit Log", empty: "No audit logs yet" },
];

const emptyPagination = { page: 1, limit: 25, totalItems: 0, totalPages: 1 };
const configurationTabs = [
  {
    value: "formats",
    label: "Formats",
    path: "/api/admin/comic-formats",
    loadingText: "Checking format support…",
    settleControl: "Save enabled formats",
    response: { formats: {}, delivery: null },
  },
  {
    value: "metadata",
    label: "Metadata",
    path: "/api/admin/metadata-providers",
    settleControl: "Test credentials",
    response: { providers: [], environment: {}, settings: {} },
  },
];

function deferred() {
  let resolve;
  const promise = new Promise((settle) => { resolve = settle; });
  return { promise, resolve };
}

function emptyResponse(url) {
  if (url === "/api/admin/stats") return { stats: {} };
  if (url.startsWith("/api/users")) return { users: [], pagination: emptyPagination };
  if (url.startsWith("/api/comics")) return { comics: [], pagination: emptyPagination };
  if (url.startsWith("/api/tags")) return { tags: [], pagination: emptyPagination };
  if (url.startsWith("/api/admin/sharing-codes")) {
    return { items: [], pagination: emptyPagination, retentionAfterExpiry: "30 days" };
  }
  if (url.startsWith("/api/admin/audit-logs")) {
    return { logs: [], pagination: emptyPagination, filters: { actions: [] } };
  }
  if (url.startsWith("/api/admin/content-reports")) {
    return { reports: [], statuses: [], categories: [] };
  }
  if (url === "/api/admin/dropbox-users") return { users: [] };
  if (url === "/api/admin/comic-formats") return { formats: {}, delivery: null };
  if (url === "/api/admin/metadata-providers") {
    return { providers: [], environment: {}, settings: {} };
  }
  throw new Error(`Unexpected admin request: ${url}`);
}

function renderDashboard(initialEntries = ["/admin"]) {
  const router = createMemoryRouter(
    [{ path: "/admin", element: <AdminDashboard /> }],
    { initialEntries },
  );
  render(<TagProvider><RouterProvider router={router} /></TagProvider>);
  return router;
}

function expectSelectedTab(label) {
  expect(screen.getByRole("tab", { name: label })).toHaveAttribute("data-state", "active");
}

function activePanel() {
  return screen.getByRole("tabpanel");
}

describe("AdminDashboard tab navigation", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(api.get).mockImplementation(async (url) => emptyResponse(url));
  });

  it("renders Overview at the canonical /admin URL", async () => {
    const router = renderDashboard();

    expect(screen.getByRole("heading", { level: 1, name: "Admin dashboard" })).toBeInTheDocument();
    expectSelectedTab("Overview");
    expect(screen.getByRole("tablist")).toHaveClass("w-full", "overflow-x-auto");
    expect(router.state.location.search).toBe("");
    expect(await within(activePanel()).findByText("Total users")).toBeInTheDocument();
  });

  it.each(tabs)("selects $label and keeps it selected after its request settles", async ({ value, label, content, empty }) => {
    const user = userEvent.setup();
    const router = renderDashboard();

    await user.click(screen.getByRole("tab", { name: label }));

    expectSelectedTab(label);
    expect(router.state.location.search).toBe(value === "overview" ? "" : `?tab=${value}`);
    expect(await within(activePanel()).findByText(content)).toBeInTheDocument();
    if (empty) expect(await within(activePanel()).findByText(empty)).toBeInTheDocument();
    expectSelectedTab(label);
  });

  it.each(tabs)("opens $label directly from its URL", async ({ value, label, content, empty }) => {
    const entry = value === "overview" ? "/admin" : `/admin?tab=${value}`;
    const router = renderDashboard([entry]);

    expectSelectedTab(label);
    expect(router.state.location.search).toBe(value === "overview" ? "" : `?tab=${value}`);
    expect(await within(activePanel()).findByText(content)).toBeInTheDocument();
    if (empty) expect(await within(activePanel()).findByText(empty)).toBeInTheDocument();
    expectSelectedTab(label);
  });

  it("falls back safely to Overview for an unknown tab", async () => {
    const router = renderDashboard(["/admin?tab=does-not-exist"]);

    expectSelectedTab("Overview");
    expect(router.state.location.search).toBe("?tab=does-not-exist");
    expect(await within(activePanel()).findByText("Total users")).toBeInTheDocument();
  });

  it("keeps Sharing codes selected while an empty response is loading and after it settles", async () => {
    let resolveSharingCodes;
    const sharingCodes = new Promise((resolve) => { resolveSharingCodes = resolve; });
    vi.mocked(api.get).mockImplementation((url) => (
      url.startsWith("/api/admin/sharing-codes") ? sharingCodes : Promise.resolve(emptyResponse(url))
    ));

    const user = userEvent.setup();
    const router = renderDashboard();
    await user.click(screen.getByRole("tab", { name: "Sharing codes" }));

    expectSelectedTab("Sharing codes");
    expect(router.state.location.search).toBe("?tab=sharing-codes");
    expect(within(activePanel()).getByText("Loading sharing codes…")).toBeInTheDocument();

    resolveSharingCodes({
      items: [],
      pagination: emptyPagination,
      retentionAfterExpiry: "30 days",
    });

    expect(await within(activePanel()).findByText("No sharing codes match these filters.")).toBeInTheDocument();
    expectSelectedTab("Sharing codes");
    expect(router.state.location.search).toBe("?tab=sharing-codes");
  });

  it("keeps a failed child request on its selected tab", async () => {
    vi.mocked(api.get).mockImplementation((url) => (
      url.startsWith("/api/admin/sharing-codes")
        ? Promise.reject(new Error("Sharing codes are unavailable"))
        : Promise.resolve(emptyResponse(url))
    ));

    const user = userEvent.setup();
    const router = renderDashboard();
    await user.click(screen.getByRole("tab", { name: "Sharing codes" }));

    await waitFor(() => expect(toast).toHaveBeenCalledWith(expect.objectContaining({
      title: "Could not load sharing codes",
      variant: "destructive",
    })));
    expectSelectedTab("Sharing codes");
    expect(router.state.location.search).toBe("?tab=sharing-codes");
    expect(within(activePanel()).getByText("No sharing codes match these filters.")).toBeInTheDocument();
  });

  it.each(configurationTabs)("keeps $label selected while its status request settles", async ({
    value,
    label,
    path,
    loadingText,
    settleControl,
    response,
  }) => {
    const request = deferred();
    vi.mocked(api.get).mockImplementation((url) => (
      url === path ? request.promise : Promise.resolve(emptyResponse(url))
    ));

    const user = userEvent.setup();
    const router = renderDashboard();
    await user.click(screen.getByRole("tab", { name: label }));

    expectSelectedTab(label);
    expect(router.state.location.search).toBe(`?tab=${value}`);
    if (loadingText) expect(within(activePanel()).getByText(loadingText)).toBeInTheDocument();
    expect(within(activePanel()).getByRole("button", { name: settleControl })).toBeDisabled();

    request.resolve(response);

    await waitFor(() => expect(
      within(activePanel()).getByRole("button", { name: settleControl }),
    ).toBeEnabled());
    expectSelectedTab(label);
    expect(router.state.location.search).toBe(`?tab=${value}`);
  });

  it("restores valid tabs through browser Back and Forward navigation", async () => {
    const user = userEvent.setup();
    const router = renderDashboard();

    await user.click(screen.getByRole("tab", { name: "Users" }));
    await user.click(screen.getByRole("tab", { name: "Sharing codes" }));
    expectSelectedTab("Sharing codes");

    await router.navigate(-1);
    await waitFor(() => expectSelectedTab("Users"));
    expect(router.state.location.search).toBe("?tab=users");

    await router.navigate(1);
    await waitFor(() => expectSelectedTab("Sharing codes"));
    expect(router.state.location.search).toBe("?tab=sharing-codes");
  });

  it("adds one history entry when pointer focus and press both activate a tab", async () => {
    const user = userEvent.setup();
    const router = renderDashboard();

    await user.click(screen.getByRole("tab", { name: "Users" }));
    const sharingCodes = screen.getByRole("tab", { name: "Sharing codes" });

    // Radix activates a tab on both primary-button mousedown and focus. A real
    // browser can deliver both before the controlled value has re-rendered,
    // so the duplicate callback must not create two identical history entries.
    await act(async () => {
      fireEvent.mouseDown(sharingCodes, { button: 0, ctrlKey: false });
      await Promise.resolve();
      fireEvent.focus(sharingCodes);
    });
    await waitFor(() => expectSelectedTab("Sharing codes"));

    await router.navigate(-1);
    await waitFor(() => expectSelectedTab("Users"));
    expect(router.state.location.search).toBe("?tab=users");
  });
});
