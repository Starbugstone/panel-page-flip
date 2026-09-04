import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import App from "./App";

const auth = vi.hoisted(() => ({
  isAuthenticated: false,
  loading: false,
  isAdmin: false,
  logout: vi.fn(),
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
vi.mock("@/components/consent/AnalyticsConsentDialog.jsx", () => ({ AnalyticsConsentDialog: () => null }));
vi.mock("@/components/analytics/GoogleAnalyticsProvider.jsx", () => ({ GoogleAnalyticsProvider: ({ children }) => children }));
vi.mock("@/components/Header.jsx", () => ({ Header: () => <header>Application header</header> }));
vi.mock("@/components/AdminNoticeBanner.jsx", () => ({ AdminNoticeBanner: () => null }));
vi.mock("@/components/CookieNotice.jsx", () => ({ CookieNotice: () => null }));
vi.mock("@/components/Footer.jsx", () => ({ Footer: () => <footer>Application footer</footer> }));
vi.mock("@/components/SessionMonitor.jsx", () => ({ default: () => null }));
vi.mock("./pages/Login.jsx", () => ({ default: () => <div>Login route</div> }));
vi.mock("./pages/Dashboard.jsx", () => ({ default: () => <div>Dashboard route</div> }));
vi.mock("./pages/AdminDashboard.jsx", () => ({ default: () => <div>Admin route</div> }));

describe("application shell", () => {
  beforeEach(() => {
    auth.isAuthenticated = false;
    auth.loading = false;
    auth.isAdmin = false;
  });

  it("renders the real not-found route inside the shared shell", async () => {
    window.history.pushState({}, "", "/route-that-does-not-exist");
    render(<App />);

    expect(await screen.findByRole("heading", { name: "404" })).toBeInTheDocument();
    expect(screen.getByText("Application header")).toBeInTheDocument();
    expect(screen.getByText("Application footer")).toBeInTheDocument();
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
