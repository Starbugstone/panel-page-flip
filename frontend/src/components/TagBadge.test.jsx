import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { TagBadge } from "./TagBadge";

describe("TagBadge", () => {
  it("truncates an unbroken tag instead of widening a narrow container", () => {
    const name = "A-very-long-personal-tag-name-that-cannot-wrap";
    render(<TagBadge tag={{ name }} />);

    const label = screen.getByText(name);
    expect(label).toHaveClass("truncate");
    expect(label.parentElement).toHaveClass("min-w-0", "max-w-full");
  });
});
