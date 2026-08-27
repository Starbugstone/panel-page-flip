import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { shouldOfferRewardedGate } from "@/lib/advertising";

export const BULK_UPLOAD_ROUTE = "/upload/bulk/session";
export const SINGLE_UPLOAD_ROUTE = "/upload";

const SESSION_ENDPOINT = "/api/upload/bulk/session";

/**
 * One batch of bulk upload, as the server records it.
 *
 * The server owns the session because a browser flag is not a scope: cleared,
 * copied or edited, `localStorage` answers "may I skip the offer" with whatever
 * suits, and it never expires by itself. What the server cannot do is verify the
 * advertisement — AdSense's Offerwall reveals the page itself and publishes no
 * completion callback — so `rewarded` travels as a note about what the browser
 * reported, and nothing downstream treats it as permission.
 */

export function readBulkUploadSession() {
  return api.get(SESSION_ENDPOINT);
}

export function openBulkUploadSession({ rewarded }) {
  return api.post(SESSION_ENDPOINT, { rewarded });
}

/**
 * The batch is over, so the next one meets the offer again.
 *
 * Deliberately swallows its failure. A session nobody managed to close expires
 * on its own within the hour, and a toast about it would be telling the user
 * about bookkeeping they did not ask for and cannot act on.
 */
export function closeBulkUploadSession() {
  return api.delete(SESSION_ENDPOINT).catch((error) => {
    logger.warn("Could not close the bulk upload session:", error.message);

    return null;
  });
}

/**
 * What should happen when somebody asks for bulk upload.
 *
 * Written as one function over the whole input so the fail-open rule is a
 * single readable expression rather than a branch per failure. `"offer"` is
 * reached only when advertising is genuinely running, Google's code genuinely
 * loaded, and no batch is already open; everything else — a refused request, a
 * blocked script, advertising switched off, a session still live — opens the
 * uploader.
 *
 * @returns {Promise<"open" | "offer">}
 */
export function resolveBulkUploadAccess({ scriptStatus }) {
  return readBulkUploadSession()
    .then((session) => {
      if (session?.active) return "open";

      return shouldOfferRewardedGate({ gateRequired: session?.gateRequired, scriptStatus })
        ? "offer"
        : "open";
    })
    .catch((error) => {
      logger.warn("Could not read the bulk upload session; opening bulk upload:", error.message);

      return "open";
    });
}
