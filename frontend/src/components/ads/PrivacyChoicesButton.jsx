import { useAdSense } from "@/components/ads/AdSenseProvider.jsx";
import { isAdvertisingActive } from "@/lib/advertising";
import { reopenPrivacyChoices } from "@/lib/privacy-choices";

/**
 * The permanent way back into the consent choices.
 *
 * Consent that can be given and never withdrawn is not consent, so this sits in
 * the footer of every page rather than inside the banner that collected it —
 * the banner is gone by the time somebody changes their mind.
 *
 * Absent where advertising is off, and absent until the server has said which
 * it is: offering it on an installation that shows no advertising would imply
 * this deployment collected a consent it never asked for.
 */
export function PrivacyChoicesButton({ className }) {
  const { config, isLoading } = useAdSense();

  if (isLoading || !isAdvertisingActive(config)) return null;

  return (
    <button
      type="button"
      className={className}
      onClick={() => reopenPrivacyChoices({ client: config.client })}
    >
      Privacy choices
    </button>
  );
}
