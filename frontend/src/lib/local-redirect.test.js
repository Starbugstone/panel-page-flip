import { describe, expect, it } from "vitest";

import { resolveLocalRedirect } from "./local-redirect";

describe("local login redirects", () => {
  it("preserves an internal path with its query and fragment", () => {
    expect(resolveLocalRedirect("/admin?tab=shares#row-2")).toBe("/admin?tab=shares#row-2");
  });

  it.each([
    ["an absolute URL", "https://example.com/steal-session"],
    ["a protocol-relative URL", "//example.com/steal-session"],
    ["a backslash path", "/safe\\\\example.com"],
    ["a control character", "/safe\nunsafe"],
    ["a missing destination", null],
  ])("falls back for %s", (_label, destination) => {
    expect(resolveLocalRedirect(destination)).toBe("/dashboard");
  });
});
