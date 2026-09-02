import { afterEach, describe, expect, it, vi } from "vitest";

const resetDom = () => {
  document.querySelectorAll("script[data-panel-turnstile]").forEach((script) => script.remove());
  delete window.turnstile;
  vi.resetModules();
};

afterEach(resetDom);

describe("the Turnstile loader", () => {
  it("shares one script and one promise across concurrent mounts", async () => {
    const { loadTurnstile, TURNSTILE_SCRIPT_URL } = await import("./turnstile-loader.js");

    const first = loadTurnstile();
    const second = loadTurnstile();
    const scripts = document.querySelectorAll("script[data-panel-turnstile]");

    expect(first).toBe(second);
    expect(scripts).toHaveLength(1);
    expect(scripts[0]).toHaveAttribute("src", TURNSTILE_SCRIPT_URL);

    window.turnstile = { render: vi.fn() };
    scripts[0].dispatchEvent(new Event("load"));

    await expect(first).resolves.toBe(window.turnstile);
  });
});
