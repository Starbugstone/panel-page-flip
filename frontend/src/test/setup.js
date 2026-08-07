import "@testing-library/jest-dom/vitest";
import { cleanup } from "@testing-library/react";
import { afterEach } from "vitest";

/**
 * What jsdom has to be told about before Radix will render.
 *
 * These are not conveniences. Radix's dialog, checkbox and popover primitives
 * call each of them during a normal mount, and jsdom implements none of them —
 * so without these stubs the components under test throw before a single
 * assertion runs, and the failure looks like a bug in the component rather than
 * a gap in the environment.
 */
if (!window.matchMedia) {
  window.matchMedia = (query) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => {},
    removeListener: () => {},
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => false,
  });
}

for (const name of ["ResizeObserver", "IntersectionObserver"]) {
  if (!globalThis[name]) {
    globalThis[name] = class {
      observe() {}
      unobserve() {}
      disconnect() {}
      takeRecords() {
        return [];
      }
    };
  }
}

// Pointer capture is how Radix tracks a press that leaves the element it began
// on. jsdom has no pointer model at all, so these answer "nothing is captured".
const elementStubs = {
  hasPointerCapture: () => false,
  setPointerCapture: () => {},
  releasePointerCapture: () => {},
  scrollIntoView: () => {},
};

for (const [name, stub] of Object.entries(elementStubs)) {
  if (!Element.prototype[name]) {
    Element.prototype[name] = stub;
  }
}

// Auto-cleanup only registers itself when Vitest's globals are on, and they are
// deliberately off here — so unmounting between tests is ours to do. Without it
// a second render finds the previous dialog still in the document and every
// query becomes ambiguous.
afterEach(() => {
  cleanup();
});
