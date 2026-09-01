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
vi.mock("@/components/analytics/GoogleAnalyticsProvider.jsx", () => ({ GoogleAnalyticsProvider: ({ children }) => children }));
vi.mock("@/components/Header.jsx", () => ({ Header: () => <header>Application header</header> }));
vi.mock("@/components/AdminNoticeBanner.jsx", () => ({ AdminNoticeBanner: () => null }));
vi.mock("@/components/CookieNotice.jsx", () => ({ CookieNotice: () => null }));
vi.mock("@/components/Footer.jsx", () => ({ Footer: () => <footer>Application footer</footer> }));
vi.mock("@/components/SessionMonitor.jsx", () => ({ default: () => null }));
vi.mock("./pages/Login.jsx", () => ({ default: () => <div>Login route</div> }));

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

  it("redirects a signed-out visitor away from a protected route", async () => {
    window.history.pushState({}, "", "/dashboard");
    render(<App />);

    expect(await screen.findByText("Login route")).toBeInTheDocument();
    expect(window.location.pathname).toBe("/login");
  });
});
