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
});
