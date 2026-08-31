import { act, renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { SharingProvider, useSharingLists } from "./use-sharing";
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

const wrapper = ({ children }) => <SharingProvider>{children}</SharingProvider>;

describe("useSharingLists pagination", () => {
  let byMeResponse;

  beforeEach(() => {
    vi.clearAllMocks();
    byMeResponse = () => ({ sharedByMe: [], pagination: paginationBlock() });
    vi.mocked(api.get).mockImplementation((url) => {
      if (url === "/api/shares/summary") return Promise.resolve({ pendingInvitations: 0, deadShares: 0 });
      if (url === "/api/shares/shared-with-me") return Promise.resolve({ sharedWithMe: [] });
      if (url.startsWith("/api/shares/shared-by-me")) return Promise.resolve(byMeResponse(url));
      return Promise.reject(new Error(`Unexpected GET ${url}`));
    });
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
});
