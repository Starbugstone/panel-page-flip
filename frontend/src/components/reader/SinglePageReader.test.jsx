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

const pointerDown = (pointerType, { x = 10, y = 10 } = {}) => {
  const event = new Event("pointerdown", { bubbles: true });
  Object.assign(event, { pointerId: 1, clientX: x, clientY: y, pointerType, button: 0 });
  surface().dispatchEvent(event);
};

const pointerMove = (x, y) => {
  const event = new Event("pointermove", { bubbles: true });
  Object.assign(event, { pointerId: 1, clientX: x, clientY: y, pointerType: "mouse" });
  surface().dispatchEvent(event);
};

const pointerUp = () => {
  const event = new Event("pointerup", { bubbles: true });
  Object.assign(event, { pointerId: 1, clientX: 0, clientY: 0, pointerType: "mouse" });
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

  it("reports a mouse click on the mat around the page", () => {
    const onSurfaceClick = vi.fn();
    renderPage({ onSurfaceClick });

    pointerDown("mouse");
    fireEvent.click(surface());

    expect(onSurfaceClick).toHaveBeenCalled();
  });

  /**
   * The click still reaches the caller — the artwork is inside the viewport and
   * the event bubbles — because deciding what a click on a page may mean is the
   * reader's job. What matters here is that the target says where it landed, so
   * that decision can be made at all.
   */
  it("says whether a click landed on the artwork", () => {
    const onSurfaceClick = vi.fn();
    renderPage({ onSurfaceClick });

    pointerDown("mouse");
    fireEvent.click(pageImage());

    const [event] = onSurfaceClick.mock.calls[0];
    expect(event.target.closest("[data-reader-artwork]")).not.toBeNull();
  });

  /**
   * The browser sends a click after a tap as well. Arriving on the zoom the
   * second tap of a double tap had just applied, it would take it straight back
   * off — which reads as a double tap that does nothing at all.
   */
  it("ignores the click a touchscreen sends after a tap", () => {
    const onSurfaceClick = vi.fn();
    renderPage({ transform: { scale: 2, x: 0, y: 0 }, onSurfaceClick });

    pointerDown("touch");
    fireEvent.click(pageImage());

    expect(onSurfaceClick).not.toHaveBeenCalled();
  });
});

describe("dragging a zoomed page with a mouse", () => {
  it("offers the grab cursor only while there is something to move", () => {
    const { rerender } = renderPage();
    expect(surface().className).not.toMatch(/cursor-grab/);

    rerender(
      <SinglePageReader
        containerRef={createRef()}
        imageRef={createRef()}
        image={{ src: "/api/comics/1/pages/1" }}
        pageNumber={1}
        title="Sandman"
        fit="contain"
        transform={{ scale: 2, x: 0, y: 0 }}
      />
    );

    expect(surface().className).toMatch(/cursor-grab/);
  });

  it("pans by the distance the mouse travelled", () => {
    const onPan = vi.fn();
    renderPage({ transform: { scale: 2, x: 0, y: 0 }, gestures: { onPan } });

    pointerDown("mouse", { x: 100, y: 100 });
    pointerMove(130, 90);
    pointerUp();

    expect(onPan).toHaveBeenCalledWith({ dx: 30, dy: -10 });
  });

  /**
   * Letting go after a drag must not also read as a click, or the zoom-out the
   * click means would undo the pan that was just made.
   */
  it("swallows the click that ends a drag", () => {
    const onSurfaceClick = vi.fn();
    renderPage({ transform: { scale: 2, x: 0, y: 0 }, onSurfaceClick, gestures: { onPan: vi.fn() } });

    pointerDown("mouse", { x: 100, y: 100 });
    pointerMove(160, 100);
    pointerUp();
    fireEvent.click(surface());

    expect(onSurfaceClick).not.toHaveBeenCalled();
  });

  it("still reports a click that never moved", () => {
    const onSurfaceClick = vi.fn();
    renderPage({ transform: { scale: 2, x: 0, y: 0 }, onSurfaceClick, gestures: { onPan: vi.fn() } });

    pointerDown("mouse", { x: 100, y: 100 });
    pointerUp();
    fireEvent.click(surface());

    expect(onSurfaceClick).toHaveBeenCalled();
  });

  it("leaves a page at natural scale alone", () => {
    const onPan = vi.fn();
    renderPage({ gestures: { onPan } });

    pointerDown("mouse", { x: 100, y: 100 });
    pointerMove(160, 100);
    pointerUp();

    expect(onPan).not.toHaveBeenCalled();
  });
});
