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
 * Absent where advertising is off, because there is then no advertising consent
 * to revisit and offering one would imply this installation collected something
 * it never did.
 */
export function PrivacyChoicesButton({ className }) {
  const { config } = useAdSense();

  if (!isAdvertisingActive(config)) return null;

  return (
    <button type="button" className={className} onClick={() => reopenPrivacyChoices()}>
      Privacy choices
    </button>
  );
}
