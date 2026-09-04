import { describe, expect, it, vi } from "vitest";

import { analyticsConsentDecision, observeAnalyticsConsent } from "@/lib/google-consent";
import { acquireConsentPlatform } from "@/lib/adsense-loader";

vi.mock("@/lib/adsense-loader", () => ({
  acquireConsentPlatform: vi.fn(() => Promise.resolve("ready")),
}));

describe("the basic-consent decision", () => {
  it.each([1, 3])("allows analytics for Google status %s", (status) => {
    expect(analyticsConsentDecision({ analyticsStoragePurposeConsentStatus: status })).toBe("granted");
  });

  it.each([0, 2, 4, undefined])("fails closed for Google status %s", (status) => {
    expect(analyticsConsentDecision({ analyticsStoragePurposeConsentStatus: status })).toBe("denied");
  });
});

describe("observing Google's certified CMP", () => {
  it("loads the consent platform and reports its analytics-specific choice", async () => {
    const onChange = vi.fn();
    const win = { googlefc: { callbackQueue: [] } };

    observeAnalyticsConsent("ca-pub-1234567890123456", { win, doc: document, onChange });
    expect(acquireConsentPlatform).toHaveBeenCalledWith("ca-pub-1234567890123456", {
      doc: document,
      win,
    });

    win.googlefc.getGoogleConsentModeValues = () => ({ analyticsStoragePurposeConsentStatus: 1 });
    const ready = win.googlefc.callbackQueue.find((entry) => entry.CONSENT_MODE_DATA_READY);
    ready.CONSENT_MODE_DATA_READY();

    expect(onChange).toHaveBeenLastCalledWith("granted");
  });

  it("does not unblock analytics when the CMP is blocked", async () => {
    vi.mocked(acquireConsentPlatform).mockResolvedValueOnce("unavailable");
    const onChange = vi.fn();

    observeAnalyticsConsent("ca-pub-1234567890123456", {
      win: { googlefc: { callbackQueue: [] } },
      doc: document,
      onChange,
    });
    await vi.waitFor(() => expect(onChange).toHaveBeenCalledWith("denied"));
  });

  it("follows later consent changes through the TCF v2 listener", () => {
    const onChange = vi.fn();
    let tcfListener;
    const win = {
      googlefc: { callbackQueue: [] },
      __tcfapi: vi.fn((command, version, callback) => {
        if (command === "addEventListener") tcfListener = callback;
      }),
    };
    const stop = observeAnalyticsConsent("ca-pub-1234567890123456", {
      win,
      doc: document,
      onChange,
    });

    const apiReady = win.googlefc.callbackQueue.find((entry) => entry.CONSENT_API_READY);
    apiReady.CONSENT_API_READY();
    expect(win.__tcfapi).toHaveBeenCalledWith("addEventListener", 2, expect.any(Function));

    win.googlefc.getGoogleConsentModeValues = () => ({ analyticsStoragePurposeConsentStatus: 1 });
    tcfListener({ listenerId: 0 }, true);
    win.googlefc.callbackQueue.at(-1).CONSENT_MODE_DATA_READY();
    expect(onChange).toHaveBeenLastCalledWith("granted");

    stop();
    expect(win.__tcfapi).toHaveBeenCalledWith("removeEventListener", 2, expect.any(Function), 0);
  });
});
