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

/**
 * A fit means the same thing in both readers, off one shared table.
 *
 * Asserted from the spread side as well as the single-page side deliberately:
 * the table is shared so the two cannot drift, and a test on only one of them
 * would let a change made for that reader quietly re-open the gap in the other.
 */
describe("what the browser may still do with a touch", () => {
  const renderSpread = (props = {}) => render(
    <SpreadPageReader
      containerRef={createRef()}
      contentRef={createRef()}
      fit="contain"
      pages={[{ pageIndex: 0, image: { src: "/pages/1" } }]}
      {...props}
    />
  );
  const surface = () => document.querySelector('[data-reader-mode="double"]');

  it("gives vertical scrolling back to the browser at a letterboxing fit", () => {
    renderSpread({ fit: "contain" });

    expect(surface()).toHaveStyle({ touchAction: "pan-y" });
  });

  it("leaves both axes to the browser at original size, which overflows in both", () => {
    renderSpread({ fit: "original" });

    expect(surface()).toHaveStyle({ touchAction: "pan-x pan-y" });
  });

  it("takes both axes for the gestures once the spread is zoomed", () => {
    renderSpread({ fit: "contain", transform: { scale: 2, x: 0, y: 0 } });

    expect(surface()).toHaveStyle({ touchAction: "none" });
  });
});
