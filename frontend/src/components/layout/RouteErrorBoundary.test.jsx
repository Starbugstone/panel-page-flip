import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import { RouteErrorBoundary } from "./RouteErrorBoundary";

describe("page rendering recovery", () => {
  afterEach(() => vi.restoreAllMocks());

  it("keeps a readable recovery page and allows retry without leaking error details", async () => {
    vi.spyOn(console, "error").mockImplementation(() => {});
    let fail = true;
    function Page() {
      if (fail) throw new Error("PRIVATE-token-and-server-path");
      return <h1>Recovered page</h1>;
    }
    render(<MemoryRouter><RouteErrorBoundary><Page /></RouteErrorBoundary></MemoryRouter>);

    expect(screen.getByRole("alert")).toHaveTextContent("This page could not be displayed");
    expect(screen.queryByText(/PRIVATE/)).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Back to home" })).toHaveAttribute("href", "/");
    fail = false;
    await userEvent.click(screen.getByRole("button", { name: "Try again" }));
    expect(screen.getByRole("heading", { name: "Recovered page" })).toBeInTheDocument();
  });
});
