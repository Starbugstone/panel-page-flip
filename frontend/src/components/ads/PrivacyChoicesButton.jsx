import { useAdSense } from "@/components/ads/AdSenseProvider.jsx";
import { reopenPrivacyChoices } from "@/lib/privacy-choices";

/**
 * The permanent way back into the consent choices.
 *
 * Consent that can be given and never withdrawn is not consent, so this sits in
 * the site footer rather than inside the banner that collected it — the banner
 * is gone by the time somebody changes their mind. It is rendered in normal
 * footers and separately inside reader settings, where there is no footer.
 *
 * Absent where both Google services are off, and absent until the server has
 * answered: offering it on an installation with neither service would imply
 * this deployment collected a consent it never asked for.
 */
export function PrivacyChoicesButton({ className, children = "Privacy choices" }) {
  const { config, analytics, consent, isLoading, isActive } = useAdSense();
  const analyticsActive = Boolean(analytics?.enabled && analytics.measurementId);
  const client = consent?.client || config?.client;

  if (isLoading || (!isActive && !analyticsActive) || !client) return null;

  return (
    <button
      type="button"
      className={className}
      onClick={() => reopenPrivacyChoices({ client })}
    >
      {children}
    </button>
  );
}
