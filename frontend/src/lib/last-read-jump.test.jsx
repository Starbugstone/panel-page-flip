import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { jumpToComicCard, latestReadComic } from "./last-read-jump";

const readAt = (id, lastReadAt) => ({ id, readingProgress: { currentPage: 3, lastReadAt } });
const unread = (id) => ({ id, readingProgress: null });

describe("latestReadComic", () => {
  it("picks the comic with the newest lastReadAt", () => {
    const comics = [
      readAt(1, "2026-08-01T10:00:00+00:00"),
      readAt(2, "2026-08-20T10:00:00+00:00"),
      readAt(3, "2026-08-10T10:00:00+00:00"),
    ];

    expect(latestReadComic(comics)?.id).toBe(2);
  });

  it("ignores comics that were never opened", () => {
    expect(latestReadComic([unread(1), readAt(2, "2026-08-01T10:00:00+00:00"), unread(3)])?.id).toBe(2);
  });

  it("returns null when nothing in the list has been read", () => {
    expect(latestReadComic([unread(1), unread(2)])).toBeNull();
    expect(latestReadComic([])).toBeNull();
    expect(latestReadComic(undefined)).toBeNull();
  });

  it("treats an unparseable stamp as never read", () => {
    expect(latestReadComic([readAt(1, "not a date"), readAt(2, null)])).toBeNull();
  });
});

describe("jumpToComicCard", () => {
  let scrollIntoView;

  beforeEach(() => {
    vi.useFakeTimers();
    scrollIntoView = vi.fn();
    Element.prototype.scrollIntoView = scrollIntoView;
    document.body.innerHTML = '<div data-comic-id="7"></div><div data-comic-id="8"></div>';
  });

  afterEach(() => {
    vi.useRealTimers();
    delete Element.prototype.scrollIntoView;
    document.body.innerHTML = "";
  });

  it("scrolls the matching card to the centre of the view", () => {
    expect(jumpToComicCard(7)).toBe(true);

    expect(scrollIntoView).toHaveBeenCalledWith({ behavior: "smooth", block: "center" });
    expect(document.querySelector('[data-comic-id="8"]').classList.length).toBe(0);
  });

  it("highlights the card briefly, then puts it back as it was", () => {
    jumpToComicCard(7);
    const card = document.querySelector('[data-comic-id="7"]');

    expect(card.classList.contains("ring-comic-purple")).toBe(true);
    vi.runAllTimers();
    expect(card.classList.length).toBe(0);
  });

  it("reports a card that is not on the page without scrolling anything", () => {
    expect(jumpToComicCard(999)).toBe(false);
    expect(scrollIntoView).not.toHaveBeenCalled();
  });
});
