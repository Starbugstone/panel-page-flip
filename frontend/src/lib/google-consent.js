import { loadConsentPlatform } from "@/lib/adsense-loader";
import { logger } from "@/lib/logger";

const GRANTED = 1;
const NOT_APPLICABLE = 3;
const TCF_API_VERSION = 2;

export const PRIVACY_CHOICES_OPENING_EVENT = "panel-page-flip:privacy-choices-opening";

export function analyticsConsentDecision(status) {
  const analytics = status?.analyticsStoragePurposeConsentStatus;

  return analytics === GRANTED || analytics === NOT_APPLICABLE ? "granted" : "denied";
}

function ensureGoogleFc(win) {
  win.googlefc = win.googlefc || {};
  win.googlefc.callbackQueue = win.googlefc.callbackQueue || [];

  return win.googlefc;
}

/**
 * Observe Google's certified CMP without persisting a second consent answer.
 * Unknown, denied and account-side misconfiguration all fail closed.
 */
export function observeAnalyticsConsent(
  client,
  {
    win = typeof window === "undefined" ? null : window,
    doc = typeof document === "undefined" ? null : document,
    onChange = () => {},
    loadPlatform = true,
  } = {}
) {
  if (!client || !win) {
    onChange("denied");
    return () => {};
  }

  const googlefc = ensureGoogleFc(win);
  let stopped = false;
  let listenerId = null;
  let lastDecision = null;

  const publish = () => {
    if (stopped) return;
    let decision = "denied";
    try {
      decision = analyticsConsentDecision(googlefc.getGoogleConsentModeValues?.());
    } catch (error) {
      logger.warn("The consent platform did not provide a usable Analytics decision:", error.message);
    }
    if (decision !== lastDecision) {
      lastDecision = decision;
      onChange(decision);
    }
  };

  googlefc.callbackQueue.push({ CONSENT_MODE_DATA_READY: publish });
  googlefc.callbackQueue.push({
    CONSENT_API_READY: () => {
      if (typeof win.__tcfapi !== "function") return;
      win.__tcfapi("addEventListener", TCF_API_VERSION, (data, success) => {
        if (success && data?.listenerId !== undefined) listenerId = data.listenerId;
        // Once consent-mode data is ready this executes synchronously. Before
        // then it remains queued, so the TCF event can never race the values.
        googlefc.callbackQueue.push({ CONSENT_MODE_DATA_READY: publish });
      });
    },
  });

  if (loadPlatform) {
    loadConsentPlatform(client, { doc }).then((status) => {
      if (!stopped && status !== "ready") onChange("denied");
    });
  }

  return () => {
    stopped = true;
    if (listenerId !== null && typeof win.__tcfapi === "function") {
      win.__tcfapi("removeEventListener", TCF_API_VERSION, () => {}, listenerId);
    }
  };
}
