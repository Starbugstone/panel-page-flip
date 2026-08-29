import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CookieNotice } from "@/components/CookieNotice.jsx";
import { isAdvertisingActive } from "@/lib/advertising";

const { adSense } = vi.hoisted(() => ({
  adSense: { config: { enabled: false, client: null }, isLoading: false },
}));

vi.mock("@/components/ads/AdSenseProvider.jsx", () => ({
  useAdSense: () => ({ config: adSense.config, isActive: isAdvertisingActive(adSense.config), isLoading: adSense.isLoading, scriptStatus: "idle" }),
}));

const CLIENT = "ca-pub-1234567890123456";

const renderNotice = () => render(
  <MemoryRouter initialEntries={["/"]}><CookieNotice /></MemoryRouter>
);

beforeEach(() => {
  vi.clearAllMocks();
  window.localStorage.clear();
  adSense.config = { enabled: false, client: null };
  adSense.isLoading = false;
});

/**
 * Two banners asking about the same thing is how somebody accepts in one and
 * rejects in the other, so where advertising is on this notice describes and
 * defers rather than collecting a second answer.
 */
describe("what the cookie notice claims", () => {
  it("says no advertising storage is used where none is", async () => {
    renderNotice();

    expect(await screen.findByText(/no advertising or analytics cookies are used/i)).toBeInTheDocument();
  });

  it("points at the consent panel where advertising is on", async () => {
    adSense.config = { enabled: true, client: CLIENT };

    renderNotice();

    expect(await screen.findByText(/you accept or reject in the privacy choices panel/i)).toBeInTheDocument();
    expect(screen.queryByText(/no advertising or analytics cookies are used/i)).not.toBeInTheDocument();
  });

  /**
   * The dismissal is permanent and the two wordings contradict each other, so
   * saying the wrong one first is not a flicker — it is the only version a user
   * who pressed "Got it" during the round trip will ever have been shown.
   */
  it("says nothing at all until the server has answered", () => {
    adSense.config = { enabled: true, client: CLIENT };
    adSense.isLoading = true;

    renderNotice();

    expect(screen.queryByRole("complementary", { name: "Cookie notice" })).not.toBeInTheDocument();
    expect(screen.queryByText(/no advertising or analytics cookies are used/i)).not.toBeInTheDocument();
  });

  it("stays dismissed once it has been dismissed", async () => {
    renderNotice();

    await userEvent.click(await screen.findByRole("button", { name: "Got it" }));

    expect(screen.queryByRole("complementary", { name: "Cookie notice" })).not.toBeInTheDocument();
  });
});
