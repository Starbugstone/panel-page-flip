import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CookieNoticePage, PrivacyPolicy, TermsOfService } from "@/pages/LegalPages.jsx";
import { reopenPrivacyChoices } from "@/lib/privacy-choices";
import { isAdvertisingActive } from "@/lib/advertising";

const { adSense } = vi.hoisted(() => ({
  adSense: {
    config: { enabled: false, client: null },
    analytics: { enabled: false, measurementId: null },
    consent: { enabled: false, client: null },
    legal: { operator: "Test operator", privacyEmail: null, legalEmail: null },
    turnstile: { enabled: false, siteKey: null },
    isLoading: false,
  },
}));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(() => Promise.resolve({})) } }));
vi.mock("@/lib/logger", () => ({ logger: { warn: vi.fn(), log: vi.fn() } }));
vi.mock("@/lib/privacy-choices", () => ({ reopenPrivacyChoices: vi.fn(() => Promise.resolve(true)) }));
vi.mock("@/components/ads/AdSenseProvider.jsx", () => ({
  useAdSense: () => ({ config: adSense.config, analytics: adSense.analytics, consent: adSense.consent, legal: adSense.legal, isActive: isAdvertisingActive(adSense.config), isLoading: adSense.isLoading, scriptStatus: "idle" }),
}));
vi.mock("@/components/config/PublicConfigProvider.jsx", () => ({
  usePublicConfig: () => ({
    legal: adSense.legal,
    turnstile: adSense.turnstile,
    isLoading: adSense.isLoading,
  }),
}));

const CLIENT = "ca-pub-1234567890123456";

const advertisingOn = () => { adSense.config = { enabled: true, client: CLIENT }; };
const analyticsOn = () => {
  adSense.analytics = { enabled: true, measurementId: "G-PSW1MY7HB4" };
  adSense.consent = { enabled: true, client: CLIENT };
};

const renderPage = (page) => render(<MemoryRouter initialEntries={["/privacy"]}>{page}</MemoryRouter>);

beforeEach(() => {
  vi.clearAllMocks();
  adSense.legal = { operator: "Test operator", privacyEmail: null, legalEmail: null };
  adSense.config = { enabled: false, client: null };
  adSense.analytics = { enabled: false, measurementId: null };
  adSense.consent = { enabled: false, client: null };
  adSense.isLoading = false;
  adSense.turnstile = { enabled: false, siteKey: null };
});

/**
 * The same build serves an ad-free self-hosted library and a monetised public
 * one. These pages are the installation's statement of fact about which it is,
 * they are indexable, and both directions are wrong on the other deployment.
 */
describe("the privacy policy", () => {
  it("covers account identifiers and sharing-code records, not only email invitations", async () => {
    renderPage(<PrivacyPolicy />);

    expect(await screen.findByText(
      /Sharing data: usernames, recipient email addresses, sharing-code records/i
    )).toBeInTheDocument();
    expect(screen.getByText(
      /Sharing by username or code does not reveal either person’s email address/i
    )).toBeInTheDocument();
  });

  it("names Google as a recipient where advertising is on", async () => {
    advertisingOn();

    renderPage(<PrivacyPolicy />);

    expect(await screen.findByText(/google serves advertising on a small number of pages/i)).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: /advertising/i })).toBeInTheDocument();
  });

  it("denies using advertising networks where none are used", async () => {
    renderPage(<PrivacyPolicy />);

    expect(await screen.findByText(/we do not use advertising networks or third-party analytics/i))
      .toBeInTheDocument();
  });

  it("documents privacy-minimised Analytics only when its env switch is active", async () => {
    analyticsOn();

    renderPage(<PrivacyPolicy />);

    expect(await screen.findByRole("heading", { name: /optional audience measurement/i })).toBeInTheDocument();
    expect(screen.getByText(/never send Google your account id, email address, comic ids/i)).toBeInTheDocument();
    expect(screen.getByText(/Google Signals and advertising-personalisation signals are disabled/i)).toBeInTheDocument();
    expect(screen.getByText(/two-month retention period/i)).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: /^advertising$/i })).not.toBeInTheDocument();
  });

  it("names Cloudflare only when Turnstile is enabled at runtime", async () => {
    adSense.turnstile = { enabled: true, siteKey: "public-site-key" };

    renderPage(<PrivacyPolicy />);

    expect(await screen.findByText(/Cloudflare receives the network and browser data/i)).toBeInTheDocument();
    expect(screen.getByText(/does not send the report contents or reporter email address to Cloudflare/i)).toBeInTheDocument();
  });

  /**
   * The claim that must never be published by accident. Defaulting to "off"
   * while the request is in flight prints "we do not use advertising networks"
   * on every load of an installation that does.
   */
  it("makes neither claim until the server has answered", () => {
    advertisingOn();
    adSense.isLoading = true;

    renderPage(<PrivacyPolicy />);

    expect(screen.queryByText(/we do not use advertising networks/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/google serves advertising/i)).not.toBeInTheDocument();
  });

  /**
   * `Footer` renders nothing on `/read/`, so a policy promising the control on
   * "every page" documents a way to withdraw consent that is not there. The
   * exception is the honest half of the sentence, and the half that rots first.
   */
  it("names the reader settings as the withdrawal route where there is no footer", async () => {
    advertisingOn();

    renderPage(<PrivacyPolicy />);

    expect(await screen.findByText(/reader.s settings/i)).toBeInTheDocument();
    expect(screen.queryByText(/in the footer of every page\./i)).not.toBeInTheDocument();
  });

  it("reopens the consent panel from inside the policy text", async () => {
    advertisingOn();

    renderPage(<PrivacyPolicy />);
    await userEvent.click(await screen.findByRole("button", { name: "privacy choices" }));

    await waitFor(() => expect(reopenPrivacyChoices).toHaveBeenCalledWith({ client: CLIENT }));
  });
});

describe("the cookie notice page", () => {
  it("lists Google advertising storage where advertising is on", async () => {
    advertisingOn();

    renderPage(<CookieNoticePage />);

    expect(await screen.findByText(/google advertising storage:/i)).toBeInTheDocument();
    expect(screen.getByText(/rejecting is as easy as accepting/i)).toBeInTheDocument();
  });

  it("documents Analytics cookies, duration and withdrawal when enabled", async () => {
    analyticsOn();

    renderPage(<CookieNoticePage />);

    expect(await screen.findByText(/Google Analytics storage:/i)).toBeInTheDocument();
    expect(screen.getByText(/expire no later than thirteen months/i)).toBeInTheDocument();
    expect(screen.getByText(/removes these cookies/i)).toBeInTheDocument();
  });

  it("says there is no consent panel where there is none", async () => {
    renderPage(<CookieNoticePage />);

    expect(await screen.findByText(/there is no tracking-consent panel/i)).toBeInTheDocument();
  });

  it("makes neither claim until the server has answered", () => {
    advertisingOn();
    adSense.isLoading = true;

    renderPage(<CookieNoticePage />);

    expect(screen.queryByText(/there is no tracking-consent panel/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/google advertising storage:/i)).not.toBeInTheDocument();
  });
});

describe("the terms", () => {
  /** Issue #73 §16: advertising is disclosed, and clicking it is not a favour. */
  it("discloses advertising and forbids manipulating it", async () => {
    advertisingOn();

    renderPage(<TermsOfService />);

    expect(await screen.findByText(/advertising is not an endorsement/i)).toBeInTheDocument();
    expect(screen.getByText(/advertising fraud breaches the advertising/i)).toBeInTheDocument();
  });

  it("says nothing about advertising where there is none", async () => {
    renderPage(<TermsOfService />);

    // The operator's name comes from the same settled context as the
    // advertising state, so seeing it means the page is not still deciding.
    expect(await screen.findByText(/Test operator/)).toBeInTheDocument();
    expect(screen.queryByText(/advertising is not an endorsement/i)).not.toBeInTheDocument();
  });

  /**
   * The hosting disclaimer and the user's warranty are the two things this
   * page has to say about uploads. An instruction ("upload only when
   * authorized") is not a guarantee, and being responsible for account
   * activity is not a statement that the operator is not responsible for
   * the files.
   */
  it("makes the user guarantee the right to upload, and to share if they share", async () => {
    renderPage(<TermsOfService />);

    expect(await screen.findByText(/you guarantee that you have the.{0,40}right to upload/i))
      .toBeInTheDocument();
    expect(screen.getByText(/if you choose to share.{0,80}guarantee/i)).toBeInTheDocument();
  });

  it("says the operator is not responsible for uploaded content", async () => {
    renderPage(<TermsOfService />);

    expect(await screen.findByText(/not responsible for uploaded content/i)).toBeInTheDocument();
  });

  /**
   * Storage is capped by whatever the operator has configured for this
   * account — the installation default, or a custom override — not by
   * whatever the client happens to enforce. The terms need to say that,
   * or a user reading them has no idea an upload can be rejected for it.
   */
  it("ties storage to the configured default or custom limit", async () => {
    renderPage(<TermsOfService />);

    expect(await screen.findByRole("heading", { name: /storage limits/i })).toBeInTheDocument();
    expect(screen.getByText(/installation.s default limit, or a custom limit the operator/i))
      .toBeInTheDocument();
    expect(screen.getByText(/an upload that would exceed that limit is rejected/i))
      .toBeInTheDocument();
    expect(screen.getByText(/operator may change the configured limit at any time/i))
      .toBeInTheDocument();
  });
});
