import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { ComicCardDetails } from "@/components/library/ComicCardDetails";

describe("ComicCardDetails", () => {
  it("allows the full comic title to wrap across lines", () => {
    render(<ComicCardDetails comic={{ title: "A very long comic title", author: "An author" }} />);

    const title = screen.getByRole("heading", { name: "A very long comic title" });
    expect(title).toHaveClass("whitespace-normal", "break-words");
    expect(title).not.toHaveClass("truncate");
  });
});
