import { useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { usePublicConfig } from "@/components/config/PublicConfigProvider.jsx";
import { PrivacyChoicesButton } from "@/components/consent/PrivacyChoicesButton.jsx";
import { useConsent } from "@/components/consent/ConsentProvider.jsx";
import { persistCookieNoticeDismissal, wasCookieNoticeDismissed } from "@/lib/cookie-notice-storage";
import { isAdvertisingActive } from "@/lib/advertising";
import { NOTIFICATION_LAYER_CLASSES } from "@/lib/overlay-layers";
import { cn } from "@/lib/utils";

/**
 * What this installation stores, in one sentence, for each of the four states.
 *
 * "Got it" acknowledges the notice; it grants nothing. Where there is an
 * optional purpose to agree to, the sentence names the control that actually
 * decides it — Google's panel, or this application's Analytics preferences —
 * rather than implying that dismissing this banner was the decision.
 */
function storageSentence(advertising, analytics) {
  if (advertising && analytics) {
    return "Advertising on some pages and optional analytics use additional storage, which you accept or reject by purpose in the Google privacy choices panel.";
  }
  if (advertising) {
    return "Advertising on some pages uses additional storage, which you accept or reject in the Google privacy choices panel.";
  }
  if (analytics) {
    return "Optional analytics uses additional storage only if you accept it; you can change that at any time through Analytics preferences.";
  }

  return "No advertising or analytics cookies are used.";
}

export function CookieNotice() {
  const { pathname } = useLocation();
  const { adsense, analytics, isLoading } = usePublicConfig();
  const { isAnalyticsDialogOpen } = useConsent();
  const [visible, setVisible] = useState(() => !wasCookieNoticeDismissed());
  const isReaderPage = pathname.startsWith("/read/");
  const advertising = isAdvertisingActive(adsense);
  const analyticsActive = Boolean(analytics?.enabled && analytics.measurementId);

  const dismiss = () => {
    persistCookieNoticeDismissal();
    setVisible(false);
  };

  // Nothing is said about cookies until the server has said what this
  // installation actually uses. The four wordings contradict each other, and
  // the dismissal is permanent: somebody who pressed "Got it" during the round
  // trip would have been told the wrong one, once, and never see the
  // correction.
  //
  // It also yields to the analytics consent request, which occupies the same
  // corner and is an actual question rather than a notice. This notice returns
  // on the next page load once that has been answered.
  if (!visible || isLoading || isAnalyticsDialogOpen) return null;

  return (
    <aside
      aria-label="Cookie notice"
      className={cn(
        "fixed inset-x-4 bottom-4 mx-auto max-w-3xl rounded-lg border bg-background p-4",
        isReaderPage && "reader-cookie-notice",
        NOTIFICATION_LAYER_CLASSES.cookieNotice
      )}
    >
      <div className="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-sm text-muted-foreground">
          We use necessary session and security cookies, plus a theme preference.{" "}
          {storageSentence(advertising, analyticsActive)}{" "}
          <Link className="font-medium text-foreground underline" to="/cookies">Learn more</Link>
        </p>
        <div className="flex shrink-0 flex-wrap items-center gap-3">
          <PrivacyChoicesButton className="min-h-11 text-sm font-medium underline underline-offset-4" />
          <Button className="shrink-0" size="sm" onClick={dismiss}>Got it</Button>
        </div>
      </div>
    </aside>
  );
}
