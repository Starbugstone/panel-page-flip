import { describe, expect, it } from "vitest";
import { headerCheckboxState, rowIdsInRange, selectedRowsOf } from "./row-selection";

const row = (id) => ({ id, name: `Row ${id}` });

describe("rowIdsInRange", () => {
  const rows = [row(1), row(2), row(3), row(4)];

  it("covers both ends of the range", () => {
    expect(rowIdsInRange(rows, 2, 4)).toEqual([2, 3, 4]);
  });

  it("reads the same range backwards", () => {
    expect(rowIdsInRange(rows, 4, 2)).toEqual([2, 3, 4]);
  });

  it("is a single row when both ends are the same", () => {
    expect(rowIdsInRange(rows, 3, 3)).toEqual([3]);
  });

  /**
   * The anchor was set on a row that has since been paged or filtered away.
   * Measuring from a stale index would select an arbitrary band of rows the
   * user never pointed at, so there is no range at all.
   */
  it("has no range to an end that is not on the list", () => {
    expect(rowIdsInRange(rows, 1, 99)).toEqual([]);
    expect(rowIdsInRange(rows, 99, 1)).toEqual([]);
  });
});

describe("selectedRowsOf", () => {
  const rows = [row(1), row(2), row(3)];

  it("keeps the order the table shows", () => {
    expect(selectedRowsOf(rows, new Set([3, 1])).map((r) => r.id)).toEqual([1, 3]);
  });

  it("ignores ids left over from a list that has since changed", () => {
    expect(selectedRowsOf(rows, new Set([1, 99])).map((r) => r.id)).toEqual([1]);
  });
});

describe("headerCheckboxState", () => {
  it("is ticked when every row on the page is selected", () => {
    expect(headerCheckboxState(3, 3)).toBe(true);
  });

  it("is indeterminate for part of a page", () => {
    expect(headerCheckboxState(3, 1)).toBe("indeterminate");
  });

  it("is clear when nothing is selected", () => {
    expect(headerCheckboxState(3, 0)).toBe(false);
  });

  it("does not call an empty page fully selected", () => {
    expect(headerCheckboxState(0, 0)).toBe(false);
  });
});
