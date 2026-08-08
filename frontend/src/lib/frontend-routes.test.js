import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

const frontendDir = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..");
const manifest = JSON.parse(readFileSync(resolve(frontendDir, "../backend/config/frontend-routes.json"), "utf8"));
const appSource = readFileSync(resolve(frontendDir, "src/App.jsx"), "utf8");

const reactRoutes = [...appSource.matchAll(/<Route\s+path="([^"]+)"/g)]
  .map((match) => match[1])
  .filter((path) => path !== "*");

const dynamicPattern = (path) => `^${path.replace(/:[^/]+/g, "[^/]+")}$`;

describe("shared frontend route manifest", () => {
  it("contains every React route exactly once", () => {
    const exact = [
      ...manifest.indexable.map((route) => route.path),
      ...manifest.noindex,
    ];
    const patterns = manifest.noindexPatterns;

    expect(new Set([...exact, ...patterns]).size).toBe(exact.length + patterns.length);
    expect(exact.sort()).toEqual(reactRoutes.filter((path) => !path.includes(":")).sort());
    expect(patterns.sort()).toEqual(reactRoutes.filter((path) => path.includes(":")).map(dynamicPattern).sort());
  });

  it("only places public informational pages in the sitemap", () => {
    expect(manifest.indexable.map((route) => route.path)).toEqual(["/", "/privacy", "/terms", "/cookies"]);
  });
});
