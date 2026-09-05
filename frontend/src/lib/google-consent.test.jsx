import { describe, expect, it, vi } from "vitest";

import {
  analyticsConsentDecision,
  observeAnalyticsConsent,
  PRIVACY_CHOICES_OPENING_EVENT,
} from "@/lib/google-consent";
import { acquireConsentPlatform } from "@/lib/adsense-loader";

vi.mock("@/lib/adsense-loader", () => ({
  acquireConsentPlatform: vi.fn(() => Promise.resolve("ready")),
}));

// After readiness, Google runs newly queued callbacks synchronously, even when
// its consent values still describe the choice made before reopening the UI.
function liveConsentPlatform() {
  const win = new EventTarget();
  let consentReady = false;
  let analyticsStatus = 1;
  let listener;
  const pending = [];
  win.__tcfapi = (command, _version, callback) => {
    if (command === "addEventListener") listener = callback;
  };
  win.googlefc = {
    getGoogleConsentModeValues: () => ({ analyticsStoragePurposeConsentStatus: analyticsStatus }),
    callbackQueue: {
      push(entry) {
        if (entry.CONSENT_API_READY) entry.CONSENT_API_READY();
        if (entry.CONSENT_MODE_DATA_READY) {
          if (consentReady) entry.CONSENT_MODE_DATA_READY();
          else pending.push(entry.CONSENT_MODE_DATA_READY);
        }
      },
    },
  };

  return {
    win,
    ready() {
      consentReady = true;
      pending.splice(0).forEach((callback) => callback());
    },
    change(eventStatus, status = analyticsStatus, success = true) {
      analyticsStatus = status;
      listener({ listenerId: 1, eventStatus }, success);
    },
  };
}

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

  it("does not register a listener after observation has stopped", () => {
    const win = { googlefc: { callbackQueue: [] }, __tcfapi: vi.fn() };
    const stop = observeAnalyticsConsent("ca-pub-1234567890123456", { win, doc: document });
    stop();
    win.googlefc.callbackQueue.find((entry) => entry.CONSENT_API_READY).CONSENT_API_READY();
    expect(win.__tcfapi).not.toHaveBeenCalled();
  });

  it("removes a listener whose registration completes after cleanup", () => {
    let registered;
    const win = {
      googlefc: { callbackQueue: [] },
      __tcfapi: vi.fn((command, _version, callback) => {
        if (command === "addEventListener") registered = callback;
      }),
    };
    const stop = observeAnalyticsConsent("ca-pub-1234567890123456", { win, doc: document });
    win.googlefc.callbackQueue.find((entry) => entry.CONSENT_API_READY).CONSENT_API_READY();
    const queued = win.googlefc.callbackQueue.length;
    stop();
    registered({ listenerId: 42 }, true);
    expect(win.__tcfapi).toHaveBeenCalledWith("removeEventListener", 2, expect.any(Function), 42);
    expect(win.googlefc.callbackQueue).toHaveLength(queued);
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
    tcfListener({ listenerId: 0, eventStatus: "tcloaded" }, true);
    win.googlefc.callbackQueue.at(-1).CONSENT_MODE_DATA_READY();
    expect(onChange).toHaveBeenLastCalledWith("granted");

    stop();
    expect(win.__tcfapi).toHaveBeenCalledWith("removeEventListener", 2, expect.any(Function), 0);
  });

  it.each([1, 2])("suspends analytics while Google's UI is open, then applies purpose status %s", (status) => {
    const platform = liveConsentPlatform();
    const onChange = vi.fn();
    const stop = observeAnalyticsConsent("ca-pub-1234567890123456", { win: platform.win, onChange });
    platform.ready();
    expect(onChange).toHaveBeenLastCalledWith("granted");

    platform.change("cmpuishown");
    expect(onChange).toHaveBeenLastCalledWith("denied");

    platform.change("useractioncomplete", status);
    expect(onChange).toHaveBeenLastCalledWith(status === 1 ? "granted" : "denied");
    stop();
  });

  it("ignores stale stored-consent and delayed readiness callbacks after Privacy choices is clicked", () => {
    const platform = liveConsentPlatform();
    const onChange = vi.fn();
    const stop = observeAnalyticsConsent("ca-pub-1234567890123456", { win: platform.win, onChange });
    platform.change("tcloaded");

    platform.win.dispatchEvent(new Event(PRIVACY_CHOICES_OPENING_EVENT));
    expect(onChange).toHaveBeenLastCalledWith("denied");
    platform.ready();
    platform.change("tcloaded");
    platform.change("cmpuishown");
    expect(onChange).not.toHaveBeenCalledWith("granted");

    platform.change("useractioncomplete", 1);
    expect(onChange).toHaveBeenLastCalledWith("granted");
    stop();
  });

  it("fails closed on a failed TCF callback even if Google still exposes a previous grant", () => {
    const platform = liveConsentPlatform();
    const onChange = vi.fn();
    const stop = observeAnalyticsConsent("ca-pub-1234567890123456", { win: platform.win, onChange });
    platform.ready();
    expect(onChange).toHaveBeenLastCalledWith("granted");

    platform.change("useractioncomplete", 1, false);
    expect(onChange).toHaveBeenLastCalledWith("denied");
    stop();
  });

  it("does not treat an incomplete TCF registration callback as a renewed decision", () => {
    const platform = liveConsentPlatform();
    const onChange = vi.fn();
    const stop = observeAnalyticsConsent("ca-pub-1234567890123456", { win: platform.win, onChange });
    platform.ready();
    platform.win.dispatchEvent(new Event(PRIVACY_CHOICES_OPENING_EVENT));
    platform.change(undefined);
    expect(onChange).toHaveBeenLastCalledWith("denied");
    stop();
  });
});
