import { createContext, useContext, useEffect, useState } from "react";

import { api } from "@/lib/api";
import { logger } from "@/lib/logger";

export const ADVERTISING_OFF = Object.freeze({ enabled: false, client: null });
export const ANALYTICS_OFF = Object.freeze({ enabled: false, measurementId: null });
export const GOOGLE_CONSENT_OFF = Object.freeze({ enabled: false, client: null });
export const TURNSTILE_OFF = Object.freeze({ enabled: false, siteKey: null });
export const LEGAL_CONTACT_UNKNOWN = Object.freeze({
  operator: "Panel Page Flip site operator",
  privacyEmail: null,
  legalEmail: null,
});

const PUBLIC_CONFIG_DEFAULTS = Object.freeze({
  adsense: ADVERTISING_OFF,
  analytics: ANALYTICS_OFF,
  consent: GOOGLE_CONSENT_OFF,
  turnstile: TURNSTILE_OFF,
  legal: LEGAL_CONTACT_UNKNOWN,
  isLoading: true,
});

const PublicConfigContext = createContext(PUBLIC_CONFIG_DEFAULTS);

const fromResponse = (data) => ({
  adsense: data?.adsense ?? ADVERTISING_OFF,
  analytics: data?.analytics ?? ANALYTICS_OFF,
  consent: data?.googleConsent ?? GOOGLE_CONSENT_OFF,
  turnstile: data?.turnstile ?? TURNSTILE_OFF,
  legal: {
    operator: data?.operator || LEGAL_CONTACT_UNKNOWN.operator,
    privacyEmail: data?.privacyEmail ?? null,
    legalEmail: data?.legalEmail ?? null,
  },
  isLoading: false,
});

export function PublicConfigProvider({ children }) {
  const [configuration, setConfiguration] = useState(PUBLIC_CONFIG_DEFAULTS);

  useEffect(() => {
    let ignore = false;

    api.get("/api/public-config", { notifyUnauthorized: false })
      .then((data) => {
        if (!ignore) setConfiguration(fromResponse(data));
      })
      .catch((error) => {
        logger.warn("Could not load the public configuration:", error.message);
        if (!ignore) setConfiguration({ ...PUBLIC_CONFIG_DEFAULTS, isLoading: false });
      });

    return () => { ignore = true; };
  }, []);

  return (
    <PublicConfigContext.Provider value={configuration}>
      {children}
    </PublicConfigContext.Provider>
  );
}

export function usePublicConfig() {
  return useContext(PublicConfigContext);
}
