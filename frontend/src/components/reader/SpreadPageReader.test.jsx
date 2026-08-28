import { render, screen } from "@testing-library/react";
import { createRef } from "react";
import { describe, expect, it } from "vitest";

import { SpreadPageReader } from "./SpreadPageReader";

describe("spread sizing", () => {
  it("lets original-size pages establish the scrollable spread width without overlapping", () => {
    render(
      <SpreadPageReader
        containerRef={createRef()}
        contentRef={createRef()}
        fit="original"
        pages={[
          { pageIndex: 1, image: { src: "/pages/2" } },
          { pageIndex: 2, image: { src: "/pages/3" } },
        ]}
      />
    );

    const spread = document.querySelector('[data-reader-mode="double"]');
    const content = spread.firstElementChild;
    expect(content).toHaveClass("w-max", "max-w-none");
    [...content.children].forEach((slot) => expect(slot).toHaveClass("flex-none"));
  });

  it("does not let either page image become selected or dragged", () => {
    render(
      <SpreadPageReader
        containerRef={createRef()}
        contentRef={createRef()}
        fit="contain"
        pages={[
          { pageIndex: 1, image: { src: "/pages/2" } },
          { pageIndex: 2, image: { src: "/pages/3" } },
        ]}
      />
    );

    screen.getAllByRole("img").forEach((image) => {
      expect(image).toHaveClass("select-none");
      expect(image).toHaveAttribute("draggable", "false");
    });
  });
});

/**
 * A reading unit does not always hold two pages: the cover is kept alone by
 * default, and an odd-length comic ends on a lone page. Stretched across the
 * full width with nothing bounding its height, that page grew past the bottom
 * of the viewport and was clipped by the container's own `overflow-hidden` —
 * the last page of a comic, cut off.
 *
 * A percentage height only binds when every box above it has a definite one, so
 * these pin the chain rather than the symptom.
 */
describe("a reading unit holding a single page", () => {
  const renderSpread = (props = {}) => render(
    <SpreadPageReader
      containerRef={createRef()}
      contentRef={createRef()}
      fit="contain"
      pages={[{ pageIndex: 0, image: { src: "/pages/1" } }]}
      {...props}
    />
  );

  it("gives the spread a definite height for the page to be bounded by", () => {
    renderSpread();

    const content = document.querySelector('[data-reader-mode="double"]').firstElementChild;
    expect(content).toHaveClass("h-full");
    expect(content).not.toHaveClass("max-h-full");
  });

  it("centres the lone page in the height it is now bounded by", () => {
    renderSpread();

    const slot = document.querySelector('[data-reader-mode="double"]').firstElementChild.firstElementChild;
    expect(slot).toHaveClass("h-full", "items-center");
  });

  it("keeps the artwork inside both axes of the viewport", () => {
    renderSpread();

    expect(screen.getByRole("img")).toHaveClass("max-h-full", "max-w-full", "object-contain");
  });

  /** Fit-width scrolls instead of letterboxing, so it must not be pinned to the viewport. */
  it("leaves a scrolling fit to size itself by width", () => {
    renderSpread({ fit: "width" });

    const content = document.querySelector('[data-reader-mode="double"]').firstElementChild;
    expect(content).toHaveClass("w-full", "items-start");
    expect(content).not.toHaveClass("h-full");
  });
});
