import { describe, expect, it, vi } from "vitest";

import { reopenPrivacyChoices } from "@/lib/privacy-choices";

vi.mock("@/lib/logger", () => ({ logger: { warn: vi.fn(), log: vi.fn() } }));

/**
 * Consent lives entirely in Google's certified platform. What matters here is
 * that this application can hand somebody back to it, and that a page with no
 * platform loaded degrades to nothing rather than to an exception.
 */
describe("reopening the privacy choices", () => {
  it("asks the consent platform to show its message again", () => {
    const showRevocationMessage = vi.fn();

    expect(reopenPrivacyChoices({ googlefc: { showRevocationMessage } })).toBe(true);
    expect(showRevocationMessage).toHaveBeenCalledOnce();
  });

  /** Clicked before the site code finished loading. */
  it("queues the request when the consent API is not ready yet", () => {
    const win = { googlefc: { callbackQueue: [] } };

    expect(reopenPrivacyChoices(win)).toBe(true);
    expect(win.googlefc.callbackQueue).toHaveLength(1);

    const showRevocationMessage = vi.fn();
    win.googlefc.showRevocationMessage = showRevocationMessage;
    win.googlefc.callbackQueue[0].CONSENT_API_READY();

    expect(showRevocationMessage).toHaveBeenCalledOnce();
  });

  it("creates the queue when the platform has not made one", () => {
    const win = { googlefc: {} };

    expect(reopenPrivacyChoices(win)).toBe(true);
    expect(win.googlefc.callbackQueue).toHaveLength(1);
  });

  it("does nothing where advertising is off or the script was blocked", () => {
    expect(reopenPrivacyChoices({})).toBe(false);
    expect(reopenPrivacyChoices(null)).toBe(false);
  });

  it("survives a consent platform that throws", () => {
    const win = {
      googlefc: {
        showRevocationMessage: () => {
          throw new Error("iframe removed");
        },
      },
    };

    expect(reopenPrivacyChoices(win)).toBe(false);
  });
});
