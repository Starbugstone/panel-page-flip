import { useEffect, useRef, useState } from "react";
import { useLocation } from "react-router-dom";

import { useConsent } from "@/components/consent/ConsentProvider.jsx";
import { usePublicConfig } from "@/components/config/PublicConfigProvider.jsx";
import {
  analyticsPageFor,
  disableGoogleAnalytics,
  enableGoogleAnalytics,
  guardGoogleAnalyticsNavigation,
  loadGoogleAnalytics,
  sendAnalyticsPageView,
  setAnalyticsPageContext,
} from "@/lib/google-analytics";
import { PRIVACY_CHOICES_OPENING_EVENT } from "@/lib/google-consent";
import { isGoogleFreeRoute } from "@/lib/google-free-routes";

const APP_ORIGIN = import.meta.env.VITE_APP_URL || "http://localhost:8080";

/**
 * Loading the measurement tag, once consent says so, and never before.
 *
 * Which consent — the Google CMP's analytics purpose or this application's own
 * Analytics preferences — is {@link useConsent}'s problem, not this component's.
 * Analytics asking an advertising context whether it may run is what made an
 * AdSense publisher id a prerequisite for measurement in the first place.
 */
export function GoogleAnalyticsProvider({ children }) {
  const { analytics, isLoading } = usePublicConfig();
  const { analyticsConsent } = useConsent();
  const { key: locationKey, pathname } = useLocation();
  const [scriptStatus, setScriptStatus] = useState("idle");
  const lastRoute = useRef(null);

  const active = !isLoading && Boolean(analytics?.enabled && analytics.measurementId);
  const measurementId = analytics?.measurementId;
  const consentDecision = analyticsConsent;

  useEffect(() => {
    if (!active || !measurementId) return undefined;
    // Synchronously, before Google's panel is on screen. The consent observer
    // reports the withdrawal too, but that arrives through a state update on
    // the next render — and somebody who has just opened the panel to change
    // their mind should not be measured while they are reading it.
    const withdrawImmediately = () => disableGoogleAnalytics(measurementId);
    window.addEventListener(PRIVACY_CHOICES_OPENING_EVENT, withdrawImmediately);

    return () => window.removeEventListener(PRIVACY_CHOICES_OPENING_EVENT, withdrawImmediately);
  }, [active, measurementId]);

  useEffect(() => {
    if (!active || !measurementId) return undefined;

    return guardGoogleAnalyticsNavigation(measurementId);
  }, [active, measurementId]);

  useEffect(() => {
    if (!active || !measurementId || consentDecision !== "granted") {
      if (measurementId) disableGoogleAnalytics(measurementId);
      return undefined;
    }

    // Never on a Google-free route, and never on a route the page table does
    // not name. The first is a policy the page table must not be able to
    // override by accident; the second is what keeps user-entered paths,
    // tokens and comic ids out of measurement entirely.
    const page = isGoogleFreeRoute(pathname) ? null : analyticsPageFor(pathname);
    if (!page) {
      disableGoogleAnalytics(measurementId);
      return undefined;
    }

    const pageFields = {
      page_location: new URL(page.path, APP_ORIGIN).href,
      page_title: page.title,
      page_referrer: "",
    };
    let ignore = false;
    setAnalyticsPageContext(pageFields);
    enableGoogleAnalytics(measurementId);
    loadGoogleAnalytics(measurementId, { pageFields }).then((status) => {
      if (!ignore) setScriptStatus(status);
    });

    return () => { ignore = true; };
  }, [active, consentDecision, measurementId, pathname]);

  useEffect(() => {
    if (!active || consentDecision !== "granted" || scriptStatus !== "ready") return;

    const page = isGoogleFreeRoute(pathname) ? null : analyticsPageFor(pathname);
    if (!page) {
      disableGoogleAnalytics(measurementId);
      return;
    }

    const pageLocation = new URL(page.path, APP_ORIGIN).href;
    const pageFields = {
      page_location: pageLocation,
      page_title: page.title,
      page_referrer: "",
    };
    // The navigation guard disabled collection before the URL changed. Update
    // gtag's context while suspended so automatic lifecycle events cannot fall
    // back to the real URL, then re-enable and send our sanitized view.
    setAnalyticsPageContext(pageFields);
    enableGoogleAnalytics(measurementId);
    if (lastRoute.current === pathname) return;
    lastRoute.current = pathname;

    sendAnalyticsPageView(measurementId, pageFields);
  }, [active, consentDecision, locationKey, measurementId, pathname, scriptStatus]);

  return children;
}
