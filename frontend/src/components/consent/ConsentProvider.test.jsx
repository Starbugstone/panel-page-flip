import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { GoogleAnalyticsProvider } from "@/components/analytics/GoogleAnalyticsProvider.jsx";
import { ANALYTICS_SCRIPT_ID, resetGoogleAnalyticsForTesting } from "@/lib/google-analytics";
import { ANALYTICS_CONSENT_STORAGE_KEY } from "@/lib/analytics-consent-storage";
import { ConsentBanner } from "@/components/consent/ConsentBanner.jsx";
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
  PRIVACY_CHOICES_OPENING_EVENT: "panel-page-flip:privacy-choices-opening",
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
      <ConsentBanner />
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

afterEach(() => resetGoogleAnalyticsForTesting());

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

    const accept = screen.getByRole("button", { name: "Accept all" });
    const reject = screen.getByRole("button", { name: "Reject all" });
    // A reject that is harder to reach than accept is not a free choice. Both
    // are real buttons, the same size, side by side in the same row — not a
    // link, not smaller type, not a second click behind "more options".
    expect(reject.tagName).toBe("BUTTON");
    expect(reject).toBeEnabled();
    expect(reject.parentElement).toBe(accept.parentElement);
    expect(screen.getByRole("link", { name: "Customize" })).toBeVisible();

    await userEvent.click(reject);

    expect(observed().analyticsConsent).toBe("denied");
    expect(screen.queryByRole("dialog", { name: "Analytics preferences" })).not.toBeInTheDocument();
  });

  it("remembers the answer so a returning visitor is not asked again", async () => {
    const first = renderProvider();
    await userEvent.click(await screen.findByRole("button", { name: "Accept all" }));
    expect(observed().analyticsConsent).toBe("granted");
    first.unmount();

    renderProvider();

    expect(observed().analyticsConsent).toBe("granted");
    expect(screen.queryByRole("dialog", { name: "Analytics preferences" })).not.toBeInTheDocument();
  });

  it("honours a withdrawal or cleared choice from another tab", async () => {
    renderProvider();
    await userEvent.click(await screen.findByRole("button", { name: "Accept all" }));
    expect(observed().analyticsConsent).toBe("granted");

    window.localStorage.setItem("panel-page-flip:analytics-consent", JSON.stringify({
      version: 1, decision: "denied", decidedAt: new Date().toISOString(),
    }));
    act(() => window.dispatchEvent(new StorageEvent("storage", {
      key: "panel-page-flip:analytics-consent", storageArea: window.localStorage,
    })));
    expect(observed().analyticsConsent).toBe("denied");

    window.localStorage.clear();
    act(() => window.dispatchEvent(new StorageEvent("storage", {
      key: null, storageArea: window.localStorage,
    })));
    expect(observed().analyticsConsent).toBe("undecided");
  });

  it("keeps a withdrawal made while public configuration is loading", () => {
    configure("neither");
    state.publicConfig.isLoading = true;
    const persistDecision = (decision) => window.localStorage.setItem(
      "panel-page-flip:analytics-consent",
      JSON.stringify({ version: 1, decision, decidedAt: new Date().toISOString() })
    );
    persistDecision("granted");
    const tree = () => <MemoryRouter><ConsentProvider><Probe /></ConsentProvider></MemoryRouter>;
    const view = render(tree());

    persistDecision("denied");
    act(() => window.dispatchEvent(new StorageEvent("storage", {
      key: "panel-page-flip:analytics-consent", storageArea: window.localStorage,
    })));
    configure("analyticsOnly");
    state.publicConfig.isLoading = false;
    view.rerender(tree());

    expect(observed().analyticsConsent).toBe("denied");
  });

  it("can be reopened to withdraw, from the permanent control", async () => {
    const first = renderProvider();
    await userEvent.click(await screen.findByRole("button", { name: "Accept all" }));
    first.unmount();

    renderProvider();
    await userEvent.click(screen.getByRole("button", { name: "Preferences" }));

    expect(await screen.findByRole("dialog", { name: "Analytics preferences" })).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Reject all" }));

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

  it("honours the privacy-panel request after a full document navigation", async () => {
    configure("both");
    renderProvider("/?privacyChoices=open");
    await waitFor(() => expect(reopenPrivacyChoices).toHaveBeenCalledWith({ client: CLIENT }));
    expect(reopenPrivacyChoices).toHaveBeenCalledTimes(1);
  });

  it("opens Google's panel in place on an ordinary route", async () => {
    configure("both");

    renderProvider("/dashboard");
    await userEvent.click(screen.getByRole("button", { name: "Preferences" }));

    expect(reopenPrivacyChoices).toHaveBeenCalledWith({ client: CLIENT });
    expect(screen.getByTestId("path").textContent).toBe("/dashboard");
  });
});

describe("Klaro customization", () => {
  beforeEach(() => configure("analyticsOnly"));

  it("keeps an edited choice pending until saved", async () => {
    renderProvider();
    await userEvent.click(screen.getByRole("link", { name: "Customize" }));
    const modal = await screen.findByRole("dialog", { name: "Analytics preferences" });
    expect(modal).toHaveAttribute("aria-modal", "true");
    const analytics = screen.getByRole("checkbox", { name: "Google Analytics" });
    expect(analytics).not.toBeChecked();
    await userEvent.click(analytics);
    expect(observed().analyticsConsent).toBe("undecided");
    await userEvent.click(screen.getByRole("button", { name: "Save choices" }));
    expect(observed().analyticsConsent).toBe("granted");
  });

  it.each(["Close", "Escape"])("returns focus to the notice without saving when customization closes with %s", async (action) => {
    renderProvider();
    await userEvent.click(screen.getByRole("link", { name: "Customize" }));
    await userEvent.click(screen.getByRole("checkbox", { name: "Google Analytics" }));
    if (action === "Escape") await userEvent.keyboard("{Escape}");
    else await userEvent.click(screen.getByRole("button", { name: "Close" }));
    expect(await screen.findByRole("link", { name: "Customize" })).toBeVisible();
    const notice = screen.getByRole("dialog", { name: "Analytics preferences" });
    expect(notice).not.toHaveAttribute("aria-modal");
    await waitFor(() => expect(notice).toHaveFocus());
    expect(observed().analyticsConsent).toBe("undecided");
    expect(window.localStorage.getItem(ANALYTICS_CONSENT_STORAGE_KEY)).toBeNull();
  });

  it("keeps keyboard focus within customization and restores the preferences trigger after deciding", async () => {
    renderProvider();
    await userEvent.click(screen.getByRole("button", { name: "Reject all" }));
    const trigger = screen.getByRole("button", { name: "Preferences" });
    await userEvent.click(trigger);
    await userEvent.click(screen.getByRole("link", { name: "Customize" }));
    const close = screen.getByRole("button", { name: "Close" });
    close.focus();
    await userEvent.tab({ shift: true });
    expect(screen.getByRole("button", { name: "Accept all" })).toHaveFocus();
    await userEvent.tab();
    expect(close).toHaveFocus();
    await userEvent.click(screen.getByRole("button", { name: "Reject all" }));
    expect(trigger).toHaveFocus();
  });

  it("renders the whole choice in French for a French browser", async () => {
    const language = vi.spyOn(navigator, "language", "get").mockReturnValue("fr-FR");
    try {
      renderProvider();
      expect(screen.getByRole("dialog", { name: "Préférences de mesure d’audience" })).toBeVisible();
      expect(screen.getByRole("button", { name: "Tout accepter" })).toBeEnabled();
      await userEvent.click(screen.getByRole("link", { name: "Personnaliser" }));
      expect(screen.getByRole("button", { name: "Enregistrer mes choix" })).toBeVisible();
      await userEvent.click(screen.getByRole("button", { name: "Tout refuser" }));
      expect(observed().analyticsConsent).toBe("denied");
    } finally {
      language.mockRestore();
    }
  });
});

it("gates the real GA4 loader and updates only the analytics purpose after a saved Klaro choice", async () => {
  configure("analyticsOnly");
  render(
    <MemoryRouter initialEntries={["/"]}>
      <ConsentProvider>
        <GoogleAnalyticsProvider><Probe /></GoogleAnalyticsProvider>
      </ConsentProvider>
    </MemoryRouter>
  );
  expect(document.getElementById(ANALYTICS_SCRIPT_ID)).toBeNull();
  await userEvent.click(screen.getByRole("button", { name: "Reject all" }));
  expect(document.getElementById(ANALYTICS_SCRIPT_ID)).toBeNull();
  await userEvent.click(screen.getByRole("button", { name: "Preferences" }));
  await userEvent.click(screen.getByRole("button", { name: "Accept all" }));
  const tag = await waitFor(() => {
    expect(document.getElementById(ANALYTICS_SCRIPT_ID)).not.toBeNull();
    return document.getElementById(ANALYTICS_SCRIPT_ID);
  });
  act(() => tag.dispatchEvent(new Event("load")));
  const updates = () => window.dataLayer.map((command) => [...command])
    .filter(([command, action]) => command === "consent" && action === "update");
  expect(updates().at(-1)[2]).toEqual({
    analytics_storage: "granted", ad_storage: "denied",
    ad_user_data: "denied", ad_personalization: "denied",
  });
  await userEvent.click(screen.getByRole("button", { name: "Preferences" }));
  await userEvent.click(screen.getByRole("button", { name: "Reject all" }));
  expect(updates().at(-1)[2].analytics_storage).toBe("denied");
  expect(window[`ga-disable-${MEASUREMENT_ID}`]).toBe(true);
});
