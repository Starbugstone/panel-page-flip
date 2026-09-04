import { PageLayout } from "@/components/layout/PageLayout";
import { Link, useSearchParams } from "react-router-dom";

import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { BULK_UPLOAD_QUEUE_ROUTE, SINGLE_UPLOAD_ROUTE } from "@/lib/bulk-upload-routes";

/**
 * The clean page targeted by the account-side AdSense Offerwall.
 *
 * Google owns the Offerwall, rewarded-ad choice, completion and entitlement.
 * The application deliberately has no page-side completion callback: AdSense
 * does not expose one for Offerwall, and inventing one would turn an ordinary
 * navigation into a false claim that an advertisement was watched.
 *
 * This page stays usable when the site code is blocked, consent is refused or
 * rewarded inventory is unavailable. In all of those cases Google simply does
 * not cover the page and the normal continuation below remains available.
 */
export default function BulkUploadGate() {
  const [searchParams] = useSearchParams();
  const folder = searchParams.get("folder");
  const uploaderPath = folder ? `${BULK_UPLOAD_QUEUE_ROUTE}?folder=${encodeURIComponent(folder)}` : BULK_UPLOAD_QUEUE_ROUTE;
  const singleUploaderPath = folder ? `${SINGLE_UPLOAD_ROUTE}?folder=${encodeURIComponent(folder)}` : SINGLE_UPLOAD_ROUTE;

  return (
    <PageLayout width="form" className="flex justify-center">
      <Card className="w-full max-w-xl">
        <CardHeader>
          <CardTitle as="h1" className="page-title">Bulk upload</CardTitle>
          <CardDescription>Upload multiple comics in one batch.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4 text-sm text-muted-foreground">
          <p>
            This server may use Google AdSense Offerwall to help fund hosting.
            When an Offerwall is available, Google presents and completes it on
            this page before granting access; Panel Page Flip does not receive or
            record an advertisement-completion signal.
          </p>
          <p>
            If Google does not show an Offerwall — for example because advertising
            is unavailable, blocked, or you declined consent — bulk upload remains
            available. The upload batch itself is always ad-free.
          </p>
        </CardContent>
        <CardFooter className="flex flex-col gap-2 sm:flex-row sm:justify-between">
          <Button className="w-full sm:w-auto" asChild>
            <Link to={uploaderPath}>Continue to bulk upload</Link>
          </Button>
          <Button className="w-full sm:w-auto" variant="outline" asChild>
            <Link to={singleUploaderPath}>Use single upload instead</Link>
          </Button>
        </CardFooter>
      </Card>
    </PageLayout>
  );
}
