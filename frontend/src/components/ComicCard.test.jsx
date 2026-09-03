import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { describe, expect, it, vi } from "vitest";
import { ComicCard } from "./ComicCard";

vi.mock("@/hooks/use-toast.js", () => ({ useToast: () => ({ toast: vi.fn() }) }));

describe("ComicCard", () => {
  it("carries its quick-view location into the reader", async () => {
    const user = userEvent.setup();
    const ReaderLocation = () => {
      const location = useLocation();
      return <span>{location.state?.libraryReturnTo || "no return"}</span>;
    };

    render(
      <MemoryRouter initialEntries={["/dashboard?view=reading"]}>
        <Routes>
          <Route path="/dashboard" element={(
            <ComicCard
              comic={{ id: 42, title: "Watchmen", author: "Moore" }}
              onResetProgress={vi.fn()}
              onEditComic={vi.fn()}
              onDeleteComic={vi.fn()}
              onShareClick={vi.fn()}
            />
          )} />
          <Route path="/read/:comicId" element={<ReaderLocation />} />
        </Routes>
      </MemoryRouter>
    );

    await user.click(screen.getByRole("link", { name: "Read Watchmen" }));

    expect(screen.getByText("/dashboard?view=reading")).toBeInTheDocument();
  });

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
