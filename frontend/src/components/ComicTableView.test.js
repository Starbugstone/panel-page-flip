import { describe, expect, it } from "vitest";
import { getComicProgressState } from "@/lib/comic-progress.js";

describe("getComicProgressState", () => {
  it("marks comics without progress as not started", () => {
    expect(getComicProgressState({ pageCount: 20, readingProgress: null }).label).toBe("Not started");
  });

  it("marks completed comics as fully read", () => {
    const state = getComicProgressState({
      pageCount: 20,
      readingProgress: { currentPage: 20, completed: true },
    });

    expect(state.label).toBe("Fully read");
    expect(state.percent).toBe(100);
  });

  it("calculates in-progress percentage", () => {
    const state = getComicProgressState({
      pageCount: 20,
      readingProgress: { currentPage: 5, completed: false },
    });

    expect(state.label).toBe("In progress");
    expect(state.percent).toBe(25);
  });
});
