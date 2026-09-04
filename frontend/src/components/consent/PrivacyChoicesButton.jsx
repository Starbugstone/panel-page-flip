import { useConsent } from "@/components/consent/ConsentProvider.jsx";

/**
 * The permanent way back into the consent choices.
 *
 * Consent that can be given and never withdrawn is not consent, so this sits in
 * the site footer rather than inside the banner that collected it — the banner
 * is gone by the time somebody changes their mind. It is rendered in normal
 * footers and separately inside reader settings, where there is no footer.
 *
 * What it reopens is whichever provider owns consent here: Google's certified
 * CMP where advertising is on, this application's Analytics preferences where
 * only measurement is. The button does not need to know which — {@link useConsent}
 * does, including the awkward case of being clicked on a page that is required
 * to stay free of Google's tag.
 *
 * Absent where no optional service is on, and absent until the server has
 * answered: offering it on an installation with neither would imply this
 * deployment collected a consent it never asked for.
 */
export function PrivacyChoicesButton({ className, children }) {
  const { canOpenPreferences, provider, openPreferences } = useConsent();

  if (!canOpenPreferences) return null;

  // Named for what it actually opens. "Privacy choices" is Google's own wording
  // for its panel, and using it for a dialogue that only covers analytics would
  // promise choices this installation does not offer.
  const label = children ?? (provider === "local" ? "Analytics preferences" : "Privacy choices");

  return (
    <button type="button" className={className} onClick={openPreferences}>
      {label}
    </button>
  );
}
