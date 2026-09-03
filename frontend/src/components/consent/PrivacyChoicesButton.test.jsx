import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { Footer } from "@/components/Footer.jsx";
import { PrivacyChoicesButton } from "@/components/consent/PrivacyChoicesButton.jsx";

const { consent } = vi.hoisted(() => ({
  consent: {
    provider: null,
    googleClient: null,
    isLoading: false,
    openPreferences: vi.fn(),
  },
}));

vi.mock("@/components/consent/ConsentProvider.jsx", () => ({
  useConsent: () => ({
    provider: consent.provider,
    googleClient: consent.googleClient,
    canOpenPreferences: !consent.isLoading && consent.provider !== null,
    openPreferences: consent.openPreferences,
  }),
}));

const CLIENT = "ca-pub-1234567890123456";

const googleProvider = () => {
  consent.provider = "google";
  consent.googleClient = CLIENT;
};
const localProvider = () => {
  consent.provider = "local";
  consent.googleClient = null;
};

beforeEach(() => {
  vi.clearAllMocks();
  consent.provider = null;
  consent.googleClient = null;
  consent.isLoading = false;
  consent.openPreferences = vi.fn();
});

/**
 * Consent that can be given and never withdrawn is not consent, so this control
 * is the mechanism behind a promise the privacy policy makes in writing.
 */
describe("the privacy choices control", () => {
  it("asks the consent layer to reopen whichever provider owns the answer", async () => {
    googleProvider();

    render(<PrivacyChoicesButton />);
    await userEvent.click(screen.getByRole("button", { name: "Privacy choices" }));

    expect(consent.openPreferences).toHaveBeenCalledTimes(1);
  });

  it("is absent where no optional Google service is on", () => {
    render(<PrivacyChoicesButton />);

    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  /**
   * "Privacy choices" is Google's own name for its panel. Using it for a
   * dialogue that only covers analytics would promise choices this
   * installation does not offer.
   */
  it("is named for what it actually opens on an Analytics-only installation", async () => {
    localProvider();

    render(<PrivacyChoicesButton />);
    await userEvent.click(screen.getByRole("button", { name: "Analytics preferences" }));

    expect(consent.openPreferences).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole("button", { name: "Privacy choices" })).not.toBeInTheDocument();
  });

  it("lets prose supply its own wording", () => {
    localProvider();

    render(<PrivacyChoicesButton>analytics preferences</PrivacyChoicesButton>);

    expect(screen.getByRole("button", { name: "analytics preferences" })).toBeInTheDocument();
  });

  /**
   * Offering it before the answer arrives would flicker a control onto every
   * plain installation's footer, implying a consent nobody was ever asked for.
   */
  it("waits for the server before offering itself", () => {
    googleProvider();
    consent.isLoading = true;

    render(<PrivacyChoicesButton />);

    expect(screen.queryByRole("button")).not.toBeInTheDocument();
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
   * made them think about it. The legal pages are in the list too: the control
   * is offered there, and the consent layer is what keeps the click from
   * mounting Google's tag on a page required to be free of it.
   */
  it.each(["/dashboard", "/settings", "/privacy", "/sharing"])(
    "offers the control on the ad-free page %s",
    (pathname) => {
      googleProvider();

      renderFooter(pathname);

      expect(screen.getByRole("button", { name: "Privacy choices" })).toBeInTheDocument();
    }
  );

  it("keeps the whole footer off the reader", () => {
    googleProvider();

    renderFooter("/read/12");

    expect(screen.queryByRole("navigation", { name: "Legal" })).not.toBeInTheDocument();
  });
});
