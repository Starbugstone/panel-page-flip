import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { Header } from "@/components/Header.jsx";

vi.mock("@/hooks/use-sharing", () => ({ useSharing: () => ({ summary: { pendingInvitations: 0 } }) }));
vi.mock("@/hooks/use-toast", () => ({ useToast: () => ({ toast: vi.fn() }) }));

const renderHeader = (pathname, { isAdmin = false } = {}) => render(
  <MemoryRouter initialEntries={[pathname]}>
    <Header isLoggedIn onLogout={vi.fn()} isAdmin={isAdmin} />
  </MemoryRouter>
);

const bulkLink = () => screen.getByRole("link", { name: "Bulk Upload" });

beforeEach(() => vi.clearAllMocks());

/**
 * Bulk upload became two routes when the rewarded gate was added: the gate at
 * /upload/bulk and the batch at /upload/bulk/session. The header was written
 * when it was one.
 */
describe("the bulk upload link", () => {
  it("goes to the gate from anywhere else", () => {
    renderHeader("/dashboard");

    expect(bulkLink()).toHaveAttribute("href", "/upload/bulk");
  });

  it("highlights while the gate is on screen", () => {
    renderHeader("/upload/bulk");

    expect(bulkLink().className).toContain("text-comic-purple");
  });

  /**
   * The gate redirects through to the batch in well under a second, so keying
   * the highlight on the gate's own path alone left the nav item dark for the
   * entire time bulk upload was actually being used — while /upload and
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
  it("does not leave the batch screen when it is already there", () => {
    renderHeader("/upload/bulk/session");

    expect(bulkLink()).toHaveAttribute("href", "/upload/bulk/session");
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
