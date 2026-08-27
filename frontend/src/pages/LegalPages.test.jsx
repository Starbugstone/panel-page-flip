import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CookieNoticePage, PrivacyPolicy, TermsOfService } from "@/pages/LegalPages.jsx";
import { api } from "@/lib/api";
import { reopenPrivacyChoices } from "@/lib/privacy-choices";

const { adSense } = vi.hoisted(() => ({
  adSense: { config: { enabled: false, client: null }, isLoading: false },
}));

vi.mock("@/lib/api", () => ({ api: { get: vi.fn(() => Promise.resolve({})) } }));
vi.mock("@/lib/logger", () => ({ logger: { warn: vi.fn(), log: vi.fn() } }));
vi.mock("@/lib/privacy-choices", () => ({ reopenPrivacyChoices: vi.fn(() => Promise.resolve(true)) }));
vi.mock("@/components/ads/AdSenseProvider.jsx", () => ({
  useAdSense: () => ({ config: adSense.config, isLoading: adSense.isLoading, scriptStatus: "idle" }),
}));

const CLIENT = "ca-pub-1234567890123456";

const advertisingOn = () => { adSense.config = { enabled: true, client: CLIENT }; };

const renderPage = (page) => render(<MemoryRouter initialEntries={["/privacy"]}>{page}</MemoryRouter>);

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(api.get).mockResolvedValue({ operator: "Test operator", privacyEmail: null, legalEmail: null });
  adSense.config = { enabled: false, client: null };
  adSense.isLoading = false;
});

/**
 * The same build serves an ad-free self-hosted library and a monetised public
 * one. These pages are the installation's statement of fact about which it is,
 * they are indexable, and both directions are wrong on the other deployment.
 */
describe("the privacy policy", () => {
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
  it("does not promise the consent control on pages that have no footer", async () => {
    advertisingOn();

    renderPage(<PrivacyPolicy />);

    expect(await screen.findByText(/outside the comic reader/i)).toBeInTheDocument();
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

    await waitFor(() => expect(api.get).toHaveBeenCalled());
    expect(screen.queryByText(/advertising is not an endorsement/i)).not.toBeInTheDocument();
  });
});
