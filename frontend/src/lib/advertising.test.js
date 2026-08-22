import { describe, expect, it } from "vitest";

import {
  AD_SAFE_ROUTES,
  adSenseScriptSrc,
  isAdSafeRoute,
  isAdvertisingActive,
  shouldOfferRewardedGate,
} from "@/lib/advertising";

/**
 * The route policy is the application-side boundary between Google and
 * somebody's comic collection, so these tests are mostly about what is *not*
 * allowed. Google-side page exclusions sit on top of this; they are a second
 * safeguard and cannot be relied on to be configured.
 */
describe("where advertising is allowed to run", () => {
  it("allows only the four application-owned pages", () => {
    expect([...AD_SAFE_ROUTES]).toEqual(["/", "/login", "/upload", "/upload/bulk"]);
  });

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
