import { act, renderHook } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { useRowSelection } from "./use-row-selection";

const rows = [{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }, { id: 5 }];
const ids = (result) => result.current.selectedRows.map((row) => row.id);

const setup = (options = {}) => renderHook(
  (props) => useRowSelection({ rows, ...options, ...props }),
  { initialProps: {} }
);

describe("useRowSelection", () => {
  it("starts with nothing picked", () => {
    const { result } = setup();

    expect(ids(result)).toEqual([]);
    expect(result.current.headerState).toBe(false);
  });

  it("ticks and unticks one row at a time", () => {
    const { result } = setup();

    act(() => result.current.toggle(2, true));
    expect(ids(result)).toEqual([2]);
    expect(result.current.headerState).toBe("indeterminate");

    act(() => result.current.toggle(2, false));
    expect(ids(result)).toEqual([]);
  });

  it("takes the whole page and lets it go again", () => {
    const { result } = setup();

    act(() => result.current.toggleAll(true));
    expect(ids(result)).toEqual([1, 2, 3, 4, 5]);
    expect(result.current.headerState).toBe(true);

    act(() => result.current.toggleAll(false));
    expect(ids(result)).toEqual([]);
  });

  it("shift-clicking covers everything between the last plain click and this one", () => {
    const { result } = setup();

    act(() => result.current.toggle(2, true));
    act(() => result.current.toggle(4, true, { extendFromAnchor: true }));

    expect(ids(result)).toEqual([2, 3, 4]);
  });

  it("reads a range backwards", () => {
    const { result } = setup();

    act(() => result.current.toggle(4, true));
    act(() => result.current.toggle(2, true, { extendFromAnchor: true }));

    expect(ids(result)).toEqual([2, 3, 4]);
  });

  /**
   * The range takes the anchor's state, not the clicked box's own toggle.
   * Shift-clicking back inside a range you just selected shortens it; taking
   * the clicked box's value would invert the half you clicked through.
   */
  it("shift-clicking from an unticking anchor clears the range instead", () => {
    const { result } = setup();

    act(() => result.current.toggleAll(true));
    act(() => result.current.toggle(2, false));
    act(() => result.current.toggle(4, false, { extendFromAnchor: true }));

    expect(ids(result)).toEqual([1, 5]);
  });

  /** Successive shift-clicks resize one range rather than walking it along. */
  it("keeps the anchor where the plain click put it", () => {
    const { result } = setup();

    act(() => result.current.toggle(1, true));
    act(() => result.current.toggle(4, true, { extendFromAnchor: true }));
    act(() => result.current.toggle(2, true, { extendFromAnchor: true }));

    expect(ids(result)).toEqual([1, 2, 3, 4]);
  });

  it("has no range to extend before a plain click has set an anchor", () => {
    const { result } = setup();

    act(() => result.current.toggle(3, true, { extendFromAnchor: true }));

    expect(ids(result)).toEqual([3]);
  });

  it("forgets the selection when the rows it was made against are replaced", () => {
    const { result, rerender } = renderHook(
      ({ resetKey }) => useRowSelection({ rows, resetKey }),
      { initialProps: { resetKey: "page-1" } }
    );

    act(() => result.current.toggle(2, true));
    expect(ids(result)).toEqual([2]);

    rerender({ resetKey: "page-2" });

    expect(ids(result)).toEqual([]);
    expect(result.current.headerState).toBe(false);
  });

  it("clears on demand", () => {
    const { result } = setup();

    act(() => result.current.toggleAll(true));
    act(() => result.current.clear());

    expect(ids(result)).toEqual([]);
  });

  it("ignores a row that has left the page since it was ticked", () => {
    const { result, rerender } = renderHook(
      ({ rows: current }) => useRowSelection({ rows: current, resetKey: "same" }),
      { initialProps: { rows } }
    );

    act(() => result.current.toggle(5, true));
    rerender({ rows: rows.slice(0, 3) });

    expect(ids(result)).toEqual([]);
  });
});
