import { createContext, useContext, useEffect, useState } from "react";
import { useLocation } from "react-router-dom";

import {
  useAdvertisingConfig,
  ADVERTISING_OFF,
  ANALYTICS_OFF,
  GOOGLE_CONSENT_OFF,
  LEGAL_CONTACT_UNKNOWN,
} from "@/hooks/use-advertising.jsx";
import { isAdSafeRoute, isAdvertisingActive } from "@/lib/advertising";
import { keepRouteAdFree, loadAdSenseScript } from "@/lib/adsense-loader";

/**
 * The one place Google's site code is loaded, and the one place it is kept away
 * from user content.
 *
 * Individual pages never inject advertising script of their own. They would each
 * have to get the consent rules, the load-once rule and the route policy right,
 * and the failure mode of getting the route policy wrong is an advertisement
 * beside somebody's uploaded comic — which is the thing this whole feature is
 * arranged to prevent.
 *
 * Two rules, both application-side:
 *
 *   1. The site code is fetched the first time the user is on an ad-safe route,
 *      and never on any other. An installation whose users go straight to their
 *      library never loads it at all.
 *   2. Because a single-page application cannot unload a script, every
 *      navigation into an ad-free route sweeps away whatever Auto Ads placed.
 *
 * Consent is Google's certified CMP's business, installed by the same site code.
 * Nothing here reads, stores or synthesises a consent state; a second opinion
 * about consent is worse than none.
 */

const AdSenseContext = createContext({
  config: ADVERTISING_OFF,
  analytics: ANALYTICS_OFF,
  consent: GOOGLE_CONSENT_OFF,
  legal: LEGAL_CONTACT_UNKNOWN,
  isLoading: false,
  /** Whether this installation shows advertising at all. */
  isActive: false,
  /** "idle" until asked for, then "loading", then "ready" or "unavailable". */
  scriptStatus: "idle",
});

export function AdSenseProvider({ children }) {
  const { config, analytics, consent, legal, isLoading } = useAdvertisingConfig();
  const { pathname } = useLocation();
  // Null until an attempt settles. "loading" is derived rather than stored, so
  // the effect below never has to write state just to say it has started.
  const [settledStatus, setSettledStatus] = useState(null);

  const active = isAdvertisingActive(config);
  const adSafe = isAdSafeRoute(pathname);
  const scriptStatus = settledStatus ?? (active && adSafe ? "loading" : "idle");

  useEffect(() => {
    if (!active || !adSafe) return undefined;

    let ignore = false;
    loadAdSenseScript(config.client).then((status) => {
      if (!ignore) setSettledStatus(status);
    });

    return () => { ignore = true; };
  }, [active, adSafe, config.client]);

  useEffect(() => {
    if (adSafe) return undefined;

    // Runs whether or not this application ever loaded the script: an operator
    // may have added Auto Ads through a tag manager, and the boundary is about
    // what is on the page, not about who put it there.
    //
    // Watches rather than sweeping once, because Auto Ads arrive well after the
    // navigation commits — a single pass at commit time reliably finds nothing,
    // and the advertisement that lands a moment later stays beside the artwork
    // for the whole visit.
    return keepRouteAdFree();
  }, [adSafe, pathname]);

  return (
    <AdSenseContext.Provider value={{ config, analytics, consent, legal, isLoading, isActive: active, scriptStatus }}>
      {children}
    </AdSenseContext.Provider>
  );
}

export function useAdSense() {
  return useContext(AdSenseContext);
}
