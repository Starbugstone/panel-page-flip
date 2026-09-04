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
 * Run what was queued, the way Funding Choices would.
 *
 * The queue is checked by what it does rather than by which reference is in it:
 * the entry is a call *through* `googlefc`, so that Google's own method is not
 * invoked detached from the object it belongs to.
 */
const drain = (callbackQueue, index = callbackQueue.length - 1) => callbackQueue[index]();

/**
 * Consent lives entirely in Google's certified platform. What matters here is
 * that this application can hand somebody back to it from wherever they are,
 * and that a page with no platform loaded degrades to nothing rather than to an
 * exception.
 */
describe("reopening the privacy choices", () => {
  it("queues the supported consent-platform revocation function", async () => {
    const showRevocationMessage = vi.fn();
    const callbackQueue = [];
    const win = { googlefc: { callbackQueue, showRevocationMessage } };

    await expect(reopenPrivacyChoices({ client: CLIENT, win })).resolves.toBe(true);
    expect(showRevocationMessage).not.toHaveBeenCalled();
    expect(callbackQueue).toHaveLength(1);

    drain(callbackQueue);
    expect(showRevocationMessage).toHaveBeenCalledTimes(1);
    expect(showRevocationMessage.mock.contexts[0]).toBe(win.googlefc);
    // Already there — no reason to fetch it again.
    expect(acquireConsentPlatform).toHaveBeenCalledWith(CLIENT, expect.anything());
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
      win.googlefc = { callbackQueue: [], showRevocationMessage };

      return Promise.resolve("ready");
    });

    await expect(reopenPrivacyChoices({ client: CLIENT, win })).resolves.toBe(true);
    expect(acquireConsentPlatform).toHaveBeenCalledWith(CLIENT, expect.anything());

    drain(win.googlefc.callbackQueue);
    expect(showRevocationMessage).toHaveBeenCalledTimes(1);
  });

  /** Clicked before the platform finished initialising. */
  it("queues the request when the consent API is not ready yet", async () => {
    const win = { googlefc: { callbackQueue: [] } };

    await expect(reopenPrivacyChoices({ client: CLIENT, win })).resolves.toBe(true);
    expect(win.googlefc.callbackQueue).toHaveLength(1);

    const showRevocationMessage = vi.fn();
    win.googlefc.showRevocationMessage = showRevocationMessage;
    win.googlefc.callbackQueue[0].CONSENT_API_READY();

    expect(win.googlefc.callbackQueue).toHaveLength(2);
    drain(win.googlefc.callbackQueue);
    expect(showRevocationMessage).toHaveBeenCalledTimes(1);
  });

  it("creates the queue when the platform has not made one", async () => {
    const win = { googlefc: {} };

    await expect(reopenPrivacyChoices({ client: CLIENT, win })).resolves.toBe(true);
    expect(win.googlefc.callbackQueue).toHaveLength(1);
  });

  it("does nothing where the platform could not be fetched", async () => {
    await expect(reopenPrivacyChoices({ client: CLIENT, win: {} })).resolves.toBe(false);
    await expect(reopenPrivacyChoices({ client: CLIENT, win: null })).resolves.toBe(false);
  });

  it("survives a consent platform that throws", async () => {
    const showRevocationMessage = () => {
      throw new Error("iframe removed");
    };
    const win = {
      googlefc: {
        callbackQueue: { push: (callback) => callback() },
        showRevocationMessage,
      },
    };

    await expect(reopenPrivacyChoices({ client: CLIENT, win })).resolves.toBe(false);
  });
});
