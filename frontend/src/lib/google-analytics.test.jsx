import { afterEach, describe, expect, it, vi } from "vitest";

import {
  ANALYTICS_SCRIPT_ID,
  analyticsPageFor,
  disableGoogleAnalytics,
  guardGoogleAnalyticsNavigation,
  loadGoogleAnalytics,
  resetGoogleAnalyticsForTesting,
  sendAnalyticsPageView,
  setAnalyticsPageContext,
} from "@/lib/google-analytics";

const MEASUREMENT_ID = "G-PSW1MY7HB4";

afterEach(() => {
  resetGoogleAnalyticsForTesting();
  document.cookie.split(";").forEach((cookie) => {
    document.cookie = `${cookie.split("=")[0].trim()}=; Max-Age=0; Path=/`;
  });
});

describe("the analytics route boundary", () => {
  it.each([
    "/reset-password/secret-token",
    "/share/invitation/secret-token",
    "/read/42",
    "/admin",
    "/admin/users/42",
    "/email-verification",
    "/somewhere/new",
  ])("sends nothing for %s", (pathname) => {
    expect(analyticsPageFor(pathname)).toBeNull();
  });

  it("uses stable labels and strips query strings from allowed routes", () => {
    expect(analyticsPageFor("/dashboard?search=private-title")).toEqual({
      path: "/dashboard",
      title: "Library",
    });
    expect(analyticsPageFor("/upload/bulk/session?folder=private-folder")).toEqual({
      path: "/upload/bulk/session",
      title: "Bulk upload session",
    });
  });
});

describe("loading Google Analytics", () => {
  it("loads and configures the tag once with privacy-preserving defaults", async () => {
    const pageFields = {
      page_location: "https://comics.example/dashboard",
      page_title: "Library",
      page_referrer: "",
    };
    const first = loadGoogleAnalytics(MEASUREMENT_ID, { pageFields });
    const second = loadGoogleAnalytics(MEASUREMENT_ID, { pageFields });
    const script = document.getElementById(ANALYTICS_SCRIPT_ID);

    expect(script.src).toContain(`id=${MEASUREMENT_ID}`);
    expect(script.async).toBe(true);
    expect(document.querySelectorAll(`#${ANALYTICS_SCRIPT_ID}`)).toHaveLength(1);

    script.dispatchEvent(new Event("load"));
    await expect(first).resolves.toBe("ready");
    await expect(second).resolves.toBe("ready");

    const config = window.dataLayer.find((entry) => entry[0] === "config");
    expect(config[1]).toBe(MEASUREMENT_ID);
    expect(config[2]).toMatchObject({
      send_page_view: false,
      allow_google_signals: false,
      allow_ad_personalization_signals: false,
      cookie_update: false,
      ...pageFields,
    });
    expect(config[2].cookie_expires).toBeLessThanOrEqual(13 * 31 * 24 * 60 * 60);
  });

  it("reports a blocker without throwing", async () => {
    const loading = loadGoogleAnalytics(MEASUREMENT_ID);
    document.getElementById(ANALYTICS_SCRIPT_ID).dispatchEvent(new Event("error"));

    await expect(loading).resolves.toBe("unavailable");
  });

  it("sends only the supplied sanitized page fields", async () => {
    const loading = loadGoogleAnalytics(MEASUREMENT_ID);
    document.getElementById(ANALYTICS_SCRIPT_ID).dispatchEvent(new Event("load"));
    await loading;

    sendAnalyticsPageView(MEASUREMENT_ID, {
      page_location: "https://comics.example/dashboard",
      page_title: "Library",
      page_referrer: "",
    });

    expect(window.dataLayer.at(-1)).toEqual(expect.objectContaining({
      0: "event",
      1: "page_view",
      2: {
        page_location: "https://comics.example/dashboard",
        page_title: "Library",
        page_referrer: "",
        send_to: MEASUREMENT_ID,
      },
    }));
  });

  it("replaces the automatic event context before collection resumes", async () => {
    const loading = loadGoogleAnalytics(MEASUREMENT_ID);
    document.getElementById(ANALYTICS_SCRIPT_ID).dispatchEvent(new Event("load"));
    await loading;

    const fields = {
      page_location: "https://comics.example/settings",
      page_title: "Settings",
      page_referrer: "",
    };
    expect(setAnalyticsPageContext(fields)).toBe(true);
    expect(window.dataLayer.at(-1)).toEqual(expect.objectContaining({
      0: "set",
      1: fields,
    }));
  });

  it("stops collection and removes GA cookies when consent is withdrawn", async () => {
    const loading = loadGoogleAnalytics(MEASUREMENT_ID);
    document.getElementById(ANALYTICS_SCRIPT_ID).dispatchEvent(new Event("load"));
    await loading;
    document.cookie = "_ga=client; Path=/";
    document.cookie = "_ga_PSW1MY7HB4=session; Path=/";

    disableGoogleAnalytics(MEASUREMENT_ID);

    expect(window[`ga-disable-${MEASUREMENT_ID}`]).toBe(true);
    expect(document.cookie).not.toContain("_ga=");
    expect(document.cookie).not.toContain("_ga_PSW1MY7HB4=");
  });
});

describe("SPA navigation protection", () => {
  it("disables collection before push, replace, and back/forward navigation", () => {
    const listeners = new Map();
    const originalPushState = vi.fn();
    const originalReplaceState = vi.fn();
    const win = {
      history: { pushState: originalPushState, replaceState: originalReplaceState },
      addEventListener: vi.fn((name, callback) => listeners.set(name, callback)),
      removeEventListener: vi.fn((name) => listeners.delete(name)),
    };
    const stop = guardGoogleAnalyticsNavigation(MEASUREMENT_ID, { win });

    win[`ga-disable-${MEASUREMENT_ID}`] = false;
    win.history.pushState({}, "", "/dashboard?search=private");
    expect(win[`ga-disable-${MEASUREMENT_ID}`]).toBe(true);
    expect(originalPushState).toHaveBeenCalled();

    win[`ga-disable-${MEASUREMENT_ID}`] = false;
    win.history.replaceState({}, "", "/read/42");
    expect(win[`ga-disable-${MEASUREMENT_ID}`]).toBe(true);

    win[`ga-disable-${MEASUREMENT_ID}`] = false;
    listeners.get("popstate")();
    expect(win[`ga-disable-${MEASUREMENT_ID}`]).toBe(true);

    stop();
    expect(win.history.pushState).toBe(originalPushState);
    expect(win.history.replaceState).toBe(originalReplaceState);
  });
});
