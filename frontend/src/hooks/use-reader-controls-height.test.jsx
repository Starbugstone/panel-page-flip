import { act, render, screen } from "@testing-library/react";
import { useRef } from "react";
import { describe, expect, it } from "vitest";

import { useReaderControlsHeight } from "./use-reader-controls-height";

/**
 * jsdom lays nothing out, so the bar is given the height it would have in a
 * browser. That is the whole point of the hook: the number has to come from the
 * element rather than from a constant somebody kept in step by hand.
 */
function Harness({ heights }) {
  const index = useRef(0);
  const { height, controlsRef } = useReaderControlsHeight();

  return (
    <div
      ref={(node) => {
        if (node) {
          node.getBoundingClientRect = () => {
            const value = heights[Math.min(index.current, heights.length - 1)];
            return { height: value, width: 0, top: 0, right: 0, bottom: value, left: 0, x: 0, y: 0 };
          };
        }
        controlsRef(node);
      }}
    >
      <span data-testid="measured">{height === null ? "none" : height}</span>
      <button type="button" onClick={() => { index.current += 1; }}>grow</button>
    </div>
  );
}

const measured = () => screen.getByTestId("measured").textContent;

describe("useReaderControlsHeight", () => {
  it("reports the height the bar actually has", () => {
    render(<Harness heights={[104]} />);

    expect(measured()).toBe("104");
  });

  it("rounds a fractional height up, so no artwork is left under the bar", () => {
    render(<Harness heights={[92.25]} />);

    expect(measured()).toBe("93");
  });

  it("reports nothing until the bar has been laid out", () => {
    render(<Harness heights={[0]} />);

    expect(measured()).toBe("none");
  });

  it("measures again when the window changes size", () => {
    render(<Harness heights={[88, 132]} />);
    expect(measured()).toBe("88");

    act(() => { screen.getByRole("button", { name: "grow" }).click(); });
    act(() => { window.dispatchEvent(new Event("resize")); });

    expect(measured()).toBe("132");
  });
});
