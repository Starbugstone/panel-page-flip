import { renderHook } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { useJumpToComic } from "./use-jump-to-comic";

const jumpToComicCard = vi.fn();

vi.mock("@/lib/last-read-jump", () => ({ jumpToComicCard: (...args) => jumpToComicCard(...args) }));

const setup = (comicId, comics, ready = true) => renderHook(
  ({ id, list, rendered }) => useJumpToComic(id, list, rendered),
  { initialProps: { id: comicId, list: comics, rendered: ready } }
);

beforeEach(() => {
  jumpToComicCard.mockReset();
  jumpToComicCard.mockReturnValue(true);
});

afterEach(() => {
  vi.clearAllMocks();
});

describe("useJumpToComic", () => {
  it("scrolls to the comic the URL named", () => {
    setup(42, [{ id: 41 }, { id: 42 }]);

    expect(jumpToComicCard).toHaveBeenCalledWith(42);
  });

  it("does nothing when the URL named no comic", () => {
    setup(null, [{ id: 42 }]);

    expect(jumpToComicCard).not.toHaveBeenCalled();
  });

  /**
   * The list arrives after the first render — later still inside a folder,
   * which waits for the tree. Jumping only on mount jumped at an empty grid.
   */
  it("waits for the comic to arrive, then jumps once", () => {
    const { rerender } = setup(42, []);
    expect(jumpToComicCard).not.toHaveBeenCalled();

    rerender({ id: 42, list: [{ id: 42 }] });
    expect(jumpToComicCard).toHaveBeenCalledTimes(1);

    rerender({ id: 42, list: [{ id: 42 }, { id: 43 }] });
    expect(jumpToComicCard).toHaveBeenCalledTimes(1);
  });

  // A card can be in the list a render before it is in the DOM.
  it("tries again when the card was not on the page yet", () => {
    jumpToComicCard.mockReturnValue(false);
    const { rerender } = setup(42, [{ id: 42 }]);

    jumpToComicCard.mockReturnValue(true);
    rerender({ id: 42, list: [{ id: 42 }, { id: 43 }] });

    expect(jumpToComicCard).toHaveBeenCalledTimes(2);
  });

  it("does not chase a comic that is not in this list", () => {
    setup(42, [{ id: 41 }, { id: 43 }]);

    expect(jumpToComicCard).not.toHaveBeenCalled();
  });

  it("waits until the grid replaces its loading skeleton", () => {
    const list = [{ id: 42 }];
    const { rerender } = setup(42, list, false);
    expect(jumpToComicCard).not.toHaveBeenCalled();

    // The same cached array can sit behind both renders, so readiness itself
    // must retry the jump.
    rerender({ id: 42, list, rendered: true });
    expect(jumpToComicCard).toHaveBeenCalledWith(42);
  });
});
