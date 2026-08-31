import { act } from "@testing-library/react";

/**
 * Stands in for the browser deciding what is on screen, which jsdom never does:
 * it lays nothing out, so every element is nought by nought and nothing would
 * ever intersect on its own.
 *
 * Tests drive it through {@link scrollTo}, which is the honest shape of the
 * question anyway — what a reader can see is an input to the code under test,
 * not an incidental detail of the environment.
 */
export class FakeIntersectionObserver {
  constructor(callback) {
    this.callback = callback;
    this.nodes = [];
    FakeIntersectionObserver.instances.push(this);
  }

  observe(node) {
    this.nodes.push(node);
  }

  unobserve(node) {
    this.nodes = this.nodes.filter((observed) => observed !== node);
  }

  disconnect() {
    this.nodes = [];
  }

  takeRecords() {
    return [];
  }

  static reset() {
    FakeIntersectionObserver.instances = [];
  }
}
FakeIntersectionObserver.reset();

/**
 * Report every observed node matching `selector` as on screen, or off it.
 *
 * Async because the code under test may only act on the news a microtask
 * later — waiting for a request slot, say.
 */
export async function scrollTo(selector, isIntersecting = true) {
  await act(async () => {
    FakeIntersectionObserver.instances.forEach((observer) => {
      const entries = observer.nodes
        .filter((node) => node.matches(selector))
        .map((node) => ({ target: node, isIntersecting }));
      if (entries.length > 0) observer.callback(entries);
    });
  });
}
