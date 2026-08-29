import { act, renderHook, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { usePageImageCache } from "@/hooks/use-page-image-cache";
import { FakeImage } from "@/test/fake-image";

/**
 * The cache mechanism on its own.
 *
 * These were reachable only through the whole reader before the hook existed,
 * which meant the awkward cases — a cancelled load settling late, a forced
 * reload of a page already decoded — were tested through a page turn and a
 * gesture, or not at all.
 */
describe("usePageImageCache", () => {
  const setup = (pageCount = 10) => renderHook(() => usePageImageCache({ comicId: "42", pageCount }));

  beforeEach(() => {
    FakeImage.reset();
    vi.stubGlobal("Image", FakeImage);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("decodes a page once and answers later callers from the cache", async () => {
    const { result } = setup();

    await act(async () => { await result.current.loadPage(0, "reader-medium"); });
    expect(result.current.isPageReady(0, "reader-medium")).toBe(true);

    const requestsAfterFirst = FakeImage.instances.length;
    await act(async () => { await result.current.loadPage(0, "reader-medium"); });

    expect(FakeImage.instances.length).toBe(requestsAfterFirst);
  });

  it("refetches when the page is wanted at a sharper variant than the one held", async () => {
    const { result } = setup();

    await act(async () => { await result.current.loadPage(0, "reader-medium"); });
    expect(result.current.isPageReady(0, "reader-large")).toBe(false);

    await act(async () => { await result.current.loadPage(0, "reader-large"); });
    expect(result.current.isPageReady(0, "reader-large")).toBe(true);
  });

  it("refuses pages outside the comic", async () => {
    const { result } = setup(3);

    await act(async () => {
      await expect(result.current.loadPage(-1, "reader-medium")).resolves.toBeNull();
      await expect(result.current.loadPage(3, "reader-medium")).resolves.toBeNull();
    });
    expect(FakeImage.instances).toHaveLength(0);
  });

  it("records a failed page as failed rather than leaving it pending forever", async () => {
    FakeImage.policy = () => "error";
    const { result } = setup();

    await act(async () => { await result.current.loadPage(0, "reader-medium"); });

    expect(result.current.imageCache[0]).toBe("failed");
    expect(result.current.isPageReady(0, "reader-medium")).toBe(false);
  });

  /**
   * A reader turning pages quickly abandons requests, and the pages they turned
   * to must not wait on them. Cancelling settles the promise — anything
   * awaiting it carries on — and leaves the page unclaimed rather than stuck at
   * `"loading"`, which is the state that would show a spinner for ever.
   */
  it("settles an abandoned load without claiming the page", async () => {
    FakeImage.policy = () => "hold";
    const { result } = setup();

    let abandoned;
    act(() => { abandoned = result.current.loadPage(0, "reader-medium"); });
    act(() => { result.current.cancelLoadsExcept([1]); });

    await act(async () => { await expect(abandoned).resolves.toBeNull(); });
    expect(result.current.isPageReady(0, "reader-medium")).toBe(false);

    // And the page can still be fetched afterwards.
    FakeImage.policy = () => "load";
    await act(async () => { await result.current.loadPage(0, "reader-medium"); });
    expect(result.current.isPageReady(0, "reader-medium")).toBe(true);
  });

  it("keeps the load a caller asked for when an unrelated page is cancelled", async () => {
    FakeImage.policy = () => "hold";
    const { result } = setup();

    let kept;
    act(() => {
      kept = result.current.loadPage(2, "reader-medium");
      result.current.loadPage(6, "reader-medium");
    });
    act(() => { result.current.cancelLoadsExcept([2]); });

    await act(async () => { FakeImage.instances[0].onload?.(); await kept; });
    expect(result.current.isPageReady(2, "reader-medium")).toBe(true);
  });

  it("forces a reload past a decoded page and reports the new image", async () => {
    const { result } = setup();
    await act(async () => { await result.current.loadPage(0, "reader-medium"); });

    let reloaded;
    await act(async () => { reloaded = await result.current.retryPage(0, "reader-medium"); });

    expect(reloaded).not.toBeNull();
    expect(FakeImage.requestedUrls().at(-1)).toContain("_force_reload=");
    expect(result.current.isPageReady(0, "reader-medium")).toBe(true);
  });

  it("queues background pages one at a time, in the order given", async () => {
    FakeImage.policy = () => "hold";
    const { result } = setup();

    act(() => { result.current.queuePages([4, 2, 7], "reader-small"); });

    await waitFor(() => expect(FakeImage.instances).toHaveLength(1));
    expect(FakeImage.instances[0].src).toContain("/pages/5");

    // Only once the first settles does the second go out.
    await act(async () => { FakeImage.instances[0].onload?.(); });
    await waitFor(() => expect(FakeImage.instances).toHaveLength(2));
    expect(FakeImage.instances[1].src).toContain("/pages/3");
  });

  /**
   * Re-queuing happens on every page turn, and the turn that replaces the queue
   * usually lands while the previous background page is still in flight. If
   * that started a second drain, a reader flicking through would accumulate one
   * concurrent download per turn — all of them competing with the page being
   * waited on.
   */
  it("replaces the queue without starting a second drain", async () => {
    FakeImage.policy = () => "hold";
    const { result } = setup();

    act(() => { result.current.queuePages([4, 2], "reader-small"); });
    await waitFor(() => expect(FakeImage.instances).toHaveLength(1));

    act(() => { result.current.queuePages([7, 8], "reader-small"); });

    // Still just the one outstanding request, not one per queuePages call.
    await new Promise((resolve) => setTimeout(resolve, 10));
    expect(FakeImage.instances).toHaveLength(1);
  });

  it("does not queue a page it already holds at that variant", async () => {
    const { result } = setup();
    await act(async () => { await result.current.loadPage(1, "reader-small"); });
    const before = FakeImage.instances.length;

    await act(async () => { result.current.queuePages([1], "reader-small"); });

    expect(FakeImage.instances.length).toBe(before);
  });

  it("evicts pages outside the window and keeps the ones inside it", async () => {
    const { result } = setup();
    await act(async () => {
      await result.current.loadPage(0, "reader-medium");
      await result.current.loadPage(5, "reader-medium");
    });

    act(() => { result.current.evictOutside(4, 6); });

    expect(result.current.imageCache[0]).toBeUndefined();
    expect(result.current.loadedVariants[0]).toBeUndefined();
    expect(result.current.isPageReady(5, "reader-medium")).toBe(true);
  });

  it("forgets everything on reset", async () => {
    const { result } = setup();
    await act(async () => { await result.current.loadPage(0, "reader-medium"); });

    act(() => { result.current.reset(); });

    expect(result.current.imageCache).toEqual({});
    expect(result.current.loadedVariants).toEqual({});
    expect(result.current.isPageReady(0, "reader-medium")).toBe(false);
  });

  it("cancels outstanding loads when the reader goes away", async () => {
    FakeImage.policy = () => "hold";
    const { result, unmount } = setup();

    let pending;
    act(() => { pending = result.current.loadPage(0, "reader-medium"); });
    unmount();

    await expect(pending).resolves.toBeNull();
  });
});
