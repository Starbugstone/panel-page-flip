import { act, renderHook, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));

vi.mock("@/lib/api", () => ({ api, UNAUTHORIZED_EVENT: "unauthorized" }));
vi.mock("@/lib/logger", () => ({
  logger: { log: vi.fn(), warn: vi.fn(), error: vi.fn() },
}));
vi.mock("@/lib/session-manager", () => ({
  default: {
    start: vi.fn(),
    stop: vi.fn(),
    forceSessionCheck: vi.fn().mockResolvedValue(true),
  },
}));

import { AuthProvider, useAuth } from "./use-auth";
import sessionManager from "@/lib/session-manager";

const wrapper = ({ children }) => <AuthProvider>{children}</AuthProvider>;

const signedInAs = async (user) => {
  api.get.mockResolvedValue({ user });
  const { result } = renderHook(() => useAuth(), { wrapper });
  await waitFor(() => expect(result.current.loading).toBe(false));
  return result;
};

/**
 * `isAdmin` is what decides whether the admin nav item and the admin route are
 * offered. It is not a security boundary — the API refuses on its own, and is
 * the only thing that counts — but it used to be spelled out separately at
 * each of the three places that asked, one of which read `user.roles` without
 * checking it was there.
 */
describe("useAuth isAdmin", () => {
  afterEach(() => {
    api.get.mockReset();
  });

  it("is false before anybody has signed in", async () => {
    api.get.mockResolvedValue({ user: null });
    const { result } = renderHook(() => useAuth(), { wrapper });

    await waitFor(() => expect(result.current.loading).toBe(false));

    expect(result.current.isAdmin).toBe(false);
    expect(result.current.isAuthenticated).toBe(false);
  });

  it("is false for an ordinary account", async () => {
    const result = await signedInAs({ id: 1, roles: ["ROLE_USER"] });

    expect(result.current.isAdmin).toBe(false);
  });

  it("is true for an account holding the role", async () => {
    const result = await signedInAs({ id: 2, roles: ["ROLE_USER", "ROLE_ADMIN"] });

    expect(result.current.isAdmin).toBe(true);
  });

  it("is false rather than throwing when the account carries no roles at all", async () => {
    const result = await signedInAs({ id: 3 });

    expect(result.current.isAdmin).toBe(false);
  });

  it("is a boolean, so it can be passed straight to a prop", async () => {
    const result = await signedInAs({ id: 4 });

    expect(typeof result.current.isAdmin).toBe("boolean");
  });
});

function deferred() {
  let resolve, reject;
  const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
  return { promise, resolve, reject };
}

describe("authentication request ordering", () => {
  afterEach(() => vi.clearAllMocks());

  it("ignores a failed manual refresh from before a new login", async () => {
    const result = await signedInAs({ id: 1 });
    const pending = deferred();
    sessionManager.forceSessionCheck.mockReturnValueOnce(pending.promise);
    let refresh;
    act(() => { refresh = result.current.refreshSession(); });
    api.post.mockResolvedValue({ user: { id: 2 } });
    await act(async () => result.current.login("reader@example.com", "password"));
    await act(async () => { pending.resolve(false); await refresh; });
    expect(result.current.user).toEqual({ id: 2 });
  });

  it("cannot restore a session from a check that started before logout", async () => {
    const pending = deferred();
    api.get.mockReturnValue(pending.promise);
    api.post.mockResolvedValue({});
    const { result } = renderHook(() => useAuth(), { wrapper });

    await act(async () => result.current.logout());
    await act(async () => pending.resolve({ user: { id: 1, roles: ["ROLE_ADMIN"] } }));

    expect(result.current.isAuthenticated).toBe(false);
    expect(result.current.isAdmin).toBe(false);
  });

  it("does not let a stale anonymous check undo a successful login", async () => {
    const pending = deferred();
    api.get.mockReturnValue(pending.promise);
    api.post.mockResolvedValue({ user: { id: 2 } });
    const { result } = renderHook(() => useAuth(), { wrapper });

    await act(async () => result.current.login("reader@example.com", "password"));
    await act(async () => pending.resolve({ user: null }));

    expect(result.current.user).toEqual({ id: 2 });
  });

  it("does not let a stale failed check clear a newer account", async () => {
    const pending = deferred();
    api.get.mockReturnValue(pending.promise);
    api.post.mockResolvedValue({ user: { id: 3 } });
    const { result } = renderHook(() => useAuth(), { wrapper });

    await act(async () => result.current.login("reader@example.com", "password"));
    await act(async () => pending.reject(new Error("Offline")));

    expect(result.current.user).toEqual({ id: 3 });
  });

  it("keeps an expired session cleared when an older check completes", async () => {
    const pending = deferred();
    api.get.mockReturnValue(pending.promise);
    const { result } = renderHook(() => useAuth(), { wrapper });

    act(() => window.dispatchEvent(new Event("unauthorized")));
    await act(async () => pending.resolve({ user: { id: 4 } }));

    expect(result.current.isAuthenticated).toBe(false);
    expect(result.current.sessionExpired).toBe(true);
  });

  it("ignores a login response that arrives after logout", async () => {
    api.get.mockResolvedValue({ user: null });
    const pending = deferred();
    api.post.mockReturnValueOnce(pending.promise).mockResolvedValue({});
    const { result } = renderHook(() => useAuth(), { wrapper });
    await waitFor(() => expect(result.current.loading).toBe(false));

    let login;
    act(() => { login = result.current.login("reader@example.com", "password"); });
    await act(async () => result.current.logout());
    await act(async () => { pending.resolve({ user: { id: 5 } }); await login; });

    expect(result.current.user).toBeNull();
  });
});
