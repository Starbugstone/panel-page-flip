import { useEffect, useState } from "react";
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
  const { scriptStatus } = useAdSense();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [decision, setDecision] = useState(null);
  const [accepting, setAccepting] = useState(false);

  const folder = searchParams.get("folder");
  const uploaderPath = folder ? `${BULK_UPLOAD_ROUTE}?folder=${encodeURIComponent(folder)}` : BULK_UPLOAD_ROUTE;

  useEffect(() => {
    // Waiting out the script is what makes the offer meaningful: deciding while
    // it is still in flight would resolve every visit to "open" and the rewarded
    // choice would never be made.
    if (scriptStatus === "loading") return undefined;

    let ignore = false;
    resolveBulkUploadAccess({ scriptStatus }).then((outcome) => {
      if (!ignore) setDecision(outcome);
    });

    return () => { ignore = true; };
  }, [scriptStatus]);

  if (decision === "open") return <Navigate replace to={uploaderPath} />;

  if (decision === null) {
    return (
      <div className="container mx-auto flex justify-center px-4 py-16" aria-live="polite">
        <Loader2 className="h-6 w-6 animate-spin" aria-label="Preparing bulk upload" />
      </div>
    );
  }

  const watchAdAndContinue = async () => {
    setAccepting(true);
    try {
      await openBulkUploadSession({ rewarded: true });
    } catch (error) {
      // The session is bookkeeping, not permission. Failing to record it must
      // not cost somebody the upload they just agreed to watch an advert for.
      logger.warn("Could not record the bulk upload session:", error.message);
    }
    navigate(uploaderPath);
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
            for one.
          </p>
        </CardContent>
        <CardFooter className="flex flex-col gap-2 sm:flex-row sm:justify-between">
          <Button className="w-full sm:w-auto" onClick={watchAdAndContinue} disabled={accepting}>
            {accepting ? <><Loader2 className="mr-2 h-4 w-4 animate-spin" />Continuing…</> : "Watch ad and continue"}
          </Button>
          <Button className="w-full sm:w-auto" variant="outline" asChild>
            <Link to={SINGLE_UPLOAD_ROUTE}>Use single upload instead</Link>
          </Button>
        </CardFooter>
      </Card>
    </div>
  );
}
