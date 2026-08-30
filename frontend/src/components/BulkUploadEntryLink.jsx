import { Link } from "react-router-dom";

import { useAdSense } from "@/components/ads/AdSenseProvider.jsx";
import { BULK_UPLOAD_GATE_ROUTE, BULK_UPLOAD_QUEUE_ROUTE } from "@/lib/bulk-upload-routes";

/**
 * The one way into a batch, and the one place that decides what it costs.
 *
 * With advertising on it is a plain anchor, because AdSense evaluates the
 * Offerwall on page load and documents no way to re-run it after a client-side
 * route change — a router navigation would reach the gate without ever giving
 * the advertisement a chance to appear. One load of the gate is one such
 * chance, and it is the unit the account-side page-view entitlement counts.
 *
 * While the configuration is still loading, a router navigation reaches the
 * gate without reloading. If the server then says advertising is active, the
 * site code is being loaded for the first time on the correct page and Google
 * can evaluate it there. This avoids treating a fast click as advertising-off.
 *
 * With advertising confirmed off, nothing about the gate is worth reaching,
 * so this is an ordinary router navigation straight to the queue.
 */
export function BulkUploadEntryLink({ search = "", children, ...props }) {
  const { isActive, isLoading } = useAdSense();

  if (isActive) {
    return <a href={`${BULK_UPLOAD_GATE_ROUTE}${search}`} {...props}>{children}</a>;
  }

  const destination = isLoading ? BULK_UPLOAD_GATE_ROUTE : BULK_UPLOAD_QUEUE_ROUTE;
  return <Link to={`${destination}${search}`} {...props}>{children}</Link>;
}
