import { renderHook, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

const api = vi.hoisted(() => ({ get: vi.fn() }));

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
    api.get.mockRejectedValue(new Error("no session"));
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
