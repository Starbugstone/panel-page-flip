import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CookieNotice } from "@/components/CookieNotice.jsx";
import { ANALYTICS_CONSENT_STORAGE_KEY, persistAnalyticsConsent } from "@/lib/analytics-consent-storage";

const { publicConfig, consent } = vi.hoisted(() => ({
  publicConfig: {
    adsense: { enabled: false, client: null },
    analytics: { enabled: false, measurementId: null },
    isLoading: false,
  },
  consent: {
    isAnalyticsDialogOpen: false, canOpenPreferences: false, provider: null,
    analyticsConsent: "denied", openPreferences: vi.fn(),
    acceptAnalytics: vi.fn(), rejectAnalytics: vi.fn(),
  },
}));

vi.mock("@/components/config/PublicConfigProvider.jsx", () => ({
  usePublicConfig: () => publicConfig,
}));
vi.mock("@/components/consent/ConsentProvider.jsx", () => ({
  useConsent: () => consent,
}));

const CLIENT = "ca-pub-1234567890123456";

const renderNotice = () => render(
  <MemoryRouter initialEntries={["/"]}><CookieNotice /></MemoryRouter>
);

beforeEach(() => {
  vi.clearAllMocks();
  window.localStorage.clear();
  publicConfig.adsense = { enabled: false, client: null };
  publicConfig.analytics = { enabled: false, measurementId: null };
  publicConfig.isLoading = false;
  consent.isAnalyticsDialogOpen = false;
  consent.canOpenPreferences = false;
  consent.provider = null;
  consent.analyticsConsent = "denied";
});

/**
 * The notice describes; it never collects. "Got it" acknowledges and grants
 * nothing, so each wording has to name the control that does decide — Google's
 * panel where advertising is on, this application's Analytics preferences where
 * only measurement is.
 */
describe("what the cookie notice claims", () => {
  it("says no advertising storage is used where none is", async () => {
    renderNotice();

    expect(await screen.findByText(/no advertising or analytics cookies are used/i)).toBeInTheDocument();
  });

  it("points at the Google panel where advertising is on", async () => {
    publicConfig.adsense = { enabled: true, client: CLIENT };

    renderNotice();

    expect(await screen.findByText(/you accept or reject in the google privacy choices panel/i)).toBeInTheDocument();
    expect(screen.queryByText(/no advertising or analytics cookies are used/i)).not.toBeInTheDocument();
  });

  it("names Analytics preferences where only measurement is on", async () => {
    publicConfig.analytics = { enabled: true, measurementId: "G-PSW1MY7HB4" };

    renderNotice();

    expect(await screen.findByText(/optional analytics uses additional storage only if you accept it/i)).toBeInTheDocument();
    expect(await screen.findByText(/analytics preferences/i)).toBeInTheDocument();
    expect(screen.queryByText(/advertising on some pages/i)).not.toBeInTheDocument();
  });

  it("describes both purposes as one Google panel where both are on", async () => {
    publicConfig.adsense = { enabled: true, client: CLIENT };
    publicConfig.analytics = { enabled: true, measurementId: "G-PSW1MY7HB4" };

    renderNotice();

    expect(
      await screen.findByText(/advertising on some pages and optional analytics use additional storage/i)
    ).toBeInTheDocument();
  });

  /**
   * The dismissal is permanent and the wordings contradict each other, so
   * saying the wrong one first is not a flicker — it is the only version a user
   * who pressed "Got it" during the round trip will ever have been shown.
   */
  it("says nothing at all until the server has answered", () => {
    publicConfig.adsense = { enabled: true, client: CLIENT };
    publicConfig.isLoading = true;

    renderNotice();

    expect(screen.queryByRole("complementary", { name: "Cookie notice" })).not.toBeInTheDocument();
    expect(screen.queryByText(/no advertising or analytics cookies are used/i)).not.toBeInTheDocument();
  });

  /**
   * Both occupy the same corner, and only one of them is a question. A notice
   * overlapping the consent request would make the choice harder to reach than
   * the acknowledgement beside it.
   */
  it("stands aside while the analytics consent request is on screen", () => {
    publicConfig.analytics = { enabled: true, measurementId: "G-PSW1MY7HB4" };
    consent.isAnalyticsDialogOpen = true;

    renderNotice();

    expect(screen.queryByRole("complementary", { name: "Cookie notice" })).not.toBeInTheDocument();
  });

  it("stays dismissed once it has been dismissed", async () => {
    renderNotice();

    await userEvent.click(await screen.findByRole("button", { name: "Got it" }));

    expect(screen.queryByRole("complementary", { name: "Cookie notice" })).not.toBeInTheDocument();
  });
});

it.each(["google", "local"])("opens %s preferences while notice dismissal leaves consent unchanged", async (provider) => {
  publicConfig.analytics = { enabled: true, measurementId: "G-PSW1MY7HB4" };
  if (provider === "google") publicConfig.adsense = { enabled: true, client: CLIENT };
  else persistAnalyticsConsent("denied");
  const savedConsent = window.localStorage.getItem(ANALYTICS_CONSENT_STORAGE_KEY);
  consent.canOpenPreferences = true;
  consent.provider = provider;
  renderNotice();
  const label = provider === "google" ? "Privacy choices" : "Analytics preferences";
  await userEvent.click(screen.getByRole("button", { name: label }));
  expect(consent.openPreferences).toHaveBeenCalledTimes(1);
  await userEvent.click(screen.getByRole("button", { name: "Got it" }));
  expect(consent.openPreferences).toHaveBeenCalledTimes(1);
  expect(consent.acceptAnalytics).not.toHaveBeenCalled();
  expect(consent.rejectAnalytics).not.toHaveBeenCalled();
  expect(consent.analyticsConsent).toBe("denied");
  expect(window.localStorage.getItem(ANALYTICS_CONSENT_STORAGE_KEY)).toBe(savedConsent);
  expect(screen.queryByRole("complementary", { name: "Cookie notice" })).not.toBeInTheDocument();
});
