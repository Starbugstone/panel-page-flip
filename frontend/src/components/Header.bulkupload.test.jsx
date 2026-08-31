import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { Header } from "@/components/Header.jsx";

const { adSense } = vi.hoisted(() => ({ adSense: { isActive: false } }));

vi.mock("@/components/ads/AdSenseProvider.jsx", () => ({ useAdSense: () => adSense }));
vi.mock("@/hooks/use-sharing", () => ({ useSharing: () => ({ summary: { pendingInvitations: 0 } }) }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: vi.fn() }) }));

const renderHeader = (entry, { isAdmin = false } = {}) => render(
  <MemoryRouter initialEntries={[entry]}>
    <Header isLoggedIn onLogout={vi.fn()} isAdmin={isAdmin} />
  </MemoryRouter>
);

const bulkLink = () => screen.getByRole("link", { name: "Bulk Upload" });

beforeEach(() => {
  vi.clearAllMocks();
  adSense.isActive = false;
});

/**
 * Bulk upload uses two routes for the Offerwall boundary: the clean page at
 * /upload/bulk and the batch at /upload/bulk/session. The header was written
 * when it was one.
 */
describe("the bulk upload link", () => {
  it("skips the gate entirely where there is no advertising to show", () => {
    renderHeader("/dashboard");

    expect(bulkLink()).toHaveAttribute("href", "/upload/bulk/session");
  });

  it("goes through the gate where advertising is on", () => {
    adSense.isActive = true;

    renderHeader("/dashboard");

    expect(bulkLink()).toHaveAttribute("href", "/upload/bulk");
  });

  it("highlights while the gate is on screen", () => {
    renderHeader("/upload/bulk");

    expect(bulkLink().className).toContain("text-comic-purple");
  });

  /**
   * Bulk upload is spent almost entirely on the batch screen behind the gate,
   * so keying the highlight on the gate's own path alone left the nav item dark
   * for the whole time bulk upload was actually being used — while /upload and
   * /dashboard still lit up, so the header disagreed with itself.
   */
  it("stays highlighted on the batch screen behind it", () => {
    renderHeader("/upload/bulk/session");

    expect(bulkLink().className).toContain("text-comic-purple");
  });

  /**
   * Clicking through to the gate mid-batch would send the user back through a
   * redirect that remounts the queue — losing every title, tag, chosen folder
   * and progress bar in it, while the uploads already in flight carried on
   * against a tree nobody could see.
   */
  /**
   * Checked with advertising on, where the entry link would point at the gate:
   * that is what makes this distinguish staying put from being sent back
   * through it, and being sent back reloads the page and takes the titles,
   * tags and uploads in flight with it.
   */
  it("does not leave the batch screen when it is already there", () => {
    adSense.isActive = true;

    renderHeader("/upload/bulk/session?folder=7");

    expect(bulkLink()).toHaveAttribute("href", "/upload/bulk/session?folder=7");
  });
});

describe("the admin destination", () => {
  it("uses Admin dashboard as its visible and accessible name", () => {
    renderHeader("/dashboard", { isAdmin: true });

    expect(screen.getByRole("link", { name: "Admin dashboard" })).toHaveTextContent("Admin dashboard");
  });
});

describe("the Dropbox destination", () => {
  it("describes the one-way workflow as an import", () => {
    renderHeader("/dashboard");

    expect(screen.getByRole("link", { name: "Dropbox Import" })).toHaveTextContent("Dropbox Import");
    expect(screen.queryByRole("link", { name: "Dropbox Sync" })).not.toBeInTheDocument();
  });
});

describe("the narrow-screen navigation", () => {
  it("puts every signed-in destination in one comfortably sized menu", async () => {
    const user = userEvent.setup();
    renderHeader("/dashboard", { isAdmin: true });

    await user.click(screen.getByRole("button", { name: "Open navigation menu" }));

    const menu = screen.getByRole("menu");
    expect(screen.getByRole("menuitem", { name: "My Comics" })).toHaveClass("min-h-11");
    expect(screen.getByRole("menuitem", { name: "Upload Comic" })).toBeInTheDocument();
    expect(screen.getByRole("menuitem", { name: "Bulk Upload" })).toBeInTheDocument();
    expect(screen.getByRole("menuitem", { name: "Sharing" })).toBeInTheDocument();
    expect(screen.getByRole("menuitem", { name: "Dropbox Import" })).toBeInTheDocument();
    expect(screen.getByRole("menuitem", { name: "Settings" })).toBeInTheDocument();
    expect(screen.getByRole("menuitem", { name: "Admin dashboard" })).toBeInTheDocument();
    expect(screen.getByRole("menuitem", { name: "Logout" })).toBeInTheDocument();
    expect(menu).toBeInTheDocument();
  });
});
