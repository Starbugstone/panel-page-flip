import { act, render, waitFor } from "@testing-library/react";
import { createRef } from "react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { ContinuousPageReader } from "./ContinuousPageReader";

const observers = [];

class TestIntersectionObserver {
  constructor(callback, options) {
    this.callback = callback;
    this.options = options;
    this.nodes = [];
    observers.push(this);
  }

  observe(node) {
    this.nodes.push(node);
  }

  disconnect() {}

  emit(entries) {
    this.callback(entries);
  }
}

describe("continuous page proximity", () => {
  afterEach(() => {
    observers.length = 0;
    vi.unstubAllGlobals();
  });

  it("releases a page that left the preload margin even if it was current when it left", async () => {
    vi.stubGlobal("IntersectionObserver", TestIntersectionObserver);
    const containerRef = createRef();
    const props = {
      containerRef,
      comicId: "42",
      pageCount: 8,
      title: "Sandman",
      geometry: {},
      resetToken: "portrait:continuous",
      onCurrentPageChange: vi.fn(),
      onActivity: vi.fn(),
    };
    const { rerender } = render(<ContinuousPageReader {...props} currentPage={0} />);

    await waitFor(() => expect(observers).toHaveLength(2));
    const firstPage = document.querySelector('[data-continuous-page="0"]');
    expect(firstPage.querySelector("img")).not.toBeNull();

    act(() => observers[0].emit([{ target: firstPage, isIntersecting: false }]));
    rerender(<ContinuousPageReader {...props} currentPage={3} />);

    expect(firstPage.querySelector("img")).toBeNull();
    expect(document.querySelector('[data-continuous-page="3"] img')).not.toBeNull();
  });

  it("expands page layout for settings zoom without breaking vertical flow", () => {
    const containerRef = createRef();
    render(
      <ContinuousPageReader
        containerRef={containerRef}
        comicId="42"
        pageCount={2}
        currentPage={0}
        title="Sandman"
        geometry={{}}
        resetToken="portrait:continuous"
        zoomLevel={1.5}
      />
    );

    const reader = document.querySelector('[data-reader-mode="continuous"]');
    const firstPage = document.querySelector('[data-continuous-page="0"]');
    expect(reader).toHaveAttribute("data-continuous-zoom", "1.5");
    expect(reader).toHaveClass("overflow-auto");
    expect(firstPage).toHaveStyle({ width: "150%", maxWidth: "84rem", touchAction: "pan-x pan-y" });
    expect(firstPage.querySelector("img")).toHaveClass("select-none");
  });

  it("asks for the size the widened page actually occupies, not the zoom counted twice", async () => {
    vi.stubGlobal("IntersectionObserver", TestIntersectionObserver);
    // A zoomed page is laid out wider, so the width measured off its container
    // already carries the zoom. 800 measured CSS pixels at a ratio of 1 are the
    // small rung whatever the slider says; counting the zoom again would reach
    // for the large one and download it on every page in the scroll.
    Object.defineProperty(HTMLElement.prototype, "clientWidth", { configurable: true, get: () => 800 });

    try {
      render(
        <ContinuousPageReader
          containerRef={createRef()}
          comicId="42"
          pageCount={2}
          currentPage={0}
          title="Sandman"
          geometry={{}}
          resetToken="portrait:continuous"
          zoomLevel={3}
        />
      );

      await waitFor(() => expect(document.querySelector('[data-continuous-page="0"] img')).toHaveAttribute(
        "src",
        expect.stringContaining("reader-small")
      ));
    } finally {
      // jsdom defines clientWidth on Element, so the shadowing property added
      // above is removed rather than restored.
      delete HTMLElement.prototype.clientWidth;
    }
  });
});
