import { render } from "@testing-library/react";
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
});
