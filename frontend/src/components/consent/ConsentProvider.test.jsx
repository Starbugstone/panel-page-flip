import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { AnalyticsConsentDialog } from "@/components/consent/AnalyticsConsentDialog.jsx";
import { ConsentProvider, useConsent } from "@/components/consent/ConsentProvider.jsx";
import { observeAnalyticsConsent } from "@/lib/google-consent";
import { reopenPrivacyChoices } from "@/lib/privacy-choices";

const state = vi.hoisted(() => ({
  publicConfig: {
    adsense: { enabled: false, client: null },
    analytics: { enabled: false, measurementId: null },
    consent: { provider: null, analytics: false, googleClient: null },
    isLoading: false,
  },
  consentCallback: null,
}));

vi.mock("@/components/config/PublicConfigProvider.jsx", () => ({
  usePublicConfig: () => state.publicConfig,
}));
vi.mock("@/lib/google-consent", () => ({
  observeAnalyticsConsent: vi.fn((_client, { onChange }) => {
    state.consentCallback = onChange;
    return vi.fn();
  }),
}));
vi.mock("@/lib/privacy-choices", () => ({
  reopenPrivacyChoices: vi.fn(() => Promise.resolve(true)),
}));

const CLIENT = "ca-pub-1234567890123456";
const MEASUREMENT_ID = "G-PSW1MY7HB4";

/** The four effective states, exactly as `/api/public-config` publishes them. */
const STATES = {
  neither: {
    adsense: { enabled: false, client: null },
    analytics: { enabled: false, measurementId: null },
    consent: { provider: null, analytics: false, googleClient: null },
  },
  adsenseOnly: {
    adsense: { enabled: true, client: CLIENT },
    analytics: { enabled: false, measurementId: null },
    consent: { provider: "google", analytics: false, googleClient: CLIENT },
  },
  analyticsOnly: {
    adsense: { enabled: false, client: null },
    analytics: { enabled: true, measurementId: MEASUREMENT_ID },
    consent: { provider: "local", analytics: true, googleClient: null },
  },
  both: {
    adsense: { enabled: true, client: CLIENT },
    analytics: { enabled: true, measurementId: MEASUREMENT_ID },
    consent: { provider: "google", analytics: true, googleClient: CLIENT },
  },
};

function configure(name) {
  Object.assign(state.publicConfig, STATES[name]);
}

/**
 * The context read back through the DOM rather than captured into a variable:
 * assigning to something outside the component during render is a side effect,
 * and this is a test of what the application actually gets to see.
 */
function Probe() {
  const consent = useConsent();
  const { pathname } = useLocation();

  return (
    <div>
      <span data-testid="path">{pathname}</span>
      <span data-testid="state">{JSON.stringify({
        provider: consent.provider,
        googleClient: consent.googleClient,
        coversAnalytics: consent.coversAnalytics,
        analyticsConsent: consent.analyticsConsent,
        isAnalyticsDialogOpen: consent.isAnalyticsDialogOpen,
        canOpenPreferences: consent.canOpenPreferences,
      })}</span>
      <button onClick={consent.openPreferences}>Preferences</button>
      <AnalyticsConsentDialog />
    </div>
  );
}

const observed = () => JSON.parse(screen.getByTestId("state").textContent);

const renderProvider = (path = "/dashboard") => render(
  <MemoryRouter initialEntries={[path]}>
    <ConsentProvider>
      <Routes>
        <Route path="*" element={<Probe />} />
      </Routes>
    </ConsentProvider>
  </MemoryRouter>
);

beforeEach(() => {
  vi.clearAllMocks();
  window.localStorage.clear();
  configure("neither");
  state.publicConfig.isLoading = false;
  state.consentCallback = null;
});

describe("who owns the consent question", () => {
  it("asks nobody, and offers no control, where no optional service is on", async () => {
    renderProvider();

    await waitFor(() => expect(observed().provider).toBeNull());
    expect(observed().canOpenPreferences).toBe(false);
    expect(observed().isAnalyticsDialogOpen).toBe(false);
    expect(observeAnalyticsConsent).not.toHaveBeenCalled();
    expect(screen.queryByRole("dialog", { name: "Analytics preferences" })).not.toBeInTheDocument();
  });

  /**
   * The CMP is Google's own requirement for EEA/UK/Swiss ad traffic, but with
   * Analytics off there is no analytics purpose to observe.
   */
  it("hands advertising to Google's CMP without watching for an analytics decision", async () => {
    configure("adsenseOnly");

    renderProvider();

    await waitFor(() => expect(observed().provider).toBe("google"));
    expect(observed().coversAnalytics).toBe(false);
    expect(observed().canOpenPreferences).toBe(true);
    expect(observeAnalyticsConsent).not.toHaveBeenCalled();
    expect(observed().analyticsConsent).toBe("denied");
  });

  it("uses one Google consent flow, and never a second local one, where both are on", async () => {
    configure("both");

    renderProvider();

    await waitFor(() => expect(observeAnalyticsConsent).toHaveBeenCalledTimes(1));
    expect(observeAnalyticsConsent.mock.calls[0][0]).toBe(CLIENT);
    expect(observed().isAnalyticsDialogOpen).toBe(false);
    expect(screen.queryByRole("dialog", { name: "Analytics preferences" })).not.toBeInTheDocument();

    // Fail-closed until the CMP actually grants the analytics purpose.
    expect(observed().analyticsConsent).toBe("undecided");
    act(() => state.consentCallback("granted"));
    expect(observed().analyticsConsent).toBe("granted");
  });

  it("delegates consent-platform acquisition to the observer", async () => {
    configure("both");

    renderProvider("/dashboard");

    await waitFor(() => expect(observeAnalyticsConsent).toHaveBeenCalled());
    expect(observeAnalyticsConsent).toHaveBeenCalledWith(CLIENT, {
      onChange: expect.any(Function),
    });
  });

  it("says nothing at all until the server has answered", () => {
    configure("analyticsOnly");
    state.publicConfig.isLoading = true;

    renderProvider();

    expect(observed().isAnalyticsDialogOpen).toBe(false);
    expect(observed().canOpenPreferences).toBe(false);
    expect(screen.queryByRole("dialog", { name: "Analytics preferences" })).not.toBeInTheDocument();
  });
});

describe("the Analytics-only consent flow", () => {
  beforeEach(() => configure("analyticsOnly"));

  it("needs no publisher id and never touches Google's consent platform", async () => {
    renderProvider();

    await waitFor(() => expect(observed().provider).toBe("local"));
    expect(observed().googleClient).toBeNull();
    expect(observeAnalyticsConsent).not.toHaveBeenCalled();
  });

  it("measures nobody until they accept, and offers reject just as prominently", async () => {
    renderProvider();

    expect(await screen.findByRole("dialog", { name: "Analytics preferences" })).toBeInTheDocument();
    expect(observed().analyticsConsent).toBe("undecided");

    const accept = screen.getByRole("button", { name: "Accept analytics" });
    const reject = screen.getByRole("button", { name: "Reject analytics" });
    // A reject that is harder to reach than accept is not a free choice. Both
    // are real buttons, the same size, side by side in the same row — not a
    // link, not smaller type, not a second click behind "more options".
    expect(reject.tagName).toBe("BUTTON");
    expect(reject).toBeEnabled();
    expect(reject.parentElement).toBe(accept.parentElement);
    expect(reject.parentElement.className).toContain("sm:shrink-0");
    expect(reject.className).toContain("h-9");
    expect(accept.className).toContain("h-9");
    expect(reject.className).toBe(accept.className);

    await userEvent.click(reject);

    expect(observed().analyticsConsent).toBe("denied");
    expect(screen.queryByRole("dialog", { name: "Analytics preferences" })).not.toBeInTheDocument();
  });

  it("remembers the answer so a returning visitor is not asked again", async () => {
    const first = renderProvider();
    await userEvent.click(await screen.findByRole("button", { name: "Accept analytics" }));
    expect(observed().analyticsConsent).toBe("granted");
    first.unmount();

    renderProvider();

    expect(observed().analyticsConsent).toBe("granted");
    expect(screen.queryByRole("dialog", { name: "Analytics preferences" })).not.toBeInTheDocument();
  });

  it("can be reopened to withdraw, from the permanent control", async () => {
    const first = renderProvider();
    await userEvent.click(await screen.findByRole("button", { name: "Accept analytics" }));
    first.unmount();

    renderProvider();
    await userEvent.click(screen.getByRole("button", { name: "Preferences" }));

    expect(await screen.findByRole("dialog", { name: "Analytics preferences" })).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Reject analytics" }));

    expect(observed().analyticsConsent).toBe("denied");
    expect(reopenPrivacyChoices).not.toHaveBeenCalled();
  });

  /**
   * A first-party dialogue is not a Google tag, so it is allowed here — but the
   * decision still must not turn into measurement of a legal-policy page.
   */
  it("still asks on a Google-free route without loading anything Google", async () => {
    renderProvider("/privacy");

    expect(await screen.findByRole("dialog", { name: "Analytics preferences" })).toBeInTheDocument();
    expect(observeAnalyticsConsent).not.toHaveBeenCalled();
  });
});

describe("the Google-free legal routes", () => {
  it.each(["/privacy", "/cookies", "/terms"])(
    "loads no consent platform on a direct visit to %s",
    async (path) => {
      configure("both");

      renderProvider(path);

      await new Promise((resolve) => setTimeout(resolve, 0));
      expect(observeAnalyticsConsent).not.toHaveBeenCalled();
      expect(observed().analyticsConsent).toBe("undecided");
    }
  );

  /**
   * Google requires the privacy-policy page to carry no Funding Choices tag, so
   * a click on the control there cannot mount it. Honouring the click by
   * leaving the page first is better than either breaking the promise or
   * silently doing nothing.
   */
  it("leaves the page before opening Google's panel", async () => {
    configure("both");

    renderProvider("/privacy");
    await userEvent.click(screen.getByRole("button", { name: "Preferences" }));

    await waitFor(() => expect(screen.getByTestId("path").textContent).toBe("/"));
    await waitFor(() => expect(reopenPrivacyChoices).toHaveBeenCalledWith({ client: CLIENT }));
  });

  it("opens Google's panel in place on an ordinary route", async () => {
    configure("both");

    renderProvider("/dashboard");
    await userEvent.click(screen.getByRole("button", { name: "Preferences" }));

    expect(reopenPrivacyChoices).toHaveBeenCalledWith({ client: CLIENT });
    expect(screen.getByTestId("path").textContent).toBe("/dashboard");
  });
});
