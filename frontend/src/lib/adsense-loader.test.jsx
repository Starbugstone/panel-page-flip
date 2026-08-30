import { afterEach, describe, expect, it, vi } from "vitest";

import {
  ADSENSE_SCRIPT_ID,
  CMP_SCRIPT_ID,
  keepRouteAdFree,
  loadAdSenseScript,
  loadConsentPlatform,
  removeInjectedAds,
  resetAdSenseScriptForTesting,
} from "@/lib/adsense-loader";

vi.mock("@/lib/logger", () => ({ logger: { warn: vi.fn(), log: vi.fn() } }));

const CLIENT = "ca-pub-1234567890123456";

const injectedScript = () => document.getElementById(ADSENSE_SCRIPT_ID);

afterEach(() => {
  resetAdSenseScriptForTesting();
  document.body.innerHTML = "";
});

describe("loading Google's site code", () => {
  it("adds the publisher's script once, however many times it is asked for", async () => {
    const first = loadAdSenseScript(CLIENT);
    const second = loadAdSenseScript(CLIENT);

    injectedScript().dispatchEvent(new Event("load"));

    await expect(first).resolves.toBe("ready");
    await expect(second).resolves.toBe("ready");
    expect(document.querySelectorAll(`#${ADSENSE_SCRIPT_ID}`)).toHaveLength(1);
    expect(injectedScript().src).toContain(`client=${CLIENT}`);
    expect(injectedScript().async).toBe(true);
  });

  it("remembers the outcome for callers that arrive after it settled", async () => {
    const loading = loadAdSenseScript(CLIENT);
    injectedScript().dispatchEvent(new Event("load"));
    await loading;

    await expect(loadAdSenseScript(CLIENT)).resolves.toBe("ready");
  });

  /** An ad blocker is the ordinary case, not an error to handle. */
  it("reports a blocked script as unavailable rather than throwing", async () => {
    const loading = loadAdSenseScript(CLIENT);

    injectedScript().dispatchEvent(new Event("error"));

    await expect(loading).resolves.toBe("unavailable");
    await expect(loadAdSenseScript(CLIENT)).resolves.toBe("unavailable");
  });

  /**
   * Some blockers neither serve the script nor fail the request. Without the
   * timeout the bulk-upload gate would spin for ever waiting to know whether it
   * can render Auto Ads or the account-side Offerwall.
   */
  it("gives up on a script that never answers", async () => {
    vi.useFakeTimers();
    try {
      const loading = loadAdSenseScript(CLIENT, { timeoutMs: 5000 });
      await vi.advanceTimersByTimeAsync(5000);

      await expect(loading).resolves.toBe("unavailable");
    } finally {
      vi.useRealTimers();
    }
  });

  /**
   * The timeout path has to memoise like the others.
   *
   * A blocker that swallows the request silently is exactly what the timeout is
   * for, and it is the one path where neither handler runs. Without recording
   * the outcome on the node, every later caller re-entered the slow path:
   * another pair of listeners on a script that will never fire them, another
   * five-second wait, and a growing pile of both for the whole session.
   */
  it("remembers that it gave up, instead of waiting again for each caller", async () => {
    vi.useFakeTimers();
    try {
      const first = loadAdSenseScript(CLIENT, { timeoutMs: 5000 });
      await vi.advanceTimersByTimeAsync(5000);
      await expect(first).resolves.toBe("unavailable");

      // Resolves without the clock having to move at all.
      await expect(loadAdSenseScript(CLIENT, { timeoutMs: 5000 })).resolves.toBe("unavailable");
    } finally {
      vi.useRealTimers();
    }
  });

  /**
   * A script that arrives at 5.5 seconds must not contradict the answer already
   * given. The gate caches the first outcome and never asks again, so a late
   * "ready" would leave the context reporting "unavailable" for the rest of the
   * session while Google's code was demonstrably resident and placing ads.
   */
  it("does not change its answer once it has given one", async () => {
    vi.useFakeTimers();
    try {
      const loading = loadAdSenseScript(CLIENT, { timeoutMs: 5000 });
      await vi.advanceTimersByTimeAsync(5000);
      await expect(loading).resolves.toBe("unavailable");

      injectedScript().dispatchEvent(new Event("load"));

      await expect(loadAdSenseScript(CLIENT, { timeoutMs: 5000 })).resolves.toBe("unavailable");
    } finally {
      vi.useRealTimers();
    }
  });

  it("asks for nothing without a publisher id", async () => {
    await expect(loadAdSenseScript(null)).resolves.toBe("unavailable");
    await expect(loadAdSenseScript("")).resolves.toBe("unavailable");
    expect(injectedScript()).toBeNull();
  });
});

/**
 * Funding Choices on its own is the consent half of AdSense without the
 * advertising half, which is what makes it safe to fetch on a page rendering
 * somebody's comic — and fetching it there is the only way the footer's
 * withdrawal control can work off the four ad-safe routes.
 */
describe("loading the consent platform by itself", () => {
  it("asks for the publisher's consent script, not the advertising one", async () => {
    const loading = loadConsentPlatform(CLIENT);

    const script = document.getElementById(CMP_SCRIPT_ID);
    expect(script.src).toContain("fundingchoicesmessages.google.com");
    expect(script.src).toContain("pub-1234567890123456");
    expect(document.getElementById(ADSENSE_SCRIPT_ID)).toBeNull();

    script.dispatchEvent(new Event("load"));
    await expect(loading).resolves.toBe("ready");
  });

  it("degrades to unavailable when it is blocked", async () => {
    const loading = loadConsentPlatform(CLIENT);
    document.getElementById(CMP_SCRIPT_ID).dispatchEvent(new Event("error"));

    await expect(loading).resolves.toBe("unavailable");
  });

  it("asks for nothing without a publisher id", async () => {
    await expect(loadConsentPlatform(null)).resolves.toBe("unavailable");
    expect(document.getElementById(CMP_SCRIPT_ID)).toBeNull();
  });
});

/**
 * The site code cannot be unloaded, so once it has run on the landing page it is
 * still resident when the reader opens a comic. Sweeping is what keeps the
 * boundary real in a single-page application.
 */
describe("taking placed advertising back off the page", () => {
  it("removes every shape Auto Ads leave behind", () => {
    document.body.innerHTML = `
      <ins class="adsbygoogle"></ins>
      <div data-google-query-id="abc"></div>
      <div data-anchor-status="displayed"></div>
      <div data-vignette-loaded="true"></div>
      <iframe src="https://tpc.googlesyndication.com/frame"></iframe>
      <iframe src="https://googleads.g.doubleclick.net/frame"></iframe>
      <article id="keep">A comic page</article>
    `;

    expect(removeInjectedAds()).toBe(6);
    expect(document.querySelectorAll("ins.adsbygoogle")).toHaveLength(0);
    expect(document.querySelectorAll("iframe")).toHaveLength(0);
    expect(document.getElementById("keep")).not.toBeNull();
  });

  it("leaves an ordinary page alone", () => {
    document.body.innerHTML = `<img src="/api/comics/1/pages/1" alt="Page 1" />`;

    expect(removeInjectedAds()).toBe(0);
    expect(document.querySelectorAll("img")).toHaveLength(1);
  });
});

/**
 * The guarantee the whole feature rests on.
 *
 * Auto Ads insert on their own schedule, typically well after a navigation
 * commits, so a single sweep at commit time finds an empty page and the
 * advertisement that arrives afterwards stays beside the artwork for the rest
 * of the visit.
 */
describe("keeping a route ad-free while the user is on it", () => {
  it("removes advertising that arrives after the navigation settled", async () => {
    document.body.innerHTML = `<article id="page">A comic page</article>`;

    const stop = keepRouteAdFree();
    try {
      const late = document.createElement("ins");
      late.className = "adsbygoogle";
      document.body.appendChild(late);

      await vi.waitFor(() => expect(document.querySelectorAll("ins.adsbygoogle")).toHaveLength(0));
      expect(document.getElementById("page")).not.toBeNull();
    } finally {
      stop();
    }
  });

  it("sweeps what is already there, without waiting for a mutation", () => {
    document.body.innerHTML = `<ins class="adsbygoogle"></ins>`;

    const stop = keepRouteAdFree();
    try {
      expect(document.querySelectorAll("ins.adsbygoogle")).toHaveLength(0);
    } finally {
      stop();
    }
  });

  /**
   * Auto Ads do not only insert: they also take over an element already on the
   * page by stamping their own marker onto it, which an insertion-only watcher
   * never sees.
   */
  it("removes an element Google takes over in place", async () => {
    document.body.innerHTML = `<div id="slot"></div>`;

    const stop = keepRouteAdFree();
    try {
      document.getElementById("slot").setAttribute("data-anchor-status", "displayed");

      await vi.waitFor(() => expect(document.getElementById("slot")).toBeNull());
    } finally {
      stop();
    }
  });

  /**
   * An iframe is matched by its URL, so one inserted blank matches nothing at
   * the moment it arrives. If the `src` that turns it into an advertisement is
   * not watched, the sweep has already had its only look at it.
   */
  it("removes an iframe pointed at Google after it was inserted", async () => {
    const stop = keepRouteAdFree();
    try {
      const frame = document.createElement("iframe");
      frame.id = "late-frame";
      document.body.appendChild(frame);
      await new Promise((resolve) => setTimeout(resolve, 10));
      expect(document.getElementById("late-frame")).not.toBeNull();

      frame.setAttribute("src", "https://googleads.g.doubleclick.net/pagead/ads");

      await vi.waitFor(() => expect(document.getElementById("late-frame")).toBeNull());
    } finally {
      stop();
    }
  });

  it("stops watching when the route changes", async () => {
    const stop = keepRouteAdFree();
    stop();

    const late = document.createElement("ins");
    late.className = "adsbygoogle";
    document.body.appendChild(late);

    await new Promise((resolve) => setTimeout(resolve, 10));
    expect(document.querySelectorAll("ins.adsbygoogle")).toHaveLength(1);
  });
});

/**
 * The reader mutates constantly as pages turn, and this observer is live for
 * the whole reading session. An attribute change cannot put a node underneath
 * the element it changed — anything that did arrives as an insertion — so
 * searching the subtree there is work paid on every page swap to find something
 * that cannot be there.
 */
describe("what an attribute mutation costs on the reader's hot path", () => {
  it("still catches an iframe pointed at Google after it was inserted blank", async () => {
    document.body.innerHTML = `<iframe id="frame"></iframe>`;

    const stop = keepRouteAdFree();
    try {
      document.getElementById("frame").setAttribute("src", "https://googlesyndication.com/pagead/ads");

      await vi.waitFor(() => expect(document.getElementById("frame")).toBeNull());
    } finally {
      stop();
    }
  });

  it("does not search the subtree of an element whose attribute changed", async () => {
    document.body.innerHTML = `<img id="page" alt="" />`;

    const stop = keepRouteAdFree();
    try {
      const page = document.getElementById("page");
      const querySelector = vi.spyOn(page, "querySelector");

      page.setAttribute("src", "/api/comics/1/pages/2");
      await new Promise((resolve) => setTimeout(resolve, 10));

      expect(querySelector).not.toHaveBeenCalled();
      expect(document.getElementById("page")).not.toBeNull();
    } finally {
      stop();
    }
  });
});
