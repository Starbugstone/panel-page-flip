import { render, waitFor } from "@testing-library/react";
import { MemoryRouter, useNavigate } from "react-router-dom";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { GoogleAnalyticsProvider } from "@/components/analytics/GoogleAnalyticsProvider.jsx";
import { loadGoogleAnalytics, sendAnalyticsPageView, setAnalyticsPageContext } from "@/lib/google-analytics";
import { observeAnalyticsConsent } from "@/lib/google-consent";

const state = vi.hoisted(() => ({
  analytics: { enabled: true, measurementId: "G-PSW1MY7HB4" },
  consent: { enabled: true, client: "ca-pub-1234567890123456" },
  isLoading: false,
  consentCallback: null,
}));

vi.mock("@/components/ads/AdSenseProvider.jsx", () => ({
  useAdSense: () => state,
}));
vi.mock("@/lib/google-consent", () => ({
  PRIVACY_CHOICES_OPENING_EVENT: "panel-page-flip:privacy-choices-opening",
  observeAnalyticsConsent: vi.fn((_client, { onChange }) => {
    state.consentCallback = onChange;
    return vi.fn();
  }),
}));
vi.mock("@/lib/google-analytics", async (importOriginal) => ({
  ...(await importOriginal()),
  loadGoogleAnalytics: vi.fn(() => Promise.resolve("ready")),
  sendAnalyticsPageView: vi.fn(),
  setAnalyticsPageContext: vi.fn(),
  disableGoogleAnalytics: vi.fn(),
}));

function Navigation() {
  const navigate = useNavigate();
  return <button onClick={() => navigate("/settings")}>Settings</button>;
}

const renderProvider = (path = "/dashboard") => render(
  <MemoryRouter initialEntries={[path]}>
    <GoogleAnalyticsProvider><Navigation /></GoogleAnalyticsProvider>
  </MemoryRouter>
);

beforeEach(() => {
  vi.clearAllMocks();
  state.analytics = { enabled: true, measurementId: "G-PSW1MY7HB4" };
  state.consent = { enabled: true, client: "ca-pub-1234567890123456" };
  state.isLoading = false;
  state.consentCallback = null;
});

describe("privacy-first analytics startup", () => {
  it("loads no analytics tag before the CMP grants analytics storage", async () => {
    renderProvider();

    await waitFor(() => expect(observeAnalyticsConsent).toHaveBeenCalled());
    expect(loadGoogleAnalytics).not.toHaveBeenCalled();
    expect(sendAnalyticsPageView).not.toHaveBeenCalled();

    state.consentCallback("denied");
    expect(loadGoogleAnalytics).not.toHaveBeenCalled();
  });

  it("loads after consent and emits one sanitized view per allowed SPA route", async () => {
    renderProvider();
    await waitFor(() => expect(state.consentCallback).toBeTypeOf("function"));

    state.consentCallback("granted");
    await waitFor(() => expect(loadGoogleAnalytics).toHaveBeenCalledTimes(1));
    expect(loadGoogleAnalytics.mock.calls[0][1].pageFields).toEqual({
      page_location: "http://localhost:8080/dashboard",
      page_title: "Library",
      page_referrer: "",
    });
    await waitFor(() => expect(sendAnalyticsPageView).toHaveBeenCalledTimes(1));
    expect(setAnalyticsPageContext).toHaveBeenCalledWith({
      page_location: "http://localhost:8080/dashboard",
      page_title: "Library",
      page_referrer: "",
    });
    expect(sendAnalyticsPageView.mock.calls[0][1]).toEqual({
      page_location: "http://localhost:8080/dashboard",
      page_title: "Library",
      page_referrer: "",
    });

    await userEvent.click(document.querySelector("button"));
    await waitFor(() => expect(sendAnalyticsPageView).toHaveBeenCalledTimes(2));
    expect(sendAnalyticsPageView.mock.calls[1][1].page_location).toBe("http://localhost:8080/settings");
  });

  it("does not load or send on tokenized routes even after consent", async () => {
    renderProvider("/share/invitation/private-token");
    await waitFor(() => expect(state.consentCallback).toBeTypeOf("function"));

    state.consentCallback("granted");
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(loadGoogleAnalytics).not.toHaveBeenCalled();
    expect(sendAnalyticsPageView).not.toHaveBeenCalled();
  });

  it("does nothing when the env-backed runtime switch is off", async () => {
    state.analytics = { enabled: false, measurementId: null };

    renderProvider();

    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(observeAnalyticsConsent).not.toHaveBeenCalled();
    expect(loadGoogleAnalytics).not.toHaveBeenCalled();
  });
});
