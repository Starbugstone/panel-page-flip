// @vitest-environment jsdom

import { createElement } from "react";
import { act, renderHook, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { api } from "@/lib/api";
import { TagProvider, useTags } from "./use-tags";

const { authState, toast } = vi.hoisted(() => ({ authState: { user: { id: 7 } }, toast: vi.fn() }));

vi.mock("./use-auth", () => ({ useAuth: () => ({ user: authState.user }) }));
vi.mock("./use-toast", () => ({ useToast: () => ({ toast }) }));
vi.mock("@/lib/api", () => ({ api: { get: vi.fn() } }));

const wrapper = ({ children }) => createElement(TagProvider, null, children);

/**
 * A tag created while uploading or editing is added to the cache instead of
 * being fetched back. The cache is per-account, so adding to it has to say
 * which account it now belongs to — otherwise the tag is stored and then
 * hidden by the very check that keeps accounts apart.
 */
describe("adding a freshly created tag to the cache", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    authState.user = { id: 7 };
    window.history.replaceState({}, "", "/dashboard");
  });

  it("shows a tag created before the tag list could be loaded", async () => {
    vi.mocked(api.get).mockRejectedValue(new Error("Unable to reach the server"));
    const { result } = renderHook(() => useTags(), { wrapper });

    await waitFor(() => expect(api.get).toHaveBeenCalled());
    expect(result.current.tags).toEqual([]);

    act(() => result.current.addTagToCache({ id: 9, name: "Just created" }));

    expect(result.current.tags).toEqual([{ id: 9, name: "Just created" }]);
  });

  it("does not carry the previous account's tags into the new account's cache", async () => {
    vi.mocked(api.get)
      .mockResolvedValueOnce({ tags: [{ id: 1, name: "Private" }] })
      .mockImplementation(() => new Promise(() => {}));
    const { rerender, result } = renderHook(() => useTags(), { wrapper });

    await waitFor(() => expect(result.current.tags).toEqual([{ id: 1, name: "Private" }]));

    act(() => {
      authState.user = { id: 8 };
      rerender();
    });
    act(() => result.current.addTagToCache({ id: 9, name: "Just created" }));

    expect(result.current.tags).toEqual([{ id: 9, name: "Just created" }]);
  });

  it("ignores a tag added while signed out", async () => {
    authState.user = null;
    const { result } = renderHook(() => useTags(), { wrapper });

    act(() => result.current.addTagToCache({ id: 9, name: "Just created" }));

    expect(result.current.tags).toEqual([]);
    expect(api.get).not.toHaveBeenCalled();
  });
});
