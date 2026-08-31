import { useEffect, useRef, useState } from "react";
import { useLocation } from "react-router-dom";

import { useAdSense } from "@/components/ads/AdSenseProvider.jsx";
import {
  analyticsPageFor,
  disableGoogleAnalytics,
  enableGoogleAnalytics,
  guardGoogleAnalyticsNavigation,
  loadGoogleAnalytics,
  sendAnalyticsPageView,
  setAnalyticsPageContext,
} from "@/lib/google-analytics";
import { observeAnalyticsConsent, PRIVACY_CHOICES_OPENING_EVENT } from "@/lib/google-consent";

const APP_ORIGIN = import.meta.env.VITE_APP_URL || "http://localhost:8080";

export function GoogleAnalyticsProvider({ children }) {
  const { analytics, consent, isActive, isLoading, scriptStatus: adSenseScriptStatus } = useAdSense();
  const { key: locationKey, pathname } = useLocation();
  const [consentDecision, setConsentDecision] = useState("waiting");
  const [scriptStatus, setScriptStatus] = useState("idle");
  const lastRoute = useRef(null);

  const active = !isLoading && Boolean(
    analytics?.enabled && analytics.measurementId && consent?.enabled && consent.client
  );
  const measurementId = analytics?.measurementId;

  useEffect(() => {
    if (!active) return undefined;

    // The AdSense site tag installs the same CMP on ad-safe pages. Loading the
    // standalone copy there would race two scripts that own one consent UI.
    // If that tag is unavailable, fall back to the consent-only script.
    const loadPlatform = !isActive || adSenseScriptStatus === "idle" || adSenseScriptStatus === "unavailable";

    return observeAnalyticsConsent(consent.client, { onChange: setConsentDecision, loadPlatform });
  }, [active, adSenseScriptStatus, consent?.client, isActive]);

  useEffect(() => {
    if (!active || !measurementId) return undefined;
    const withdrawImmediately = () => {
      disableGoogleAnalytics(measurementId);
      setConsentDecision("denied");
    };
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

    if (!analyticsPageFor(pathname)) {
      disableGoogleAnalytics(measurementId);
      return undefined;
    }

    const page = analyticsPageFor(pathname);
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

    const page = analyticsPageFor(pathname);
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
