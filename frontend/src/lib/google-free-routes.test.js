import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { matchRoutes } from "react-router-dom";
import { describe, expect, it } from "vitest";

import { GOOGLE_FREE_ROUTES, isGoogleFreeRoute } from "@/lib/google-free-routes";
import { AD_SAFE_ROUTES } from "@/lib/advertising";
import { analyticsPageFor } from "@/lib/google-analytics";

const frontendDir = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..");
const manifest = JSON.parse(readFileSync(resolve(frontendDir, "../backend/config/frontend-routes.json"), "utf8"));

describe("the Google-free legal routes", () => {
  /**
   * The backend copy is what makes the CSP for these routes strict and what the
   * nginx image bakes into their locations. Two lists that can disagree would
   * mean the application refused to load Google on a page whose header still
   * allowed it, or the reverse.
   */
  it("is the same set the backend route manifest declares", () => {
    expect([...GOOGLE_FREE_ROUTES].sort()).toEqual([...manifest.googleFree].sort());
  });

  it("names only routes the application actually serves", () => {
    const known = [...manifest.indexable.map((route) => route.path), ...manifest.noindex];

    for (const path of GOOGLE_FREE_ROUTES) {
      expect(known, path).toContain(path);
    }
  });

  it("matches exactly, ignoring a trailing slash and any query", () => {
    expect(isGoogleFreeRoute("/privacy")).toBe(true);
    expect(isGoogleFreeRoute("/privacy/")).toBe(true);
    expect(isGoogleFreeRoute("/PrIvAcY///?from=footer#choices")).toBe(true);
    expect(isGoogleFreeRoute("/privacy?from=footer")).toBe(true);
    expect(isGoogleFreeRoute("/privacy-policy")).toBe(false);
    expect(isGoogleFreeRoute("/")).toBe(false);
    expect(isGoogleFreeRoute(undefined)).toBe(false);
  });

  /**
   * The check has to agree with the router: a path that renders a legal page but
   * is not classified as one would load Google on it.
   */
  it.each([
    ["/%70rivacy", true],
    ["/%50RIVACY/", true],
    ["/%63ookies", true],
    ["/t%65rms?from=footer", true],
    ["/privacy%2F", false],
    ["/%2570rivacy", false],
    ["/%70rivacy%3Ffrom", false],
    ["/%E0%A4%A", false],
    ["/privacy/%E0%A4%A", false],
  ])("classifies %s the way the router routes it", (path, expected) => {
    const routes = GOOGLE_FREE_ROUTES.map((route) => ({ path: route }));
    const [pathname] = path.split("?");

    expect(isGoogleFreeRoute(path)).toBe(expected);
    expect(matchRoutes(routes, pathname) !== null).toBe(expected);
  });

  it("carries no advertising and produces no page view", () => {
    for (const path of GOOGLE_FREE_ROUTES) {
      expect(AD_SAFE_ROUTES, path).not.toContain(path);
      expect(analyticsPageFor(path), path).toBeNull();
    }
  });

  /**
   * Not a legal-policy page, so it is outside the CSP guarantee — but a page
   * view saying somebody opened the illegal-content reporting workflow is
   * measurement of a legally sensitive act that nothing here needs.
   */
  it("keeps the content-reporting form out of measurement too", () => {
    expect(analyticsPageFor("/report-content")).toBeNull();
  });
});
