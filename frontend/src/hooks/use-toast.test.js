import { beforeEach, describe, expect, it, vi } from "vitest";

const notifications = vi.hoisted(() => ({
  default: vi.fn(),
  error: vi.fn(),
  warning: vi.fn(),
  success: vi.fn(),
}));

vi.mock("sonner", () => ({
  toast: Object.assign(notifications.default, {
    error: notifications.error,
    warning: notifications.warning,
    success: notifications.success,
  }),
}));

import { toast } from "./use-toast";

describe("toast adapter", () => {
  beforeEach(() => vi.clearAllMocks());

  it.each([
    [undefined, "default"],
    ["destructive", "error"],
    ["warning", "warning"],
    ["success", "success"],
  ])("maps %s notifications onto the shared Sonner host", (variant, method) => {
    toast({ title: "Saved", description: "The change is live.", variant });

    expect(notifications[method]).toHaveBeenCalledWith("Saved", {
      description: "The change is live.",
    });
  });
});
