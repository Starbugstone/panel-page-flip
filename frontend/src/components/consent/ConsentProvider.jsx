import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";

import { usePublicConfig } from "@/components/config/PublicConfigProvider.jsx";
import { hasRequestedAdSenseScript } from "@/lib/adsense-loader";
import {
  ANALYTICS_CONSENT_DENIED,
  ANALYTICS_CONSENT_GRANTED,
  ANALYTICS_CONSENT_UNDECIDED,
  persistAnalyticsConsent,
  readAnalyticsConsent,
} from "@/lib/analytics-consent-storage";
import { isGoogleFreeRoute } from "@/lib/google-free-routes";
import { observeAnalyticsConsent } from "@/lib/google-consent";
import { reopenPrivacyChoices } from "@/lib/privacy-choices";

/**
 * The one answer to "may we use optional storage", and who is asking.
 *
 * Consent used to be a property of the advertising context, which is why
 * Analytics could not run without an AdSense publisher id: the only consent
 * provider wired up was Google's Privacy & Messaging, installed by the
 * advertising site code. The shared concern was never advertising. It is
 * consent, and it has two possible owners:
 *
 *   google — Google's certified CMP, which AdSense requires for EEA/UK/Swiss ad
 *            traffic and which can gather the analytics purpose in the same
 *            message when the account has consent mode for analytics enabled;
 *   local  — this application's own Analytics preferences, for an installation
 *            that measures and does not advertise.
 *
 * The server decides which, from what is effectively enabled — see
 * `App\Service\ConsentConfiguration`. Never both: two dialogues covering the
 * same purpose is how somebody accepts in one and rejects in the other.
 *
 * Analytics fails closed under either owner. Unknown, denied, a blocked script
 * and an account that never enabled the analytics purpose all read as denied,
 * because measuring somebody who did not agree is worse than not measuring.
 */

const CONSENT_DEFAULTS = Object.freeze({
  provider: null,
  googleClient: null,
  coversAnalytics: false,
  isLoading: true,
  analyticsConsent: ANALYTICS_CONSENT_DENIED,
  /** Whether the local Analytics dialogue should be on screen right now. */
  isAnalyticsDialogOpen: false,
  /** Whether there is any consent control worth offering the user. */
  canOpenPreferences: false,
  acceptAnalytics: () => {},
  rejectAnalytics: () => {},
  openPreferences: () => {},
});

const ConsentContext = createContext(CONSENT_DEFAULTS);

/** Where the Google CMP is reopened from when the user is on a Google-free page. */
const PREFERENCES_FALLBACK_ROUTE = "/";

export function ConsentProvider({ children }) {
  const { consent, isLoading } = usePublicConfig();
  const { pathname } = useLocation();
  const navigate = useNavigate();
  const provider = consent?.provider ?? null;
  const googleClient = consent?.googleClient ?? null;
  const coversAnalytics = Boolean(consent?.analytics);
  const googleFree = isGoogleFreeRoute(pathname);

  const [googleDecision, setGoogleDecision] = useState(ANALYTICS_CONSENT_UNDECIDED);
  // Read once, synchronously, so the very first render already knows whether a
  // returning visitor has decided. An effect would render the dialogue for a
  // frame to somebody who rejected analytics months ago.
  const [localDecision, setLocalDecision] = useState(readAnalyticsConsent);
  const [reopenedLocally, setReopenedLocally] = useState(false);
  // Set when the user asks for the Google panel from a Google-free page. Those
  // pages must not mount Funding Choices, so the request survives one
  // navigation instead. A ref rather than state: it is a pending instruction,
  // not something any render reads.
  const pendingGooglePanel = useRef(false);

  const analyticsConsent = useMemo(() => {
    if (!coversAnalytics) return ANALYTICS_CONSENT_DENIED;
    if (provider === "google") return googleDecision;
    if (provider === "local") return localDecision;

    return ANALYTICS_CONSENT_DENIED;
  }, [coversAnalytics, googleDecision, localDecision, provider]);

  useEffect(() => {
    if (provider !== "google" || !coversAnalytics || googleFree || !googleClient) return undefined;

    // The advertising site code installs the same CMP on ad-safe routes.
    // Fetching the standalone copy as well would put two scripts in charge of
    // one consent dialogue, so the loader is asked whether it has already been
    // requested rather than this component keeping its own tally.
    return observeAnalyticsConsent(googleClient, {
      onChange: setGoogleDecision,
      loadPlatform: !hasRequestedAdSenseScript(),
    });
  }, [coversAnalytics, googleClient, googleFree, provider]);

  const openGooglePanel = useCallback(() => {
    reopenPrivacyChoices({ client: googleClient });
  }, [googleClient]);

  useEffect(() => {
    if (!pendingGooglePanel.current || googleFree) return;
    pendingGooglePanel.current = false;
    openGooglePanel();
  }, [googleFree, openGooglePanel, pathname]);

  const decide = useCallback((decision) => {
    persistAnalyticsConsent(decision);
    setLocalDecision(decision);
    setReopenedLocally(false);
  }, []);

  const value = useMemo(() => {
    const undecidedLocally = provider === "local" && coversAnalytics && localDecision === ANALYTICS_CONSENT_UNDECIDED;

    return {
      provider,
      googleClient,
      coversAnalytics,
      isLoading,
      analyticsConsent,
      // Nothing is asked until the server has said which provider owns the
      // question: a dialogue rendered during the round trip would be the wrong
      // one about half the time, and a consent answer given to the wrong
      // question is not consent.
      isAnalyticsDialogOpen: !isLoading && provider === "local" && coversAnalytics
        && (reopenedLocally || undecidedLocally),
      canOpenPreferences: !isLoading && (provider === "local" || (provider === "google" && Boolean(googleClient))),
      acceptAnalytics: () => decide(ANALYTICS_CONSENT_GRANTED),
      rejectAnalytics: () => decide(ANALYTICS_CONSENT_DENIED),
      // There is deliberately no "dismiss": closing the dialogue without
      // answering is not a decision, and an undecided visitor is simply not
      // measured. Accept and reject both close it, and both are one click.
      openPreferences: () => {
        if (provider === "local") {
          setReopenedLocally(true);

          return;
        }
        if (provider !== "google" || !googleClient) return;
        if (googleFree) {
          // Do not defeat the Google-free rule to honour a click on it.
          pendingGooglePanel.current = true;
          navigate(PREFERENCES_FALLBACK_ROUTE);

          return;
        }
        openGooglePanel();
      },
    };
  }, [
    analyticsConsent,
    coversAnalytics,
    decide,
    googleClient,
    googleFree,
    isLoading,
    localDecision,
    navigate,
    openGooglePanel,
    provider,
    reopenedLocally,
  ]);

  return <ConsentContext.Provider value={value}>{children}</ConsentContext.Provider>;
}

export function useConsent() {
  return useContext(ConsentContext);
}
