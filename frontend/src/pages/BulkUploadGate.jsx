import { useEffect, useRef, useState } from "react";
import { Link, Navigate, useNavigate, useSearchParams } from "react-router-dom";
import { Loader2 } from "lucide-react";

import { useAdSense } from "@/components/ads/AdSenseProvider.jsx";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import {
  BULK_UPLOAD_ROUTE,
  SINGLE_UPLOAD_ROUTE,
  openBulkUploadSession,
  resolveBulkUploadAccess,
} from "@/lib/bulk-upload-session";
import { logger } from "@/lib/logger";
import { requestRewardedAd } from "@/lib/rewarded-ad";

/**
 * The clean route in front of bulk upload.
 *
 * Everything on this page is written by this application: an explanation and two
 * buttons. That is what makes it somewhere an advertisement may appear at all —
 * the batch screen behind it shows filenames, progress and failures from real
 * files, so it is ad-free, and keeping the two apart is the whole reason this is
 * a separate route rather than a dialog.
 *
 * Most visitors never see it. With advertising off, unconfigured, blocked or
 * simply unable to offer a rewarded advertisement, the page resolves straight
 * through to the uploader.
 */
export default function BulkUploadGate() {
  const { scriptStatus, isLoading } = useAdSense();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [decision, setDecision] = useState(null);
  const [accepting, setAccepting] = useState(false);
  const [adFailed, setAdFailed] = useState(false);
  const mounted = useRef(true);

  // Set on the way in as well as cleared on the way out: Strict Mode replays a
  // committed effect as cleanup-then-setup while keeping the ref, so a teardown
  // that never restores it leaves the page permanently believing it unmounted.
  useEffect(() => {
    mounted.current = true;

    return () => { mounted.current = false; };
  }, []);

  const folder = searchParams.get("folder");
  const uploaderPath = folder ? `${BULK_UPLOAD_ROUTE}?folder=${encodeURIComponent(folder)}` : BULK_UPLOAD_ROUTE;
  const singleUploaderPath = folder ? `${SINGLE_UPLOAD_ROUTE}?folder=${encodeURIComponent(folder)}` : SINGLE_UPLOAD_ROUTE;

  useEffect(() => {
    // Both waits are load-bearing, and for the same reason. Until the runtime
    // configuration lands, `scriptStatus` is "idle" because advertising still
    // looks switched off — deciding then resolves every visit to "open", the
    // offer is never made, and on a fast session endpoint that is *every*
    // visit rather than a rare race.
    if (isLoading || scriptStatus === "loading") return undefined;

    let ignore = false;
    resolveBulkUploadAccess({ scriptStatus }).then((outcome) => {
      if (!ignore) setDecision(outcome);
    });

    return () => { ignore = true; };
  }, [isLoading, scriptStatus]);

  if (decision === "open") return <Navigate replace to={uploaderPath} />;

  if (decision === null) {
    return (
      <div className="container mx-auto flex justify-center px-4 py-16" aria-live="polite">
        <Loader2 className="h-6 w-6 animate-spin" aria-label="Preparing bulk upload" />
      </div>
    );
  }

  /**
   * Show the advertisement, then open the batch.
   *
   * `rewarded` records what Google reported and nothing else — the server keeps
   * it as an audit note and no endpoint treats it as permission. Where no
   * advertisement could be shown at all the batch still opens: issue #73 is
   * explicit that missing rewarded inventory must not block bulk upload.
   */
  const watchAdAndContinue = async () => {
    setAccepting(true);
    setAdFailed(false);

    const outcome = await requestRewardedAd();

    if (outcome === "dismissed") {
      // They closed it early. Nothing is taken away — the offer stands and the
      // single uploader is still one button away — but silently proceeding
      // would record a reward that was not earned.
      if (mounted.current) {
        setAccepting(false);
        setAdFailed(true);
      }

      return;
    }

    try {
      await openBulkUploadSession({ rewarded: outcome === "viewed" });
    } catch (error) {
      // The session is bookkeeping, not permission. Failing to record it must
      // not cost somebody the upload they just agreed to watch an advert for.
      logger.warn("Could not record the bulk upload session:", error.message);
    }

    // Guarded because the await above can outlive the page: somebody who picks
    // "Use single upload instead" while the request is in flight must not be
    // dragged back onto the uploader when it settles.
    if (!mounted.current) return;

    setAccepting(false);
    navigate(uploaderPath, { replace: true });
  };

  return (
    <div className="container mx-auto flex justify-center px-4 py-8">
      <Card className="w-full max-w-xl">
        <CardHeader>
          <CardTitle className="text-2xl font-comic">Bulk upload</CardTitle>
          <CardDescription>Upload multiple comics in one batch.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4 text-sm text-muted-foreground">
          <p>
            Watch a short advertisement to unlock bulk upload for this batch. The
            advertisement is provided by Google and pays for the hosting this
            server runs on.
          </p>
          <p>
            One advertisement covers the whole batch, however many comics are in
            it. Uploading comics one at a time is always available and never asks
            for one. If no rewarded advertisement is available, bulk upload will
            open without one.
          </p>
          {adFailed && (
            <p role="status" className="text-foreground">
              The advertisement was closed before it finished, so bulk upload has
              not been unlocked. You can try again, or upload comics one at a
              time instead.
            </p>
          )}
        </CardContent>
        <CardFooter className="flex flex-col gap-2 sm:flex-row sm:justify-between">
          <Button className="w-full sm:w-auto" onClick={watchAdAndContinue} disabled={accepting}>
            {accepting ? <><Loader2 className="mr-2 h-4 w-4 animate-spin" />Continuing…</> : "Watch ad and continue"}
          </Button>
          <Button className="w-full sm:w-auto" variant="outline" asChild>
            <Link to={singleUploaderPath}>Use single upload instead</Link>
          </Button>
        </CardFooter>
      </Card>
    </div>
  );
}
