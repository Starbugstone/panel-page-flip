import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

import {
  AD_SAFE_ROUTES,
  adSenseScriptSrc,
  consentPlatformScriptSrc,
  isAdSafeRoute,
  isAdvertisingActive,
  publisherId,
  shouldOfferRewardedGate,
} from "@/lib/advertising";

/**
 * The route policy is the application-side boundary between Google and
 * somebody's comic collection, so these tests are mostly about what is *not*
 * allowed. Google-side page exclusions sit on top of this; they are a second
 * safeguard and cannot be relied on to be configured.
 */
describe("where advertising is allowed to run", () => {
  it.each([
    "/dashboard",
    "/read/12",
    "/settings",
    "/sharing",
    "/share/invitation/abc",
    "/dropbox-sync",
    "/admin",
    "/admin/users/4",
    "/privacy",
    "/terms",
    "/cookies",
    "/report-content",
    "/forgot-password",
    "/reset-password/abc",
    "/email-verification",
  ])("keeps advertising off %s", (pathname) => {
    expect(isAdSafeRoute(pathname)).toBe(false);
  });

  /**
   * The one that a prefix match would get wrong. The gate is explanatory text;
   * the session behind it lists real filenames, progress and failures.
   */
  it("separates the bulk-upload gate from the batch screen behind it", () => {
    expect(isAdSafeRoute("/upload/bulk")).toBe(true);
    expect(isAdSafeRoute("/upload/bulk/session")).toBe(false);
  });

  it("refuses anything it has never heard of", () => {
    expect(isAdSafeRoute("/upload/bulk/session/2")).toBe(false);
    expect(isAdSafeRoute("/some-future-page")).toBe(false);
    expect(isAdSafeRoute("")).toBe(false);
    expect(isAdSafeRoute(null)).toBe(false);
    expect(isAdSafeRoute(undefined)).toBe(false);
  });

  it("is not fooled by a trailing slash or a query string", () => {
    expect(isAdSafeRoute("/upload/")).toBe(true);
    expect(isAdSafeRoute("/upload/bulk?folder=3")).toBe(true);
    expect(isAdSafeRoute("/read/12/")).toBe(false);
    expect(isAdSafeRoute("/read/12?page=4")).toBe(false);
    expect(isAdSafeRoute("/")).toBe(true);
  });
});

describe("whether advertising is running at all", () => {
  it("needs both the switch and a publisher id", () => {
    expect(isAdvertisingActive({ enabled: true, client: "ca-pub-1234567890123456" })).toBe(true);
    expect(isAdvertisingActive({ enabled: true, client: null })).toBe(false);
    expect(isAdvertisingActive({ enabled: false, client: "ca-pub-1234567890123456" })).toBe(false);
  });

  /** What the frontend is handed when the configuration request failed. */
  it("treats nothing at all as off", () => {
    expect(isAdvertisingActive(undefined)).toBe(false);
    expect(isAdvertisingActive(null)).toBe(false);
    expect(isAdvertisingActive({})).toBe(false);
  });

  it("builds the site code URL around the publisher id", () => {
    expect(adSenseScriptSrc("ca-pub-1234567890123456")).toBe(
      "https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1234567890123456"
    );
  });
});

/**
 * Every branch except one opens the uploader. An offer that cannot be accepted
 * is a dead end, and bulk upload is a feature of this application rather than
 * something Google grants.
 */
describe("whether to offer the rewarded advertisement", () => {
  it("offers it only when advertising is on and Google's code actually loaded", () => {
    expect(shouldOfferRewardedGate({ gateRequired: true, scriptStatus: "ready" })).toBe(true);
  });

  it.each([
    ["advertising is off", { gateRequired: false, scriptStatus: "ready" }],
    ["the script was blocked", { gateRequired: true, scriptStatus: "unavailable" }],
    ["the script never answered", { gateRequired: true, scriptStatus: "loading" }],
    ["nothing was ever asked for", { gateRequired: true, scriptStatus: "idle" }],
    ["the server said nothing", { gateRequired: undefined, scriptStatus: "ready" }],
  ])("opens bulk upload directly when %s", (_reason, input) => {
    expect(shouldOfferRewardedGate(input)).toBe(false);
  });
});

/**
 * A forcing function for the allowlist.
 *
 * `AD_SAFE_ROUTES` is hand-maintained, and the file it sits in says the
 * allowlist direction exists so a new route cannot quietly acquire advertising.
 * That only holds if adding or renaming a route makes somebody look at this
 * list — so these read App.jsx, exactly as the shared route manifest's test
 * does, rather than comparing the constant to a copy of itself.
 */
describe("the allowlist against the router", () => {
  const frontendDir = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..");
  const appSource = readFileSync(resolve(frontendDir, "src/App.jsx"), "utf8");
  const reactRoutes = [...appSource.matchAll(/<Route\s+path="([^"]+)"/g)]
    .map((match) => match[1])
    .filter((path) => path !== "*");

  it("allows no route the router does not have", () => {
    // A renamed route leaves its old name behind here, where it protects
    // nothing, while the new one is ad-free by accident rather than by choice.
    expect(reactRoutes).toEqual(expect.arrayContaining([...AD_SAFE_ROUTES]));
  });

  it("treats every other route in the application as ad-free", () => {
    const notAllowed = reactRoutes.filter((path) => !AD_SAFE_ROUTES.includes(path));

    for (const path of notAllowed) {
      // Parameterised routes are checked through a concrete instance, which is
      // what isAdSafeRoute is actually given at runtime.
      expect(isAdSafeRoute(path.replace(/:[^/]+/g, "1"))).toBe(false);
    }
  });

  /**
   * Deliberately brittle. Widening the allowlist is a decision about where
   * Google may place an advertisement on a site full of unvetted uploads, and
   * it should not be possible to make it without editing a test that says so.
   */
  it("holds exactly the four pages that carry no user content", () => {
    expect([...AD_SAFE_ROUTES]).toEqual(["/", "/login", "/upload", "/upload/bulk"]);
  });
});

describe("the consent platform URL", () => {
  it("uses the publisher id in the form Google's own URLs take", () => {
    expect(publisherId("ca-pub-1234567890123456")).toBe("pub-1234567890123456");
    expect(consentPlatformScriptSrc("ca-pub-1234567890123456"))
      .toBe("https://fundingchoicesmessages.google.com/i/pub-1234567890123456?ers=1");
  });

  it("does not fall over on a missing client", () => {
    expect(publisherId(null)).toBe("");
    expect(publisherId(undefined)).toBe("");
  });
});
