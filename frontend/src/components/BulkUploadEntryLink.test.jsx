import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { BulkUploadEntryLink } from "@/components/BulkUploadEntryLink.jsx";

const { adSense } = vi.hoisted(() => ({ adSense: { isActive: false, isLoading: false } }));

vi.mock("@/components/ads/AdSenseProvider.jsx", () => ({ useAdSense: () => adSense }));

const renderLink = (props = {}) => render(
  <MemoryRouter>
    <BulkUploadEntryLink {...props}>Bulk upload</BulkUploadEntryLink>
  </MemoryRouter>
);

const link = () => screen.getByRole("link", { name: "Bulk upload" });

/**
 * Whether the click would leave the page to the browser.
 *
 * React Router's `Link` calls `preventDefault` in its own handler, which runs
 * while the event bubbles through the React root — so by the time it reaches
 * the document it is already cancelled. A plain anchor is not. Reading the flag
 * here and cancelling afterwards tells the two apart without jsdom attempting a
 * navigation it cannot perform.
 */
const clickReloadsThePage = async (element) => {
  let cancelled = null;
  const listener = (event) => {
    cancelled = event.defaultPrevented;
    event.preventDefault();
  };

  document.addEventListener("click", listener);
  await userEvent.click(element);
  document.removeEventListener("click", listener);

  return cancelled === false;
};

beforeEach(() => {
  adSense.isActive = false;
  adSense.isLoading = false;
});

describe("while the advertising configuration is loading", () => {
  beforeEach(() => { adSense.isLoading = true; });

  it("does not let a fast click bypass the gate", async () => {
    renderLink();

    expect(link()).toHaveAttribute("href", "/upload/bulk");
    expect(await clickReloadsThePage(link())).toBe(false);
  });
});

describe("with advertising on", () => {
  beforeEach(() => { adSense.isActive = true; });

  /**
   * The Offerwall is evaluated on page load and Google documents no API to
   * re-run it after a client-side route change, so a router navigation into the
   * gate means the advertisement never gets the chance to appear at all.
   */
  it("reaches the gate through a page load Google can act on", async () => {
    renderLink();

    expect(link()).toHaveAttribute("href", "/upload/bulk");
    expect(await clickReloadsThePage(link())).toBe(true);
  });

  it("carries the chosen destination folder to the gate", () => {
    renderLink({ search: "?folder=7" });

    expect(link()).toHaveAttribute("href", "/upload/bulk?folder=7");
  });
});

describe("with advertising off", () => {
  /**
   * The gate has nothing to offer here, and every self-hosted installation is
   * in this state once the server has answered. Sending somebody through an
   * advertising page that shows no advertising, and reloading the whole
   * application to get there, would be a cost paid for nothing.
   */
  it("goes straight to the queue without reloading anything", async () => {
    renderLink();

    expect(link()).toHaveAttribute("href", "/upload/bulk/session");
    expect(await clickReloadsThePage(link())).toBe(false);
  });

  it("carries the chosen destination folder to the queue", () => {
    renderLink({ search: "?folder=7" });

    expect(link()).toHaveAttribute("href", "/upload/bulk/session?folder=7");
  });
});
