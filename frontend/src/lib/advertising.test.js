import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  AD_SAFE_ROUTES,
  AD_SAFE_ROUTE_LABELS,
  adSafeRouteSentence,
  adSenseScriptSrc,
  consentPlatformScriptSrc,
  isAdSafeRoute,
  isAdvertisingActive,
  publisherId,
  settleOnce,
} from "@/lib/advertising";
import { BULK_UPLOAD_QUEUE_ROUTE } from "@/lib/bulk-upload-routes";

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

  /**
   * The privacy policy names these pages and says advertising appears nowhere
   * else. Adding a route without describing it would publish a false statement
   * of fact on an indexable page, and nothing else would notice.
   */
  it("describes every allowed page, and only the allowed pages", () => {
    expect(Object.keys(AD_SAFE_ROUTE_LABELS).sort()).toEqual([...AD_SAFE_ROUTES].sort());
  });

  /**
   * The operator-facing diagnostic (`app:diagnose-google-integrations`) prints this
   * list so somebody can hold it up against their AdSense page exclusions. It
   * cannot import the allowlist — it is PHP, on the other side of the release —
   * so the copy is checked from here, where the authoritative list lives.
   */
  it("is the list the backend diagnostic reports to an operator", () => {
    const command = readFileSync(
      resolve(frontendDir, "..", "backend/src/Command/DiagnoseGoogleIntegrationsCommand.php"),
      "utf8",
    );
    const declaration = command.match(/AD_SAFE_ROUTES = \[(.*?)\];/s);

    expect(declaration).not.toBeNull();
    expect([...declaration[1].matchAll(/'([^']+)'/g)].map((match) => match[1]))
      .toEqual([...AD_SAFE_ROUTES]);
  });

  it("reads the allowed pages as the sentence the privacy policy prints", () => {
    expect(adSafeRouteSentence()).toBe(
      "the landing page, the login page, the single-comic upload form and the bulk-upload information page",
    );
  });

  /**
   * The gate navigates to `BULK_UPLOAD_QUEUE_ROUTE` and App.jsx spells the same path
   * again as a literal. The two are what put the batch screen on the ad-free
   * side of the boundary, so a rename that reaches only one of them either
   * dead-ends the gate or lands the batch on a path nothing has classified.
   */
  it("routes the batch screen at the path the gate sends people to", () => {
    expect(reactRoutes).toContain(BULK_UPLOAD_QUEUE_ROUTE);
    expect(isAdSafeRoute(BULK_UPLOAD_QUEUE_ROUTE)).toBe(false);
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

/**
 * Google's callbacks arrive late, twice, or never. A second settle would leave
 * a cached answer contradicting what actually happened.
 */
describe("settling exactly once", () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it("keeps the first outcome and ignores every later one", async () => {
    const { promise, settle } = settleOnce(1000);

    settle("viewed");
    settle("dismissed");

    await expect(promise).resolves.toBe("viewed");
  });

  it("resolves unavailable when nothing answers in time", async () => {
    const { promise } = settleOnce(1000);

    vi.advanceTimersByTime(1000);

    await expect(promise).resolves.toBe("unavailable");
  });

  it("stops the clock once an answer arrives", async () => {
    const { promise, settle } = settleOnce(1000);

    settle("ready");
    vi.advanceTimersByTime(5000);

    await expect(promise).resolves.toBe("ready");
  });
});
