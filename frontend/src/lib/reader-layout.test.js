import { describe, expect, it } from "vitest";

import {
  adjacentReadingPage,
  buildReadingUnits,
  displayOrderFor,
  pageRangeLabel,
  readingUnitForPage,
} from "./reader-layout";

describe("logical pages presented as spreads", () => {
  it.each([
    [1, [[0]]],
    [2, [[0], [1]]],
    [3, [[0], [1, 2]]],
    [4, [[0], [1, 2], [3]]],
    [5, [[0], [1, 2], [3, 4]]],
  ])("keeps a cover alone for a %i-page comic", (count, expected) => {
    expect(buildReadingUnits(count)).toEqual(expected);
  });

  it("pairs from the first page when the cover rule is disabled", () => {
    expect(buildReadingUnits(5, {}, { coverAlone: false })).toEqual([[0, 1], [2, 3], [4]]);
  });

  it("leaves wide scans alone and resumes pairing afterwards", () => {
    const geometry = {
      2: { aspectRatio: 1.6 },
      4: { aspectRatio: 1.4 },
      5: { aspectRatio: 1.8 },
    };
    expect(buildReadingUnits(7, geometry)).toEqual([[0], [1], [2], [3], [4], [5, 6]]);
  });

  it("restores either side of a pair without changing the logical page", () => {
    const units = buildReadingUnits(5);
    expect(readingUnitForPage(units, 2)).toEqual([1, 2]);
    expect(adjacentReadingPage(units, 2, "previous")).toBe(0);
    expect(adjacentReadingPage(units, 2, "next")).toBe(3);
  });

  it("reverses only visual placement for RTL", () => {
    expect(displayOrderFor([3, 4], "rtl")).toEqual([4, 3]);
    expect(displayOrderFor([3, 4], "ltr")).toEqual([3, 4]);
    expect(pageRangeLabel([4, 3])).toBe("4–5");
  });
});
