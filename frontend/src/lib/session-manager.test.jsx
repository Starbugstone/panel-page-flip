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

  it("skips overlapping checks rather than stacking requests", async () => {
    sessionManager.checkInProgress = true;

    await expect(sessionManager.checkSession()).resolves.toBe(true);
    expect(api.get).not.toHaveBeenCalled();
  });
});
