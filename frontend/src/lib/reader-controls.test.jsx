import { describe, expect, it } from "vitest";

import { isReaderControl } from "./reader-controls";

/**
 * The one definition of "this event belongs to a control, not to the page".
 *
 * Pinned as a list because three listeners share it — the gesture machine, the
 * mouse pan and the surface clicks — and they previously carried three
 * different lists. A control that only some of them recognised is how a tap on
 * the zoom slider turned a page while it was being dragged.
 */
describe("what counts as a reader control", () => {
  const inSurface = (html) => {
    const surface = document.createElement("div");
    surface.innerHTML = html;
    return surface.firstElementChild;
  };

  it.each([
    ["a button", "<button>Settings</button>"],
    ["a link", '<a href="/library">Library</a>'],
    ["a text input", "<input />"],
    ["a select", "<select></select>"],
    ["a textarea", "<textarea></textarea>"],
    ["a label", "<label>Fit</label>"],
    ["anything acting as a button", '<div role="button">Next</div>'],
    ["a switch", '<div role="switch"></div>'],
    ["a slider", '<div role="slider"></div>'],
    ["an open dialog", '<div role="dialog"></div>'],
    ["an editable region", '<div contenteditable="true"></div>'],
  ])("claims %s", (_label, html) => {
    expect(isReaderControl(inSurface(html))).toBe(true);
  });

  it("claims a press that landed on something inside a control", () => {
    expect(isReaderControl(inSurface("<button><span>Settings</span></button>").firstElementChild)).toBe(true);
  });

  it("leaves the artwork and the mat around it to the page", () => {
    expect(isReaderControl(inSurface('<img alt="Page 1" />'))).toBe(false);
    expect(isReaderControl(inSurface("<div></div>"))).toBe(false);
  });

  /** Pointer events in jsdom can arrive with no target at all. */
  it("treats a missing target as not a control", () => {
    expect(isReaderControl(null)).toBe(false);
    expect(isReaderControl(undefined)).toBe(false);
  });
});
