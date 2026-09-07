import { beforeEach, describe, expect, it, vi } from "vitest";

import { reopenPrivacyChoices } from "@/lib/privacy-choices";
import { acquireConsentPlatform } from "@/lib/adsense-loader";

vi.mock("@/lib/logger", () => ({ logger: { warn: vi.fn(), log: vi.fn() } }));
vi.mock("@/lib/adsense-loader", () => ({
  acquireConsentPlatform: vi.fn(() => Promise.resolve("unavailable")),
}));

const CLIENT = "ca-pub-1234567890123456";

beforeEach(() => vi.clearAllMocks());

/**
 * Consent lives entirely in Google's certified platform. What matters here is
 * that this application can hand somebody back to it from wherever they are,
 * and that a page with no platform loaded degrades to nothing rather than to an
 * exception.
 */
describe("reopening the privacy choices", () => {
  /**
   * The post-init state a click almost always lands in: Funding Choices has
   * loaded, `showRevocationMessage` is a real function, and `callbackQueue`
   * has been drained and removed (`googlefc.callbackQueue === null`).
   *
   * Earlier, the call was pushed onto a freshly-created empty array that the
   * library was no longer watching — the arrow function sat there forever and
   * the consent panel never opened. The fix is to invoke the method directly.
   */
  it("calls the revocation function directly when the consent API is ready", async () => {
    const showRevocationMessage = vi.fn();
    const callbackQueue = [];
    const win = { googlefc: { callbackQueue, showRevocationMessage } };

    await expect(reopenPrivacyChoices({ client: CLIENT, win })).resolves.toBe(true);
    expect(showRevocationMessage).toHaveBeenCalledTimes(1);
    expect(showRevocationMessage.mock.contexts[0]).toBe(win.googlefc);
    // Direct invocation leaves the queue untouched. Pushing onto a queue the
    // library has already drained was the original bug.
    expect(callbackQueue).toHaveLength(0);
    // Already there — no reason to fetch it again.
    expect(acquireConsentPlatform).toHaveBeenCalledWith(CLIENT, expect.anything());
  });

  /**
   * The Funding Choices script removes `callbackQueue` after draining it.
   * Reproducing the production state means starting from `null` and asserting
   * that we still call the method, not recreate the queue and push onto it.
   */
  it("calls the revocation function directly when the platform has removed its queue", async () => {
    const showRevocationMessage = vi.fn();
    const win = { googlefc: { callbackQueue: null, showRevocationMessage } };

    await expect(reopenPrivacyChoices({ client: CLIENT, win })).resolves.toBe(true);
    expect(showRevocationMessage).toHaveBeenCalledTimes(1);
    expect(showRevocationMessage.mock.contexts[0]).toBe(win.googlefc);
    // We must not leave a callbackQueue behind — the previous behaviour did,
    // because `googlefc.callbackQueue = googlefc.callbackQueue || []` creates
    // a fresh array the library has no reference to.
    expect(win.googlefc.callbackQueue).toBeNull();
  });

  /**
   * The reason this is asynchronous at all.
   *
   * The advertising site code loads only on the four ad-safe routes, so on a
   * reader, library or settings page `googlefc` has never existed — and those
   * are the pages somebody is on when they decide to withdraw consent. Without
   * fetching the platform here, the footer control is dead on every page that
   * matters and the withdrawal the policy promises does not exist.
   */
  it("fetches the consent platform on a page that never loaded advertising", async () => {
    const win = {};
    const showRevocationMessage = vi.fn();
    vi.mocked(acquireConsentPlatform).mockImplementation(() => {
      win.googlefc = { callbackQueue: null, showRevocationMessage };

      return Promise.resolve("ready");
    });

    await expect(reopenPrivacyChoices({ client: CLIENT, win })).resolves.toBe(true);
    expect(acquireConsentPlatform).toHaveBeenCalledWith(CLIENT, expect.anything());

    expect(showRevocationMessage).toHaveBeenCalledTimes(1);
  });

  /** Clicked before the platform finished initialising. */
  it("queues the request when the consent API is not ready yet", async () => {
    const win = { googlefc: { callbackQueue: [] } };

    await expect(reopenPrivacyChoices({ client: CLIENT, win })).resolves.toBe(true);
    expect(win.googlefc.callbackQueue).toHaveLength(1);
    expect(typeof win.googlefc.callbackQueue[0].CONSENT_API_READY).toBe("function");

    const showRevocationMessage = vi.fn();
    win.googlefc.showRevocationMessage = showRevocationMessage;
    win.googlefc.callbackQueue[0].CONSENT_API_READY();

    // By the time CONSENT_API_READY fires the method exists, so the retry
    // calls it directly rather than queueing again.
    expect(win.googlefc.callbackQueue).toHaveLength(1);
    expect(showRevocationMessage).toHaveBeenCalledTimes(1);
  });

  /**
   * Clicked during init on a page that never loaded the advertising site
   * code. Funding Choices has not yet exposed `showRevocationMessage` and
   * has not yet drained (or removed) the callbackQueue either — so the queue
   * may genuinely be empty or absent. The fix recreates the queue before
   * pushing the CONSENT_API_READY handler so it has somewhere to live.
   */
  it("recreates the queue when the platform has not made one", async () => {
    const win = { googlefc: {} };

    await expect(reopenPrivacyChoices({ client: CLIENT, win })).resolves.toBe(true);
    expect(Array.isArray(win.googlefc.callbackQueue)).toBe(true);
    expect(win.googlefc.callbackQueue).toHaveLength(1);
  });

  it("does nothing where the platform could not be fetched", async () => {
    await expect(reopenPrivacyChoices({ client: CLIENT, win: {} })).resolves.toBe(false);
    await expect(reopenPrivacyChoices({ client: CLIENT, win: null })).resolves.toBe(false);
  });

  it("survives a consent platform that throws", async () => {
    const showRevocationMessage = vi.fn(() => {
      throw new Error("iframe removed");
    });
    const win = { googlefc: { callbackQueue: null, showRevocationMessage } };

    await expect(reopenPrivacyChoices({ client: CLIENT, win })).resolves.toBe(false);
    expect(showRevocationMessage).toHaveBeenCalledTimes(1);
  });
});
