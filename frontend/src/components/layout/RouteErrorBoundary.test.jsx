import { render, screen } from "@testing-library/react";
import { lazy, Suspense } from "react";
import { MemoryRouter } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import { RouteErrorBoundary } from "./RouteErrorBoundary";

describe("page rendering recovery", () => {
  afterEach(() => vi.restoreAllMocks());

  it("keeps a readable recovery page without leaking error details", () => {
    vi.spyOn(console, "error").mockImplementation(() => {});
    function Page() {
      throw new Error("PRIVATE-token-and-server-path");
    }
    render(<MemoryRouter><RouteErrorBoundary><Page /></RouteErrorBoundary></MemoryRouter>);

    expect(screen.getByRole("alert")).toHaveTextContent("This page could not be displayed");
    expect(screen.queryByText(/PRIVATE/)).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Back to home" })).toHaveAttribute("href", "/");
  });

  it("offers a document reload when a rejected lazy import cannot be retried in memory", async () => {
    vi.spyOn(console, "error").mockImplementation(() => {});
    const Page = lazy(() => Promise.reject(new TypeError("Failed to fetch dynamically imported module")));
    render(<MemoryRouter><RouteErrorBoundary><Suspense fallback="Loading"><Page /></Suspense></RouteErrorBoundary></MemoryRouter>);

    const reload = await screen.findByRole("link", { name: "Reload page" });
    expect(reload).toHaveAttribute("href", window.location.href);
  });
});
