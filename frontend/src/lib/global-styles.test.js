import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const styles = readFileSync(resolve(import.meta.dirname, "../index.css"), "utf8");

describe("the global scrollbar theme", () => {
  it("uses slim theme-aware scrollbars in Firefox", () => {
    expect(styles).toMatch(/\*\s*\{[^}]*scrollbar-width:\s*thin;/s);
    expect(styles).toMatch(/scrollbar-color:\s*hsl\(var\(--scrollbar-thumb\)\)\s+transparent;/);
    expect(styles).toContain("--scrollbar-thumb-hover:");
  });

  it("uses rounded transparent-track scrollbars in Chromium and Safari", () => {
    expect(styles).toMatch(/\*::-webkit-scrollbar\s*\{[^}]*width:\s*0\.625rem;[^}]*height:\s*0\.625rem;/s);
    expect(styles).toMatch(/\*::-webkit-scrollbar-track\s*\{[^}]*background:\s*transparent;/s);
    expect(styles).toMatch(/\*::-webkit-scrollbar-thumb\s*\{[^}]*border-radius:\s*9999px;[^}]*background-clip:\s*padding-box;/s);
    expect(styles).toMatch(/\*::-webkit-scrollbar-thumb:hover\s*\{[^}]*var\(--scrollbar-thumb-hover\)/s);
  });
});
