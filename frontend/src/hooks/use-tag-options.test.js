// @vitest-environment jsdom

import { createElement } from "react";
import { act, renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { api } from "@/lib/api";
import { TagProvider, useTags } from "./use-tags";
import { isRetryableTagError, useTagOptions } from "./use-tag-options";

const { authState, toast } = vi.hoisted(() => ({ authState: { user: { id: 7 } }, toast: vi.fn() }));

vi.mock("./use-auth", () => ({ useAuth: () => ({ user: authState.user }) }));
vi.mock("./use-toast", () => ({ useToast: () => ({ toast }) }));
vi.mock("@/lib/api", () => ({ api: { get: vi.fn() } }));

describe("isRetryableTagError", () => {
  it.each([
    [{ status: 0, message: "Unable to reach the server" }, true],
    [{ status: 503, message: "Temporarily unavailable" }, true],
    [{ status: 400, message: "network connection failed" }, true],
    [{ status: 404, message: "Tag endpoint not found" }, false],
  ])("classifies %o as retryable=%s", (error, expected) => {
    expect(isRetryableTagError(error)).toBe(expected);
  });
});

describe("useTagOptions", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    authState.user = { id: 7 };
    window.history.replaceState({}, "", "/dashboard");
  });

  it("shares the provider prefetch instead of requesting the same tags twice", async () => {
    vi.mocked(api.get).mockResolvedValue({ tags: [{ id: 1, name: "Manga" }] });
    const wrapper = ({ children }) => createElement(TagProvider, null, children);
    const { result } = renderHook(() => useTagOptions(), { wrapper });

    await waitFor(() => expect(result.current.availableTags).toEqual([{ id: 1, name: "Manga" }]));

    expect(api.get).toHaveBeenCalledTimes(1);
    expect(api.get).toHaveBeenCalledWith("/api/tags");
  });

  it("does not expose or reuse one account's cached tags for another account", async () => {
    let resolveSecondRequest;
    vi.mocked(api.get)
      .mockResolvedValueOnce({ tags: [{ id: 1, name: "Private" }] })
      .mockImplementationOnce(() => new Promise((resolve) => { resolveSecondRequest = resolve; }))
      .mockResolvedValueOnce({ tags: [] });
    const wrapper = ({ children }) => createElement(TagProvider, null, children);
    const { rerender, result } = renderHook(() => ({ options: useTagOptions(), tags: useTags() }), { wrapper });

    await waitFor(() => expect(result.current.options.availableTags).toEqual([{ id: 1, name: "Private" }]));

    act(() => {
      authState.user = { id: 8 };
      rerender();
    });

    expect(result.current.options.availableTags).toEqual([]);
    await expect(result.current.tags.searchTags("Private")).resolves.toEqual([]);
    await waitFor(() => {
      const tagListRequests = vi.mocked(api.get).mock.calls.filter(([url]) => url === "/api/tags");
      expect(tagListRequests).toHaveLength(2);
    });

    await act(async () => {
      resolveSecondRequest({ tags: [{ id: 2, name: "Other" }] });
    });
    await waitFor(() => expect(result.current.options.availableTags).toEqual([{ id: 2, name: "Other" }]));
  });
});
