import { afterEach, describe, expect, it, vi } from "vitest";

import {
  ADSENSE_SCRIPT_ID,
  loadAdSenseScript,
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
   * can offer a rewarded advertisement.
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

  it("asks for nothing without a publisher id", async () => {
    await expect(loadAdSenseScript(null)).resolves.toBe("unavailable");
    await expect(loadAdSenseScript("")).resolves.toBe("unavailable");
    expect(injectedScript()).toBeNull();
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
