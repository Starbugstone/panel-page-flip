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

  it("shows the previous title while an automatic rename is being previewed", () => {
    render(<ComicCardDetails comic={{
      title: "DragonBall 01",
      autoRenameOriginalTitle: "DragonBall 1",
      author: "Toriyama",
    }} />);

    expect(screen.getByText("Was DragonBall 1")).toBeInTheDocument();
  });
});
