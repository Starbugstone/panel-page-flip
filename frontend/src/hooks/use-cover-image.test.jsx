import { act, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { createRequestSlots } from "@/lib/cover-loading";
import { FakeIntersectionObserver, scrollTo as scrollToSelector } from "@/test/fake-intersection-observer";
import { useCoverImage } from "./use-cover-image";

function Cover({ url, slots, eager = false }) {
  const { observe, src, status, onLoad, onError, retry } = useCoverImage(url, { slots, eager });

  return (
    <div ref={observe} data-cover={url}>
      {src && <img src={src} alt={url} onLoad={onLoad} onError={onError} />}
      <output data-status={url ?? "absent"}>{status}</output>
      <button type="button" onClick={retry}>Retry {url}</button>
    </div>
  );
}

const renderCovers = (urls, { slots, eager = false }) => render(
  <>{urls.map((url) => <Cover key={url} url={url} slots={slots} eager={eager} />)}</>
);

/** Tell the hook watching `url` whether its card is on screen. */
const scrollTo = (url, isIntersecting = true) => scrollToSelector(`[data-cover="${url}"]`, isIntersecting);

/** Every URL currently asked of the server; a settled cover keeps its `src`. */
const requestedUrls = () => [...document.querySelectorAll("img")].map((image) => image.getAttribute("src"));
const flush = () => act(async () => {});
const statusOf = (url) => document.querySelector(`[data-status="${url ?? "absent"}"]`).textContent;
const settle = (url, outcome) => {
  const image = document.querySelector(`img[src^="${url}"]`);
  act(() => { fireEvent[outcome](image); });
};

describe("requesting a cover", () => {
  beforeEach(() => {
    vi.stubGlobal("IntersectionObserver", FakeIntersectionObserver);
  });

  afterEach(() => {
    FakeIntersectionObserver.reset();
    vi.unstubAllGlobals();
  });

  it("does not ask for a cover nobody is looking at, or animate its placeholder", () => {
    renderCovers(["/covers/a.jpg"], { slots: createRequestSlots({ limit: 4 }) });

    expect(requestedUrls()).toEqual([]);
    expect(statusOf("/covers/a.jpg")).toBe("idle");
  });

  it("asks once the cover is scrolled to", async () => {
    renderCovers(["/covers/a.jpg"], { slots: createRequestSlots({ limit: 4 }) });

    await scrollTo("/covers/a.jpg");

    expect(requestedUrls()).toEqual(["/covers/a.jpg"]);
  });

  it("does not make the covers above the fold wait to be scrolled to", async () => {
    renderCovers(["/covers/a.jpg"], { slots: createRequestSlots({ limit: 4 }), eager: true });
    await flush();

    expect(requestedUrls()).toEqual(["/covers/a.jpg"]);
  });

  it("keeps a whole screenful of covers down to a handful of requests", async () => {
    const urls = [...Array(12)].map((_, index) => `/covers/${index}.jpg`);
    renderCovers(urls, { slots: createRequestSlots({ limit: 3 }) });

    for (const url of urls) await scrollTo(url);

    expect(requestedUrls()).toHaveLength(3);
  });

  it("moves on to the next cover as each one arrives", async () => {
    const urls = ["/covers/a.jpg", "/covers/b.jpg", "/covers/c.jpg"];
    renderCovers(urls, { slots: createRequestSlots({ limit: 1 }) });

    for (const url of urls) await scrollTo(url);
    expect(requestedUrls()).toEqual(["/covers/a.jpg"]);

    settle("/covers/a.jpg", "load");
    await flush();

    expect(requestedUrls()).toEqual(["/covers/a.jpg", "/covers/b.jpg"]);
    expect(statusOf("/covers/a.jpg")).toBe("loaded");
  });

  it("gives up its place in the queue when it is scrolled past", async () => {
    const urls = ["/covers/a.jpg", "/covers/b.jpg", "/covers/c.jpg"];
    renderCovers(urls, { slots: createRequestSlots({ limit: 1 }) });

    for (const url of urls) await scrollTo(url);
    await scrollTo("/covers/b.jpg", false);

    settle("/covers/a.jpg", "load");
    await flush();

    // The reader scrolled past b before it was ever requested, so the slot it
    // was waiting for goes to something still on screen.
    expect(requestedUrls()).toEqual(["/covers/a.jpg", "/covers/c.jpg"]);
  });

  it("aborts an in-flight ticket when its card unmounts", async () => {
    const slots = createRequestSlots({ limit: 1 });
    const view = renderCovers(["/covers/a.jpg"], { slots });
    await scrollTo("/covers/a.jpg");
    expect(slots.activeCount).toBe(1);

    view.unmount();

    expect(slots.activeCount).toBe(0);
  });

  it("returns an in-flight ticket when the cover URL is removed", async () => {
    const slots = createRequestSlots({ limit: 1 });
    const view = render(<Cover url="/covers/a.jpg" slots={slots} eager />);
    await flush();
    expect(slots.activeCount).toBe(1);

    view.rerender(<Cover url={null} slots={slots} eager />);
    await flush();

    expect(slots.activeCount).toBe(0);
    expect(requestedUrls()).toEqual([]);
    expect(statusOf(null)).toBe("absent");
  });
});

describe("a cover that comes back broken", () => {
  beforeEach(() => {
    vi.stubGlobal("IntersectionObserver", FakeIntersectionObserver);
    vi.useFakeTimers({ shouldAdvanceTime: true });
  });

  afterEach(() => {
    vi.useRealTimers();
    FakeIntersectionObserver.reset();
    vi.unstubAllGlobals();
  });

  const waitOutTheBackoff = async () => {
    await act(async () => { vi.advanceTimersByTime(10000); });
  };

  it("asks again, at a URL the browser has nothing cached for", async () => {
    renderCovers(["/covers/a.jpg"], { slots: createRequestSlots({ limit: 4 }) });
    await scrollTo("/covers/a.jpg");

    settle("/covers/a.jpg", "error");
    expect(statusOf("/covers/a.jpg")).toBe("retrying");

    await waitOutTheBackoff();

    expect(requestedUrls()).toEqual(["/covers/a.jpg?retry=1"]);
  });

  it("waits until it is back on screen before asking again", async () => {
    renderCovers(["/covers/a.jpg"], { slots: createRequestSlots({ limit: 4 }) });
    await scrollTo("/covers/a.jpg");

    settle("/covers/a.jpg", "error");
    await scrollTo("/covers/a.jpg", false);
    await waitOutTheBackoff();

    expect(requestedUrls()).toEqual([]);

    await scrollTo("/covers/a.jpg");

    expect(requestedUrls()).toEqual(["/covers/a.jpg?retry=1"]);
  });

  it("does not let an old retry reset a replacement cover that has loaded", async () => {
    const slots = createRequestSlots({ limit: 4 });
    const view = render(<Cover url="/covers/old.jpg" slots={slots} />);
    await scrollTo("/covers/old.jpg");

    settle("/covers/old.jpg", "error");
    view.rerender(<Cover url="/covers/new.jpg" slots={slots} />);
    await flush();
    settle("/covers/new.jpg", "load");
    expect(statusOf("/covers/new.jpg")).toBe("loaded");

    await waitOutTheBackoff();

    expect(statusOf("/covers/new.jpg")).toBe("loaded");
    expect(requestedUrls()).toEqual(["/covers/new.jpg"]);
  });

  it("holds its request slot for the whole retry, not the whole backoff", async () => {
    const slots = createRequestSlots({ limit: 1 });
    renderCovers(["/covers/a.jpg", "/covers/b.jpg"], { slots });
    await scrollTo("/covers/a.jpg");
    await scrollTo("/covers/b.jpg");

    settle("/covers/a.jpg", "error");
    await flush();

    // b is served while a is waiting out its backoff: a failure must not park
    // one of the few slots for seconds.
    expect(requestedUrls()).toEqual(["/covers/a.jpg", "/covers/b.jpg"]);
  });

  it("stops asking eventually, and hands the decision back", async () => {
    renderCovers(["/covers/a.jpg"], { slots: createRequestSlots({ limit: 4 }) });
    await scrollTo("/covers/a.jpg");

    for (const attempt of [0, 1, 2, 3]) {
      settle(attempt === 0 ? "/covers/a.jpg" : `/covers/a.jpg?retry=${attempt}`, "error");
      await waitOutTheBackoff();
    }

    expect(statusOf("/covers/a.jpg")).toBe("failed");
    expect(requestedUrls()).toEqual(["/covers/a.jpg?retry=3"]);

    await act(async () => { screen.getByRole("button", { name: "Retry /covers/a.jpg" }).click(); });

    expect(requestedUrls()).toEqual(["/covers/a.jpg?retry=4"]);
  });
});
