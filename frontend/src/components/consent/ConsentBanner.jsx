import { useEffect, useRef, useState } from "react";
import ConsentNotice from "klaro/src/components/consent-notice.jsx";
import "klaro/dist/klaro.min.css";

import { useConsent } from "@/components/consent/ConsentProvider.jsx";
import { createKlaroConfig, createKlaroManager, klaroTranslate } from "./KlaroConfig";
import { enhanceKlaroAccessibility } from "./klaro-accessibility";
import "./consent-banner.css";

function LocalConsentBanner({ analyticsConsent, acceptAnalytics, rejectAnalytics }) {
  const container = useRef(null);
  const [config] = useState(createKlaroConfig);
  // Source components share React 19 and its lifecycle with the application;
  // Klaro's prebuilt entry bundles a separate renderer and auto-mounts globally.
  const [manager] = useState(() => createKlaroManager(config, analyticsConsent,
    (decision) => decision === "granted" ? acceptAnalytics() : rejectAnalytics()));

  useEffect(() => enhanceKlaroAccessibility(container.current, config), [config]);

  return (
    <div ref={container} className="klaro ppf-consent" lang={config.lang}>
      <ConsentNotice config={config} manager={manager} lang={config.lang}
        t={(...args) => klaroTranslate(config, ...args)} show hide={() => {}} />
    </div>
  );
}

export function ConsentBanner() {
  const consent = useConsent();
  if (!consent.isAnalyticsDialogOpen) return null;
  return <LocalConsentBanner key={consent.analyticsConsent} {...consent} />;
}
