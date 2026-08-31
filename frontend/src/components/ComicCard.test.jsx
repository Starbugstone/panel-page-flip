import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";
import { ComicCard } from "./ComicCard";

vi.mock("@/hooks/use-toast.js", () => ({ useToast: () => ({ toast: vi.fn() }) }));

describe("ComicCard", () => {
  it("exposes the comic id for last-read lookup", () => {
    render(
      <MemoryRouter>
        <ComicCard
          comic={{ id: 42, title: "Watchmen", author: "Moore" }}
          onResetProgress={vi.fn()}
          onEditComic={vi.fn()}
          onDeleteComic={vi.fn()}
          onShareClick={vi.fn()}
        />
      </MemoryRouter>
    );

    expect(document.querySelector("[data-comic-id]")).toHaveAttribute("data-comic-id", "42");
  });

  it("keeps card actions outside the link that opens the reader", () => {
    render(
      <MemoryRouter>
        <ComicCard
          comic={{ id: 42, title: "Watchmen", author: "Moore", lastReadPage: 3, pageCount: 12 }}
          onResetProgress={vi.fn()}
          onEditComic={vi.fn()}
          onDeleteComic={vi.fn()}
          onShareClick={vi.fn()}
        />
      </MemoryRouter>
    );

    const readLink = screen.getByRole("link", { name: "Read Watchmen" });
    const reset = screen.getByRole("button", { name: "Reset reading progress for Watchmen" });

    expect(readLink).toHaveAttribute("href", "/read/42");
    expect(readLink.querySelector("button")).toBeNull();
    expect(reset.closest("a")).toBeNull();
  });
});
