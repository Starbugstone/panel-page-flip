import { acquireConsentPlatform } from "@/lib/adsense-loader";
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
  } = {}
) {
  if (!client || !win) {
    onChange("denied");
    return () => {};
  }

  const platform = acquireConsentPlatform(client, { win, doc });
  const googlefc = ensureGoogleFc(win);
  let stopped = false;
  let listenerId = null;
  let lastDecision = null;
  let awaitingUserAction = false;

  const removeListener = () => {
    if (listenerId !== null && typeof win.__tcfapi === "function") {
      win.__tcfapi("removeEventListener", TCF_API_VERSION, () => {}, listenerId);
      listenerId = null;
    }
  };

  const publish = () => {
    if (stopped || awaitingUserAction) return;
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

  /**
   * Reopening the panel pauses collection until a new user decision. Google's
   * readiness callbacks can still expose the previous grant while its UI opens.
   *
   * It also re-arms `lastDecision`, which is what makes a re-grant visible: the
   * CMP republishes `granted` after the user confirms, and a publish that
   * matches the last reported value is suppressed. Without this, somebody who
   * opened the panel and accepted again would stay unmeasured until reload.
   */
  const withdraw = () => {
    awaitingUserAction = true;
    lastDecision = "denied";
    onChange("denied");
  };
  win.addEventListener?.(PRIVACY_CHOICES_OPENING_EVENT, withdraw);

  googlefc.callbackQueue.push({ CONSENT_MODE_DATA_READY: publish });
  googlefc.callbackQueue.push({
    CONSENT_API_READY: () => {
      if (stopped || typeof win.__tcfapi !== "function") return;
      win.__tcfapi("addEventListener", TCF_API_VERSION, (data, success) => {
        if (success && data?.listenerId !== undefined) listenerId = data.listenerId;
        if (stopped) {
          removeListener();
          return;
        }
        if (!success || data?.eventStatus === "cmpuishown") {
          withdraw();
          return;
        }
        // A listener is also called during registration with incomplete data.
        // Only completed decisions or a valid saved choice can authorize GA.
        if (data?.eventStatus === "useractioncomplete") awaitingUserAction = false;
        else if (data?.eventStatus !== "tcloaded") return;

        // Once consent-mode data is ready this executes synchronously. Before
        // then it remains queued. `publish` also checks for a reopened panel so
        // a delayed callback cannot restore the grant being reconsidered.
        googlefc.callbackQueue.push({ CONSENT_MODE_DATA_READY: publish });
      });
    },
  });

  platform.then((status) => {
    if (!stopped && status !== "ready") withdraw();
  });

  return () => {
    stopped = true;
    win.removeEventListener?.(PRIVACY_CHOICES_OPENING_EVENT, withdraw);
    removeListener();
  };
}
