import { beforeEach, describe, expect, it, vi } from "vitest";

import { reopenPrivacyChoices } from "@/lib/privacy-choices";
import { loadConsentPlatform } from "@/lib/adsense-loader";

vi.mock("@/lib/logger", () => ({ logger: { warn: vi.fn(), log: vi.fn() } }));
vi.mock("@/lib/adsense-loader", () => ({ loadConsentPlatform: vi.fn(() => Promise.resolve("unavailable")) }));

const CLIENT = "ca-pub-1234567890123456";

beforeEach(() => vi.clearAllMocks());

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

    await expect(reopenPrivacyChoices({
      client: CLIENT,
      win: { googlefc: { callbackQueue, showRevocationMessage } },
    }))
      .resolves.toBe(true);
    expect(showRevocationMessage).not.toHaveBeenCalled();
    expect(callbackQueue).toContain(showRevocationMessage);
    // Already there — no reason to fetch it again.
    expect(loadConsentPlatform).not.toHaveBeenCalled();
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
    vi.mocked(loadConsentPlatform).mockImplementation(() => {
      win.googlefc = { callbackQueue: [], showRevocationMessage };

      return Promise.resolve("ready");
    });

    await expect(reopenPrivacyChoices({ client: CLIENT, win })).resolves.toBe(true);
    expect(loadConsentPlatform).toHaveBeenCalledWith(CLIENT, expect.anything());
    expect(win.googlefc.callbackQueue).toContain(showRevocationMessage);
  });

  /** Clicked before the platform finished initialising. */
  it("queues the request when the consent API is not ready yet", async () => {
    const win = { googlefc: { callbackQueue: [] } };

    await expect(reopenPrivacyChoices({ client: CLIENT, win })).resolves.toBe(true);
    expect(win.googlefc.callbackQueue).toHaveLength(1);

    const showRevocationMessage = vi.fn();
    win.googlefc.showRevocationMessage = showRevocationMessage;
    win.googlefc.callbackQueue[0].CONSENT_API_READY();

    expect(win.googlefc.callbackQueue).toContain(showRevocationMessage);
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
