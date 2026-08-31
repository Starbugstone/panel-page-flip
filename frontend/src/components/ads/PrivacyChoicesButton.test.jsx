import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { Footer } from "@/components/Footer.jsx";
import { PrivacyChoicesButton } from "@/components/ads/PrivacyChoicesButton.jsx";
import { reopenPrivacyChoices } from "@/lib/privacy-choices";
import { isAdvertisingActive } from "@/lib/advertising";

const { adSense } = vi.hoisted(() => ({
  adSense: {
    config: { enabled: false, client: null },
    analytics: { enabled: false, measurementId: null },
    consent: { enabled: false, client: null },
    isLoading: false,
  },
}));

vi.mock("@/lib/privacy-choices", () => ({ reopenPrivacyChoices: vi.fn(() => Promise.resolve(true)) }));
vi.mock("@/components/ads/AdSenseProvider.jsx", () => ({
  useAdSense: () => ({ config: adSense.config, analytics: adSense.analytics, consent: adSense.consent, isActive: isAdvertisingActive(adSense.config), isLoading: adSense.isLoading, scriptStatus: "idle" }),
}));

const CLIENT = "ca-pub-1234567890123456";

const advertisingOn = () => { adSense.config = { enabled: true, client: CLIENT }; };
const analyticsOn = () => {
  adSense.analytics = { enabled: true, measurementId: "G-PSW1MY7HB4" };
  adSense.consent = { enabled: true, client: CLIENT };
};

beforeEach(() => {
  vi.clearAllMocks();
  adSense.config = { enabled: false, client: null };
  adSense.analytics = { enabled: false, measurementId: null };
  adSense.consent = { enabled: false, client: null };
  adSense.isLoading = false;
});

/**
 * Consent that can be given and never withdrawn is not consent, so this control
 * is the mechanism behind a promise the privacy policy makes in writing.
 */
describe("the privacy choices control", () => {
  it("hands the publisher id to the consent platform", async () => {
    advertisingOn();

    render(<PrivacyChoicesButton />);
    await userEvent.click(screen.getByRole("button", { name: "Privacy choices" }));

    // Passed through because the platform is fetched on demand: on an ad-free
    // route nothing Google-owned has been loaded, and it needs the id to load.
    expect(reopenPrivacyChoices).toHaveBeenCalledWith({ client: CLIENT });
  });

  it("is absent where the installation shows no advertising", () => {
    render(<PrivacyChoicesButton />);

    expect(screen.queryByRole("button", { name: "Privacy choices" })).not.toBeInTheDocument();
  });

  it("is present for analytics when advertising itself is off", async () => {
    analyticsOn();

    render(<PrivacyChoicesButton />);
    await userEvent.click(screen.getByRole("button", { name: "Privacy choices" }));

    expect(reopenPrivacyChoices).toHaveBeenCalledWith({ client: CLIENT });
  });

  /**
   * Offering it before the answer arrives would flicker a control onto every
   * ad-free installation's footer, implying a consent nobody was ever asked for.
   */
  it("waits for the server before offering itself", () => {
    advertisingOn();
    adSense.isLoading = true;

    render(<PrivacyChoicesButton />);

    expect(screen.queryByRole("button", { name: "Privacy choices" })).not.toBeInTheDocument();
  });
});

describe("the footer it lives in", () => {
  const renderFooter = (pathname) => render(
    <MemoryRouter initialEntries={[pathname]}><Footer /></MemoryRouter>
  );

  it("uses the product name", () => {
    renderFooter("/dashboard");

    expect(screen.getByText(/© \d{4} Panel Page Flip\. All rights reserved\./)).toBeInTheDocument();
  });

  /**
   * The reader is ad-free and the site code is never loaded there, but consent
   * is exactly what somebody wants to change while looking at the page that
   * made them think about it.
   */
  it.each(["/dashboard", "/settings", "/privacy", "/sharing"])(
    "offers the control on the ad-free page %s",
    (pathname) => {
      advertisingOn();

      renderFooter(pathname);

      expect(screen.getByRole("button", { name: "Privacy choices" })).toBeInTheDocument();
    }
  );

  it("keeps the whole footer off the reader", () => {
    advertisingOn();

    renderFooter("/read/12");

    expect(screen.queryByRole("navigation", { name: "Legal" })).not.toBeInTheDocument();
  });
});
