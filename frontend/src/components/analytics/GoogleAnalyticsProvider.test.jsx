import { act, render, screen, waitFor } from "@testing-library/react";
import { MemoryRouter, useNavigate } from "react-router-dom";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { GoogleAnalyticsProvider } from "@/components/analytics/GoogleAnalyticsProvider.jsx";
import {
  denyLocalAnalyticsConsent,
  disableGoogleAnalytics,
  grantLocalAnalyticsConsent,
  loadGoogleAnalytics,
  sendAnalyticsPageView,
  setAnalyticsPageContext,
} from "@/lib/google-analytics";

const state = vi.hoisted(() => ({
  analytics: { enabled: true, measurementId: "G-PSW1MY7HB4" },
  isLoading: false,
  analyticsConsent: "undecided",
  consentProvider: "local",
  setConsent: null,
}));

vi.mock("@/components/config/PublicConfigProvider.jsx", () => ({
  usePublicConfig: () => ({ analytics: state.analytics, isLoading: state.isLoading }),
}));
vi.mock("@/components/consent/ConsentProvider.jsx", () => ({
  useConsent: () => ({
    analyticsConsent: state.analyticsConsent,
    provider: state.consentProvider,
  }),
}));
vi.mock("@/lib/google-analytics", async (importOriginal) => ({
  ...(await importOriginal()),
  denyLocalAnalyticsConsent: vi.fn(),
  grantLocalAnalyticsConsent: vi.fn(),
  loadGoogleAnalytics: vi.fn(() => Promise.resolve("ready")),
  sendAnalyticsPageView: vi.fn(),
  setAnalyticsPageContext: vi.fn(),
  disableGoogleAnalytics: vi.fn(),
}));

function Navigation() {
  const navigate = useNavigate();
  return (
    <>
      <button onClick={() => navigate("/settings")}>Settings</button>
      <button onClick={() => navigate("/read/42")}>Reader</button>
      <button onClick={() => navigate("/dashboard")}>Dashboard</button>
    </>
  );
}

/**
 * The decision arrives as a prop of the consent context, so the test drives it
 * the same way the real providers do: re-render with a new value. Which
 * provider produced that value — Google's CMP or the local dialogue — is
 * deliberately not this component's concern any more.
 */
function renderProvider(path = "/dashboard") {
  const view = render(
    <MemoryRouter initialEntries={[path]}>
      <GoogleAnalyticsProvider><Navigation /></GoogleAnalyticsProvider>
    </MemoryRouter>
  );
  state.setConsent = (decision) => {
    state.analyticsConsent = decision;
    view.rerender(
      <MemoryRouter initialEntries={[path]}>
        <GoogleAnalyticsProvider><Navigation /></GoogleAnalyticsProvider>
      </MemoryRouter>
    );
  };

  return view;
}

beforeEach(() => {
  vi.clearAllMocks();
  state.analytics = { enabled: true, measurementId: "G-PSW1MY7HB4" };
  state.isLoading = false;
  state.analyticsConsent = "undecided";
  state.consentProvider = "local";
  state.setConsent = null;
});

describe("privacy-first analytics startup", () => {
  it("loads no analytics tag while consent is undecided or refused", async () => {
    renderProvider();

    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(loadGoogleAnalytics).not.toHaveBeenCalled();
    expect(sendAnalyticsPageView).not.toHaveBeenCalled();

    act(() => state.setConsent("denied"));
    expect(loadGoogleAnalytics).not.toHaveBeenCalled();
  });

  it("loads after consent and emits one sanitized view per allowed SPA route", async () => {
    renderProvider();

    act(() => state.setConsent("granted"));
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

  it("counts a return to the same allowed route after an excluded route", async () => {
    renderProvider();
    act(() => state.setConsent("granted"));
    await waitFor(() => expect(sendAnalyticsPageView).toHaveBeenCalledTimes(1));

    await userEvent.click(screen.getByRole("button", { name: "Reader" }));
    await userEvent.click(screen.getByRole("button", { name: "Dashboard" }));

    await waitFor(() => expect(sendAnalyticsPageView).toHaveBeenCalledTimes(2));
    expect(sendAnalyticsPageView.mock.calls[1][1].page_location).toBe("http://localhost:8080/dashboard");
  });

  it("emits Consent Mode v2 changes for the local banner but leaves Google's CMP authoritative", async () => {
    const view = renderProvider();
    act(() => state.setConsent("granted"));
    await waitFor(() => expect(grantLocalAnalyticsConsent).toHaveBeenCalledTimes(1));

    act(() => state.setConsent("denied"));
    expect(denyLocalAnalyticsConsent).toHaveBeenCalledTimes(1);

    vi.clearAllMocks();
    view.unmount();
    state.analyticsConsent = "granted";
    state.consentProvider = "google";
    renderProvider();
    await waitFor(() => expect(loadGoogleAnalytics).toHaveBeenCalledTimes(1));
    expect(grantLocalAnalyticsConsent).not.toHaveBeenCalled();
    expect(denyLocalAnalyticsConsent).not.toHaveBeenCalled();
  });

  /**
   * Withdrawal has to be immediate and has to take the cookies with it, whoever
   * collected the original grant.
   */
  it("stops collecting and removes the cookies when consent is withdrawn", async () => {
    renderProvider();
    act(() => state.setConsent("granted"));
    await waitFor(() => expect(loadGoogleAnalytics).toHaveBeenCalledTimes(1));

    disableGoogleAnalytics.mockClear();
    act(() => state.setConsent("denied"));

    expect(disableGoogleAnalytics).toHaveBeenCalledWith("G-PSW1MY7HB4");
  });

  it("does not load or send on tokenized routes even after consent", async () => {
    renderProvider("/share/invitation/private-token");

    await act(async () => {
      state.setConsent("granted");
      await Promise.resolve();
    });
    expect(loadGoogleAnalytics).not.toHaveBeenCalled();
    expect(sendAnalyticsPageView).not.toHaveBeenCalled();
  });

  /**
   * Google requires the privacy-policy URL in Privacy & Messaging to host no
   * consent-requiring tag. Direct load, not navigation into it: that is the
   * request the crawler and the operator's own check actually make.
   */
  it.each(["/privacy", "/cookies", "/terms"])(
    "loads nothing on the Google-free route %s even with consent granted",
    async (path) => {
      renderProvider(path);

      await act(async () => {
        state.setConsent("granted");
        await Promise.resolve();
      });
      await new Promise((resolve) => setTimeout(resolve, 0));
      expect(loadGoogleAnalytics).not.toHaveBeenCalled();
      expect(sendAnalyticsPageView).not.toHaveBeenCalled();
    }
  );

  it("does nothing when the env-backed runtime switch is off", async () => {
    state.analytics = { enabled: false, measurementId: null };

    renderProvider();
    act(() => state.setConsent("granted"));

    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(loadGoogleAnalytics).not.toHaveBeenCalled();
  });
});
