import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const api = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}));

vi.mock("@/lib/api", () => ({ api }));
vi.mock("@/lib/logger", () => ({
  logger: { log: vi.fn(), warn: vi.fn(), error: vi.fn() },
}));

import sessionManager from "./session-manager";

describe("sessionManager", () => {
  beforeEach(() => {
    api.get.mockReset();
    api.post.mockReset();
    sessionManager.stop();
    sessionManager.consecutiveFailures = 0;
    sessionManager.lastCheckTime = 0;
    sessionManager.checkInProgress = false;
    sessionManager.onSessionExpired = null;
    sessionManager.isActive = false;
  });

  afterEach(() => {
    sessionManager.stop();
  });

  it("treats a successful /api/me as a live session", async () => {
    api.get.mockResolvedValue({ user: { id: 1 } });

    await expect(sessionManager.checkSession()).resolves.toBe(true);
    expect(api.get).toHaveBeenCalledWith("/api/me", { notifyUnauthorized: false });
  });

  it("fires onSessionExpired once after a 401", async () => {
    const onSessionExpired = vi.fn();
    sessionManager.isActive = true;
    sessionManager.onSessionExpired = onSessionExpired;
    api.get.mockRejectedValue({ status: 401, message: "gone" });

    await expect(sessionManager.checkSession()).resolves.toBe(false);
    expect(onSessionExpired).toHaveBeenCalledOnce();
    expect(sessionManager.onSessionExpired).toBeNull();
  });

  it("treats a successful anonymous response as an expired session", async () => {
    const onSessionExpired = vi.fn();
    sessionManager.isActive = true;
    sessionManager.onSessionExpired = onSessionExpired;
    api.get.mockResolvedValue({ user: null });

    await expect(sessionManager.checkSession()).resolves.toBe(false);
    expect(onSessionExpired).toHaveBeenCalledOnce();
    expect(sessionManager.onSessionExpired).toBeNull();
  });

  it("skips overlapping checks rather than stacking requests", async () => {
    sessionManager.checkInProgress = true;

    await expect(sessionManager.checkSession()).resolves.toBe(true);
    expect(api.get).not.toHaveBeenCalled();
  });

  it("cannot expire a new session from a previous session's pending check", async () => {
    let reject;
    api.get.mockReturnValue(new Promise((_, rejectRequest) => { reject = rejectRequest; }));
    sessionManager.start({ onSessionExpired: vi.fn() });
    const check = sessionManager.checkSession();
    const onSessionExpired = vi.fn();
    sessionManager.start({ onSessionExpired });
    reject({ status: 401 });
    await check;
    expect(onSessionExpired).not.toHaveBeenCalled();
  });

  it("allows a new session check without an older completion unlocking it", async () => {
    let resolveOld, resolveNew;
    api.get.mockReturnValueOnce(new Promise((resolve) => { resolveOld = resolve; }))
      .mockReturnValueOnce(new Promise((resolve) => { resolveNew = resolve; }));
    sessionManager.start();
    const oldCheck = sessionManager.checkSession();
    sessionManager.start();
    const newCheck = sessionManager.checkSession();
    expect(api.get).toHaveBeenCalledTimes(2);
    resolveOld({ user: null });
    await oldCheck;
    expect(sessionManager.checkInProgress).toBe(true);
    resolveNew({ user: { id: 2 } });
    await expect(newCheck).resolves.toBe(true);
    expect(sessionManager.checkInProgress).toBe(false);
  });
});
