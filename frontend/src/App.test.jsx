import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { Link } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import App from "./App";

const auth = vi.hoisted(() => ({
  isAuthenticated: false,
  loading: false,
  isAdmin: false,
  logout: vi.fn(),
  crashDashboard: false,
}));

vi.mock("./hooks/use-auth.jsx", () => ({
  AuthProvider: ({ children }) => children,
  useAuth: () => auth,
}));
vi.mock("./hooks/use-tags.jsx", () => ({ TagProvider: ({ children }) => children }));
vi.mock("./hooks/use-comic-library.jsx", () => ({ ComicLibraryProvider: ({ children }) => children }));
vi.mock("./hooks/use-sharing.jsx", () => ({ SharingProvider: ({ children }) => children }));
vi.mock("@/components/config/PublicConfigProvider.jsx", () => ({ PublicConfigProvider: ({ children }) => children }));
vi.mock("@/components/ads/AdSenseProvider.jsx", () => ({ AdSenseProvider: ({ children }) => children }));
vi.mock("@/components/consent/ConsentProvider.jsx", () => ({ ConsentProvider: ({ children }) => children }));
vi.mock("@/components/consent/ConsentBanner.jsx", () => ({ ConsentBanner: () => null }));
vi.mock("@/components/analytics/GoogleAnalyticsProvider.jsx", () => ({ GoogleAnalyticsProvider: ({ children }) => children }));
vi.mock("@/components/Header.jsx", () => ({ Header: () => <header>Application header<Link to="/admin">Administration</Link></header> }));
vi.mock("@/components/AdminNoticeBanner.jsx", () => ({ AdminNoticeBanner: () => null }));
vi.mock("@/components/CookieNotice.jsx", () => ({ CookieNotice: () => null }));
vi.mock("@/components/Footer.jsx", () => ({ Footer: () => <footer>Application footer</footer> }));
vi.mock("@/components/SessionMonitor.jsx", () => ({ default: () => null }));
vi.mock("./pages/Login.jsx", () => ({ default: () => <div>Login route</div> }));
vi.mock("./pages/Dashboard.jsx", () => ({ default: () => {
  if (auth.crashDashboard) throw new Error("Private exception details");
  return <div>Dashboard route</div>;
} }));
vi.mock("./pages/AdminDashboard.jsx", () => ({ default: () => <div>Admin route</div> }));

describe("application shell", () => {
  beforeEach(() => {
    auth.isAuthenticated = false;
    auth.loading = false;
    auth.isAdmin = false;
    auth.crashDashboard = false;
    vi.spyOn(window, "scrollTo").mockImplementation(() => {});
  });

  it("keeps navigation usable after a route crashes and recovers on a different route", async () => {
    const consoleError = vi.spyOn(console, "error").mockImplementation(() => {});
    auth.isAuthenticated = true;
    auth.isAdmin = true;
    auth.crashDashboard = true;
    window.history.pushState({}, "", "/dashboard");
    try {
      render(<App />);
      expect(await screen.findByRole("alert")).toHaveTextContent("This page could not be displayed");
      expect(screen.queryByText("Private exception details")).not.toBeInTheDocument();
      await userEvent.click(screen.getByRole("link", { name: "Administration" }));
      expect(await screen.findByText("Admin route")).toBeInTheDocument();
      expect(screen.queryByRole("alert")).not.toBeInTheDocument();
    } finally {
      consoleError.mockRestore();
    }
  });

  it("renders the real not-found route inside the shared shell", async () => {
    window.history.pushState({}, "", "/route-that-does-not-exist");
    render(<App />);

    expect(await screen.findByRole("heading", { name: "404" })).toBeInTheDocument();
    expect(screen.getByText("Application header")).toBeInTheDocument();
    expect(screen.getByText("Application footer")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Skip to content" })).toHaveAttribute("href", "#main-content");
    expect(screen.getByRole("main")).toHaveAttribute("id", "main-content");
  });

  it("preserves a privacy-panel request when a signed-in landing page redirects", async () => {
    auth.isAuthenticated = true;
    window.history.pushState({}, "", "/?privacyChoices=open");
    render(<App />);
    expect(await screen.findByText("Dashboard route")).toBeInTheDocument();
    expect(window.location.search).toBe("?privacyChoices=open");
  });

  it("redirects a signed-out visitor away from a protected route", async () => {
    window.history.pushState({}, "", "/dashboard");
    render(<App />);

    expect(await screen.findByText("Login route")).toBeInTheDocument();
    expect(window.location.pathname).toBe("/login");
    expect(window.location.search).toBe("?redirect=%2Fdashboard");
  });

  it("returns a signed-out administrator to the exact admin view they requested", async () => {
    window.history.pushState({}, "", "/admin?tab=sharing-codes");
    render(<App />);

    expect(await screen.findByText("Login route")).toBeInTheDocument();
    expect(window.location.pathname).toBe("/login");
    expect(window.location.search).toBe("?redirect=%2Fadmin%3Ftab%3Dsharing-codes");
  });

  it("completes the stored redirect after authentication changes the route guard", async () => {
    auth.isAuthenticated = true;
    auth.isAdmin = true;
    window.history.pushState({}, "", "/login?redirect=%2Fadmin%3Ftab%3Dsharing-codes");
    render(<App />);

    expect(await screen.findByText("Admin route")).toBeInTheDocument();
    expect(window.location.pathname).toBe("/admin");
    expect(window.location.search).toBe("?tab=sharing-codes");
  });
});
