import { Link } from "react-router-dom";

import { Button } from "@/components/ui/button";
import { useConsent } from "@/components/consent/ConsentProvider.jsx";
import { NOTIFICATION_LAYER_CLASSES } from "@/lib/overlay-layers";
import { cn } from "@/lib/utils";

/**
 * The consent request for an installation that measures and does not advertise.
 *
 * Only rendered where this application owns the answer. Where AdSense is on,
 * Google's certified CMP is the one dialogue and this never appears; two panels
 * covering the analytics purpose would leave two answers that can disagree.
 *
 * Accept and reject are the same component, the same size and side by side,
 * because a reject that is harder to reach than accept is not a free choice.
 * There is deliberately no "X": dismissing is not deciding, and an undecided
 * visitor is simply not measured. Somebody who wants it gone can reject it,
 * which is the same amount of work as accepting and does not cost them anything
 * in the application.
 */
export function AnalyticsConsentDialog() {
  const { isAnalyticsDialogOpen, acceptAnalytics, rejectAnalytics } = useConsent();

  if (!isAnalyticsDialogOpen) return null;

  return (
    <aside
      aria-label="Analytics preferences"
      role="dialog"
      className={cn(
        "fixed inset-x-4 bottom-4 mx-auto max-w-3xl rounded-lg border bg-background p-4 shadow-lg",
        NOTIFICATION_LAYER_CLASSES.cookieNotice
      )}
    >
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-sm text-muted-foreground">
          May we use Google Analytics to measure which pages of this site are used? It
          sets analytics storage and sends a fixed page category — never your library,
          your comics, your searches or anything you type.{" "}
          <Link className="font-medium text-foreground underline" to="/privacy">How we use it</Link>.
          Everything here works either way, and you can change your mind at any time
          through <strong>Analytics preferences</strong>.
        </p>
        <div className="flex shrink-0 gap-2">
          <Button size="sm" variant="outline" onClick={rejectAnalytics}>Reject analytics</Button>
          <Button size="sm" onClick={acceptAnalytics}>Accept analytics</Button>
        </div>
      </div>
    </aside>
  );
}
