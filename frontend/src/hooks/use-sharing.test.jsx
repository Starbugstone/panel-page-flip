import { act, renderHook, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { SharingProvider, useSharing, useSharingLists } from "./use-sharing";
import { api } from "@/lib/api";

vi.mock("@/lib/api", () => ({ api: { get: vi.fn() } }));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn() } }));

// One object for the whole file: the hook tags each answer with the account it
// belongs to, so a fresh user object per render would read as a signed-out gap.
const { AUTH } = vi.hoisted(() => ({
  AUTH: { isAuthenticated: true, user: { id: 7, email: "owner@test.local" } },
}));
vi.mock("@/hooks/use-auth", () => ({ useAuth: () => AUTH }));

const paginationBlock = (overrides = {}) => ({ page: 1, limit: 25, totalItems: 0, totalPages: 1, ...overrides });

/** Every by-me URL the hook asked for, in order. */
const byMeCalls = () =>
  vi.mocked(api.get).mock.calls.map(([url]) => url).filter((url) => url.startsWith("/api/shares/shared-by-me"));
const withMeCalls = () =>
  vi.mocked(api.get).mock.calls.map(([url]) => url).filter((url) => url.startsWith("/api/shares/shared-with-me"));

const wrapper = ({ children }) => <SharingProvider>{children}</SharingProvider>;

function deferred() {
  let resolve;
  const promise = new Promise((settle) => { resolve = settle; });

  return { promise, resolve };
}

describe("sharing summary session isolation", () => {
  const originalUser = AUTH.user;
  const emptySummary = { pendingInvitations: 0, deadShares: 0 };

  beforeEach(() => vi.resetAllMocks());
  afterEach(() => {
    AUTH.isAuthenticated = true;
    AUTH.user = originalUser;
  });

  it("hides the previous account's counts while the next account is loading", async () => {
    const nextAccount = deferred();
    api.get.mockResolvedValueOnce({ pendingInvitations: 8, deadShares: 3 })
      .mockReturnValueOnce(nextAccount.promise);
    const { result, rerender } = renderHook(useSharing, { wrapper });
    await waitFor(() => expect(result.current.summary.pendingInvitations).toBe(8));

    AUTH.user = { id: 8 };
    rerender();
    expect(result.current.summary).toEqual(emptySummary);
    await act(async () => nextAccount.resolve({ pendingInvitations: 1, deadShares: 0 }));
    expect(result.current.summary.pendingInvitations).toBe(1);
  });

  it("ignores an old action refresh after signing out and signing back in", async () => {
    const oldRefresh = deferred();
    const newSummary = deferred();
    api.get.mockResolvedValueOnce({ pendingInvitations: 2, deadShares: 0 })
      .mockReturnValueOnce(oldRefresh.promise)
      .mockReturnValueOnce(newSummary.promise);
    const { result, rerender } = renderHook(useSharing, { wrapper });
    await waitFor(() => expect(result.current.summary.pendingInvitations).toBe(2));
    const refresh = result.current.refreshSummary;
    let pending;
    act(() => { pending = refresh(); });

    AUTH.isAuthenticated = false;
    AUTH.user = null;
    rerender();
    await act(async () => { oldRefresh.resolve({ pendingInvitations: 99, deadShares: 99 }); await pending; });
    AUTH.isAuthenticated = true;
    AUTH.user = { id: 8 };
    rerender();
    expect(result.current.summary).toEqual(emptySummary);
    await act(async () => newSummary.resolve({ pendingInvitations: 1, deadShares: 0 }));
    expect(result.current.summary.pendingInvitations).toBe(1);

    await act(async () => refresh());
    expect(api.get).toHaveBeenCalledTimes(3);
  });
});

describe("useSharingLists pagination", () => {
  let byMeResponse;
  let withMeResponse;

  beforeEach(() => {
    vi.clearAllMocks();
    byMeResponse = () => ({ sharedByMe: [], pagination: paginationBlock() });
    withMeResponse = () => ({ sharedWithMe: [], pagination: paginationBlock() });
    vi.mocked(api.get).mockImplementation((url) => {
      if (url === "/api/shares/summary") return Promise.resolve({ pendingInvitations: 0, deadShares: 0 });
      if (url.startsWith("/api/shares/shared-with-me")) return Promise.resolve(withMeResponse(url));
      if (url.startsWith("/api/shares/shared-by-me")) return Promise.resolve(byMeResponse(url));
      return Promise.reject(new Error(`Unexpected GET ${url}`));
    });
  });

  it("pages and filters the recipient table independently", async () => {
    withMeResponse = (url) => ({
      sharedWithMe: [],
      pagination: paginationBlock({
        page: Number(new URLSearchParams(url.split("?")[1]).get("page")),
        totalItems: 30,
        totalPages: 2,
      }),
    });
    const withMeFilters = { sort: "owner", direction: "ASC", filterStatus: "Pending" };
    const { result } = renderHook(() => useSharingLists({}, withMeFilters), { wrapper });
    await waitFor(() => expect(result.current.isLoading).toBe(false));

    expect(withMeCalls().at(-1)).toBe(
      "/api/shares/shared-with-me?sort=owner&direction=ASC&filterStatus=Pending&page=1&limit=25"
    );

    act(() => result.current.setWithMePage(2));
    await waitFor(() => expect(withMeCalls().at(-1)).toContain("page=2"));
    await waitFor(() => expect(result.current.withMePagination.page).toBe(2));
  });

  it("asks for the first page at the shared default size and returns the server's pagination", async () => {
    byMeResponse = () => ({ sharedByMe: [], pagination: paginationBlock({ totalItems: 30, totalPages: 2 }) });

    const { result } = renderHook(() => useSharingLists(), { wrapper });
    await waitFor(() => expect(result.current.isLoading).toBe(false));

    expect(api.get).toHaveBeenCalledWith("/api/shares/shared-by-me?page=1&limit=25");
    expect(result.current.byMePagination).toEqual(paginationBlock({ totalItems: 30, totalPages: 2 }));
  });

  it("turning the page fetches that page", async () => {
    byMeResponse = (url) => ({
      sharedByMe: [],
      pagination: paginationBlock({ page: Number(new URLSearchParams(url.split("?")[1]).get("page")), totalItems: 30, totalPages: 2 }),
    });

    const { result } = renderHook(() => useSharingLists(), { wrapper });
    await waitFor(() => expect(result.current.isLoading).toBe(false));

    act(() => result.current.setByMePage(2));

    await waitFor(() => expect(api.get).toHaveBeenCalledWith("/api/shares/shared-by-me?page=2&limit=25"));
  });

  it("changing the page size starts over from the first page", async () => {
    byMeResponse = () => ({ sharedByMe: [], pagination: paginationBlock({ totalItems: 30, totalPages: 2 }) });

    const { result } = renderHook(() => useSharingLists(), { wrapper });
    await waitFor(() => expect(result.current.isLoading).toBe(false));

    act(() => result.current.setByMePage(2));
    await waitFor(() => expect(api.get).toHaveBeenCalledWith("/api/shares/shared-by-me?page=2&limit=25"));

    act(() => result.current.setByMeLimit(10));

    await waitFor(() => expect(api.get).toHaveBeenCalledWith("/api/shares/shared-by-me?page=1&limit=10"));
  });

  it("sends table filters to the server and resets them to the first page", async () => {
    const filters = {
      sort: "recipient",
      direction: "ASC",
      filterStatus: "Pending",
      filterTimezone: "Europe/Paris",
    };
    const { result } = renderHook(() => useSharingLists(filters), { wrapper });
    await waitFor(() => expect(result.current.isLoading).toBe(false));

    expect(byMeCalls().at(-1)).toBe(
      "/api/shares/shared-by-me?sort=recipient&direction=ASC&filterStatus=Pending&filterTimezone=Europe%2FParis&page=1&limit=25"
    );
  });

  /**
   * The reset has to happen in render, not from an effect. An effect leaves one
   * commit carrying the new filters and the old page, and the fetch effect in
   * that same commit would ask the server for a page the filters just
   * invalidated — a whole round trip whose answer is thrown away.
   */
  it("asks for the first page only, when a filter changes off page one", async () => {
    // Enough pages that page three exists; otherwise the clamp pulls it back on
    // its own and the reset under test never gets a look in.
    byMeResponse = () => ({ sharedByMe: [], pagination: paginationBlock({ totalItems: 60, totalPages: 3 }) });
    const { rerender, result } = renderHook(
      ({ filters }) => useSharingLists(filters),
      { wrapper, initialProps: { filters: { sort: "createdAt", direction: "DESC" } } },
    );
    await waitFor(() => expect(result.current.isLoading).toBe(false));

    act(() => result.current.setByMePage(3));
    await waitFor(() => expect(byMeCalls().at(-1)).toContain("page=3"));
    const before = byMeCalls().length;

    rerender({ filters: { sort: "createdAt", direction: "DESC", filterStatus: "Pending" } });
    await waitFor(() => expect(byMeCalls().at(-1)).toContain("filterStatus=Pending"));

    expect(byMeCalls().slice(before)).toEqual([
      "/api/shares/shared-by-me?sort=createdAt&direction=DESC&filterStatus=Pending&page=1&limit=25",
    ]);
  });

  it("debounces an owner-table search and starts it on page one", async () => {
    const { result } = renderHook(() => useSharingLists(), { wrapper });
    await waitFor(() => expect(result.current.isLoading).toBe(false));

    act(() => result.current.setByMePage(2));
    await waitFor(() => expect(byMeCalls().at(-1)).toContain("page=2"));

    act(() => result.current.setByMeSearchInput("  jane  "));
    await waitFor(() => expect(byMeCalls().at(-1)).toBe(
      "/api/shares/shared-by-me?page=1&limit=25&search=jane"
    ));
  });

  it("falls back to the last page when the one being looked at stops existing", async () => {
    // Whatever page is asked for, the server now only has one: the state after
    // deleting the final record on a trailing page.
    byMeResponse = () => ({ sharedByMe: [], pagination: paginationBlock({ totalItems: 2, totalPages: 1 }) });

    const { result } = renderHook(() => useSharingLists(), { wrapper });
    await waitFor(() => expect(result.current.isLoading).toBe(false));

    act(() => result.current.setByMePage(3));

    // The page it was told about is asked for once, and then abandoned for the
    // last page that exists rather than rendered as an empty list.
    await waitFor(() => expect(byMeCalls().at(-1)).toBe("/api/shares/shared-by-me?page=1&limit=25"));
    expect(byMeCalls()).toContain("/api/shares/shared-by-me?page=3&limit=25");
  });

  it("ignores an action reload for an old URL after the new page has loaded", async () => {
    const latePageOne = deferred();
    const pageTwo = deferred();
    let initialPageOne = true;

    vi.mocked(api.get).mockImplementation((url) => {
      if (url === "/api/shares/summary") return Promise.resolve({ pendingInvitations: 0, deadShares: 0 });
      if (url.startsWith("/api/shares/shared-with-me")) {
        return Promise.resolve({ sharedWithMe: [], pagination: paginationBlock() });
      }
      if (url === "/api/shares/shared-by-me?page=2&limit=25") return pageTwo.promise;
      if (url === "/api/shares/shared-by-me?page=1&limit=25") {
        if (initialPageOne) {
          initialPageOne = false;
          return Promise.resolve({
            sharedByMe: [{ id: "initial-a" }],
            pagination: paginationBlock({ totalItems: 2, totalPages: 2 }),
          });
        }

        return latePageOne.promise;
      }

      return Promise.reject(new Error(`Unexpected GET ${url}`));
    });

    const { result } = renderHook(() => useSharingLists(), { wrapper });
    await waitFor(() => expect(result.current.sharedByMe).toEqual([{ id: "initial-a" }]));
    const reloadPageOne = result.current.reload;

    let lateReload;
    act(() => { lateReload = reloadPageOne(); });
    await waitFor(() => expect(byMeCalls().filter((url) => url.includes("page=1"))).toHaveLength(2));

    act(() => result.current.setByMePage(2));
    await waitFor(() => expect(byMeCalls()).toContain("/api/shares/shared-by-me?page=2&limit=25"));
    await act(async () => {
      pageTwo.resolve({
        sharedByMe: [{ id: "current-b" }],
        pagination: paginationBlock({ page: 2, totalItems: 2, totalPages: 2 }),
      });
      await pageTwo.promise;
    });
    await waitFor(() => expect(result.current.sharedByMe).toEqual([{ id: "current-b" }]));
    expect(result.current.byMeIsLoading).toBe(false);

    await act(async () => {
      latePageOne.resolve({
        sharedByMe: [{ id: "late-a" }],
        pagination: paginationBlock({ totalItems: 2, totalPages: 2 }),
      });
      await lateReload;
    });

    expect(result.current.sharedByMe).toEqual([{ id: "current-b" }]);
    expect(result.current.byMeIsLoading).toBe(false);
  });

  /**
   * A bulk revoke can outlive a search, a filter or a page turn: its callback
   * is captured when the confirmation is clicked and only reloads once every
   * request has come back. Dropping that reload would leave the rows it just
   * changed on screen exactly as they were.
   */
  it("reloads what is on screen now when an action's callback predates a page turn", async () => {
    vi.mocked(api.get).mockImplementation((url) => {
      if (url === "/api/shares/summary") return Promise.resolve({ pendingInvitations: 0, deadShares: 0 });
      if (url.startsWith("/api/shares/shared-with-me")) {
        return Promise.resolve({ sharedWithMe: [], pagination: paginationBlock() });
      }

      return Promise.resolve({
        sharedByMe: [{ id: url }],
        pagination: paginationBlock({ totalItems: 60, totalPages: 3 }),
      });
    });

    const { result } = renderHook(() => useSharingLists(), { wrapper });
    await waitFor(() => expect(result.current.isLoading).toBe(false));
    const reloadFromPageOne = result.current.reload;

    act(() => result.current.setByMePage(2));
    await waitFor(() => expect(byMeCalls().at(-1)).toBe("/api/shares/shared-by-me?page=2&limit=25"));

    await act(async () => { await reloadFromPageOne(); });

    // Page two, not the page one it was captured on, and not nothing at all.
    expect(byMeCalls().at(-1)).toBe("/api/shares/shared-by-me?page=2&limit=25");
    expect(byMeCalls().filter((url) => url.includes("page=2"))).toHaveLength(2);
    expect(result.current.sharedByMe).toEqual([{ id: "/api/shares/shared-by-me?page=2&limit=25" }]);
  });
});
