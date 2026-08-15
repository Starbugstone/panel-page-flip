import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { ReaderThumbnailStrip } from "./ReaderThumbnailStrip";

const renderStrip = (props = {}) => render(
  <ReaderThumbnailStrip
    comicId="42"
    pageCount={400}
    currentPage={0}
    onSelect={() => {}}
    {...props}
  />
);

describe("ReaderThumbnailStrip", () => {
  it("does not ask for four hundred thumbnails to show the first page", () => {
    renderStrip();

    const thumbnails = document.querySelectorAll("img");
    expect(thumbnails.length).toBeGreaterThan(0);
    expect(thumbnails.length).toBeLessThan(30);
    expect(screen.getAllByRole("button")).toHaveLength(400);
  });

  it("loads thumbnails around wherever the reader currently is", () => {
    renderStrip({ currentPage: 199 });

    const sources = [...document.querySelectorAll("img")].map((image) => image.getAttribute("src"));

    expect(sources).toContain("/api/comics/42/pages/200?variant=thumb");
    expect(sources).not.toContain("/api/comics/42/pages/1?variant=thumb");
  });

  it("asks for thumbnails, not full pages", () => {
    renderStrip({ pageCount: 3 });

    [...document.querySelectorAll("img")].forEach((image) => {
      expect(image.getAttribute("src")).toMatch(/variant=thumb$/);
      expect(image.getAttribute("loading")).toBe("lazy");
    });
  });

  it("turns to the logical page that was clicked", async () => {
    const user = userEvent.setup();
    const onSelect = vi.fn();
    renderStrip({ pageCount: 5, onSelect });

    await user.click(screen.getByRole("button", { name: "Go to page 4" }));

    // Zero-based index out, one-based page number in the label: navigation is
    // the reader's shared contract, and the strip does not get its own.
    expect(onSelect).toHaveBeenCalledWith(3);
  });

  it("says which page is the current one", () => {
    renderStrip({ pageCount: 5, currentPage: 2 });

    expect(screen.getByRole("button", { name: "Go to page 3" })).toHaveAttribute("aria-current", "true");
    expect(screen.getByRole("button", { name: "Go to page 1" })).not.toHaveAttribute("aria-current");
  });

  it("is reachable from the keyboard", async () => {
    const user = userEvent.setup();
    const onSelect = vi.fn();
    renderStrip({ pageCount: 3, onSelect });

    await user.tab();
    await user.tab();
    await user.keyboard("{Enter}");

    expect(onSelect).toHaveBeenCalledWith(1);
  });

  it("reserves the shape of a page it has been told about", () => {
    renderStrip({ pageCount: 2, geometry: { 1: { width: 3976, height: 3056, aspectRatio: 1.3 } } });

    const slot = screen.getByRole("button", { name: "Go to page 1" }).querySelector("span");
    expect(slot).toHaveStyle({ aspectRatio: "1.3" });
  });

  it("shows nothing at all for a comic with no pages", () => {
    const { container } = renderStrip({ pageCount: 0 });

    expect(container).toBeEmptyDOMElement();
  });
});
