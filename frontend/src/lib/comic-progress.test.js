import { describe, expect, it } from "vitest";
import { getComicProgressState, parsePageNumber } from "./comic-progress";

describe("parsePageNumber", () => {
  it("turns a one-based page into a zero-based index", () => {
    expect(parsePageNumber("1", 40)).toBe(0);
    expect(parsePageNumber("40", 40)).toBe(39);
    expect(parsePageNumber(12, 40)).toBe(11);
  });

  it("clamps a page outside the comic to its nearest end", () => {
    expect(parsePageNumber("500", 40)).toBe(39);
    expect(parsePageNumber("0", 40)).toBe(0);
    expect(parsePageNumber("-3", 40)).toBe(0);
  });

  it("rejects the half-typed states an input passes through", () => {
    expect(parsePageNumber("", 40)).toBeNull();
    expect(parsePageNumber("   ", 40)).toBeNull();
    expect(parsePageNumber("abc", 40)).toBeNull();
    expect(parsePageNumber("-", 40)).toBeNull();
  });

  it("rejects everything while the comic has no pages", () => {
    expect(parsePageNumber("1", 0)).toBeNull();
    expect(parsePageNumber("1", undefined)).toBeNull();
  });

  it("ignores trailing junk rather than refusing to move", () => {
    expect(parsePageNumber("7abc", 40)).toBe(6);
  });
});

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
