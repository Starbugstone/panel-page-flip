import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { createRef } from "react";

import { SinglePageReader } from "./SinglePageReader";

const renderPage = (props = {}) => render(
  <SinglePageReader
    containerRef={createRef()}
    imageRef={createRef()}
    image={{ src: "/api/comics/1/pages/1" }}
    pageNumber={1}
    title="Sandman"
    fit="contain"
    {...props}
  />
);

const pageImage = () => screen.getByAltText(/page 1 of sandman/i);
const surface = () => document.querySelector("[data-page-fit]");

const pointerDown = (pointerType) => {
  const event = new Event("pointerdown", { bubbles: true });
  Object.assign(event, { pointerId: 1, clientX: 10, clientY: 10, pointerType });
  surface().dispatchEvent(event);
};

describe("the page surface", () => {
  it("gives the fitted page's vertical scrolling back to the browser", () => {
    renderPage({ fit: "width" });

    expect(surface()).toHaveStyle({ touchAction: "pan-y" });
  });

  it("leaves both axes to the browser at original size, which overflows in both", () => {
    renderPage({ fit: "original" });

    expect(surface()).toHaveStyle({ touchAction: "pan-x pan-y" });
  });

  it("takes both axes once the page is zoomed, because it is panning them", () => {
    renderPage({ transform: { scale: 2, x: 10, y: 20 } });

    expect(surface()).toHaveStyle({ touchAction: "none" });
    expect(pageImage()).toHaveStyle({ transform: "translate3d(10px, 20px, 0) scale(2)" });
  });

  it("follows the finger of a swipe that has not been resolved yet", () => {
    renderPage({ swipeOffset: -40, isSwiping: true });

    expect(pageImage()).toHaveStyle({ transform: "translate3d(-40px, 0px, 0) scale(1)" });
  });

  it("lets a mouse click a zoomed page back to fitting", () => {
    const onImageClick = vi.fn();
    renderPage({ transform: { scale: 2, x: 0, y: 0 }, onImageClick });

    pointerDown("mouse");
    fireEvent.click(pageImage());

    expect(onImageClick).toHaveBeenCalled();
  });

  /**
   * The browser sends a click after a tap as well. Arriving on the zoom the
   * second tap of a double tap had just applied, it would take it straight back
   * off — which reads as a double tap that does nothing at all.
   */
  it("ignores the click a touchscreen sends after a tap", () => {
    const onImageClick = vi.fn();
    renderPage({ transform: { scale: 2, x: 0, y: 0 }, onImageClick });

    pointerDown("touch");
    fireEvent.click(pageImage());

    expect(onImageClick).not.toHaveBeenCalled();
  });
});
