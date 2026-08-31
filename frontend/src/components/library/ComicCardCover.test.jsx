import { act, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { ComicCardCover } from "./ComicCardCover";
import { FakeIntersectionObserver, scrollTo } from "@/test/fake-intersection-observer";

const comic = { id: 7, title: "Watchmen", coverImagePath: "/api/comics/cover/1/7/cover.jpg" };

const renderCover = (overrides = {}) => render(
  <ComicCardCover comic={{ ...comic, ...overrides }} coverPriority={false} isSharedWithMe={false} onResetProgress={() => {}} />
);

const coverImage = () => document.querySelector("img");
const coverState = () => document.querySelector("[data-cover-state]").dataset.coverState;
const loadingArt = () => document.querySelector('[class*="animate-cover-panel"]');
const onScreen = () => scrollTo("[data-cover-state]");
const settle = (outcome) => act(() => { fireEvent[outcome](coverImage()); });

describe("a comic's cover in the grid", () => {
  beforeEach(() => {
    vi.stubGlobal("IntersectionObserver", FakeIntersectionObserver);
  });

  afterEach(() => {
    FakeIntersectionObserver.reset();
    vi.unstubAllGlobals();
  });

  it("neither requests nor animates a cover nobody has scrolled to", () => {
    renderCover();

    expect(coverImage()).toBeNull();
    expect(loadingArt()).toBeNull();
    expect(coverState()).toBe("idle");
  });

  it("starts drawing the placeholder as the cover comes into view", async () => {
    renderCover();
    await onScreen();

    expect(loadingArt()).not.toBeNull();
    expect(coverState()).toBe("loading");
  });

  it("keeps the placeholder up until the cover has actually decoded", async () => {
    renderCover();
    await onScreen();

    expect(coverImage()).toHaveClass("opacity-0");
    expect(loadingArt()).not.toBeNull();

    settle("load");

    expect(coverImage()).toHaveClass("opacity-100");
    expect(loadingArt()).toBeNull();
  });

  it("offers a way back once a cover has been asked for as often as is polite", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      renderCover();
      await onScreen();

      for (let attempt = 0; attempt < 4; attempt += 1) {
        settle("error");
        await act(async () => { vi.advanceTimersByTime(10000); });
      }

      expect(coverState()).toBe("failed");
      await act(async () => {
        screen.getByRole("button", { name: "Retry cover for Watchmen" }).click();
      });

      expect(coverImage()).toHaveAttribute("src", "/api/comics/cover/1/7/cover.jpg?retry=4");
    } finally {
      vi.useRealTimers();
    }
  });

  it("does not ask the server for a cover a comic does not have", async () => {
    renderCover({ coverImagePath: null });
    await onScreen();

    expect(coverImage()).toBeNull();
    expect(loadingArt()).toBeNull();
    expect(coverState()).toBe("absent");
  });
});
