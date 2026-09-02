import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { BackToLibraryLink } from "@/components/BackToLibraryLink.jsx";

const { library } = vi.hoisted(() => ({ library: { comics: [] } }));

vi.mock("@/hooks/use-comic-library.jsx", () => ({ useComicLibrary: () => library }));

const renderLink = (path, state) => render(
  <MemoryRouter initialEntries={[{ pathname: path, state }]}>
    <BackToLibraryLink className="text-sm" />
  </MemoryRouter>
);

const link = () => screen.getByRole("link", { name: "Back to Library" });

beforeEach(() => {
  library.comics = [
    { id: 41, libraryFolderId: null },
    { id: 42, libraryFolderId: 7 },
  ];
});

/**
 * Leaving the reader used to land at the top of the whole library, which for a
 * comic filed three folders deep meant finding it again by hand.
 */
describe("the reader's way back", () => {
  it("returns to the quick view that opened the reader", () => {
    renderLink("/read/42", { libraryReturnTo: "/dashboard?view=reading" });

    expect(link()).toHaveAttribute("href", "/dashboard?view=reading&jump=42");
  });

  it("returns to the folder the comic is in, and to the comic", () => {
    renderLink("/read/42");

    expect(link()).toHaveAttribute("href", "/dashboard?folder=7&jump=42");
  });

  it("returns to the comic itself when it is filed nowhere", () => {
    renderLink("/read/41");

    expect(link()).toHaveAttribute("href", "/dashboard?jump=41");
  });

  // A bookmark or a shared link opens the reader with no library behind it.
  it("falls back to the plain library for a comic that was never listed", () => {
    renderLink("/read/99");

    expect(link()).toHaveAttribute("href", "/dashboard");
  });

  it("falls back to the plain library when the path names no comic", () => {
    renderLink("/read/");

    expect(link()).toHaveAttribute("href", "/dashboard");
  });

  it("does not read a numeric prefix from a malformed reader path", () => {
    renderLink("/read/42oops");

    expect(link()).toHaveAttribute("href", "/dashboard");
  });
});
