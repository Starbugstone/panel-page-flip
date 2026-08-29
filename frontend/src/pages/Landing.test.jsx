import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { describe, expect, it } from "vitest";

import { landingCopy, landingPhrases } from "@/lib/landing-copy.js";
import Landing from "./Landing";

describe("Landing", () => {
  it("renders every public phrase from the shared landing copy", () => {
    render(
      <MemoryRouter>
        <Landing />
      </MemoryRouter>
    );

    const text = document.body.textContent ?? "";
    for (const phrase of landingPhrases(landingCopy)) {
      expect(text).toContain(phrase);
    }
  });

  it("explains the library, sharing, and page delivery experience", () => {
    render(
      <MemoryRouter>
        <Landing />
      </MemoryRouter>
    );

    expect(screen.getByRole("heading", { name: /your comics,\s*ready when you are/i })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: /a library that feels like yours/i })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: /share the good stuff/i })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: /built for the next page/i })).toBeInTheDocument();
    expect(screen.getByText(/delivers each page individually/i)).toBeInTheDocument();
    expect(screen.getByText(/right-sized for the screen and cached/i)).toBeInTheDocument();
    expect(screen.getAllByRole("link", { name: /start your library/i })).toHaveLength(2);
    expect(screen.getAllByRole("link", { name: /start your library/i })[0]).toHaveAttribute("href", "/login?signup=true");
    expect(screen.getByRole("link", { name: /log in/i })).toHaveAttribute("href", "/login");
  });

  it("leaves footer ownership to the application shell", () => {
    const { container } = render(
      <MemoryRouter>
        <Landing />
      </MemoryRouter>
    );

    expect(container.querySelector("footer")).not.toBeInTheDocument();
  });
});
