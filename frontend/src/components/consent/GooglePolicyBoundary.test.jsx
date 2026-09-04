import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { Link, MemoryRouter, useLocation } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";

import { GooglePolicyBoundary } from "./GooglePolicyBoundary";

function Destination() {
  const { pathname } = useLocation();
  return <span data-testid="destination">{pathname}</span>;
}

function renderBoundary(from, to, replaceDocument) {
  return render(
    <MemoryRouter initialEntries={[from]}>
      <Link to={to}>Continue</Link>
      <GooglePolicyBoundary replaceDocument={replaceDocument}>
        <Destination />
      </GooglePolicyBoundary>
    </MemoryRouter>
  );
}

describe("document CSP transitions", () => {
  it.each([
    ["/dashboard", "/privacy"],
    ["/privacy", "/?privacyChoices=open#preferences"],
  ])("loads a fresh document from %s to %s before mounting integrations", async (from, to) => {
    const replaceDocument = vi.fn();
    renderBoundary(from, to, replaceDocument);
    await userEvent.click(screen.getByRole("link", { name: "Continue" }));
    expect(replaceDocument).toHaveBeenCalledWith(to);
    expect(screen.queryByTestId("destination")).not.toBeInTheDocument();
  });

  it.each([["/privacy", "/cookies"], ["/dashboard", "/sharing"]])(
    "keeps client navigation within the same policy from %s to %s",
    async (from, to) => {
      const replaceDocument = vi.fn();
      renderBoundary(from, to, replaceDocument);
      await userEvent.click(screen.getByRole("link", { name: "Continue" }));
      expect(screen.getByTestId("destination")).toHaveTextContent(to);
      expect(replaceDocument).not.toHaveBeenCalled();
    }
  );
});
