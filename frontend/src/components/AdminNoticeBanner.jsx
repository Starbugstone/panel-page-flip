import { useCallback, useEffect, useState } from "react";
import { AlertTriangle, Loader2, X } from "lucide-react";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { formatDate } from "@/lib/format";

/**
 * What an administrator left for this account to read.
 *
 * Sits above the page rather than inside one, because a notice about a comic
 * has to be readable from wherever the reader happens to be — and because the
 * comic it is about may already be gone, which is exactly when the notice
 * matters most.
 *
 * Dismissed one at a time and deliberately not automatically: a banner that
 * disappears on navigation is one somebody can miss entirely, and the record of
 * having dismissed it is what makes "were they told?" answerable later.
 */
export function AdminNoticeBanner({ isLoggedIn }) {
  const [dismissingId, setDismissingId] = useState(null);

  const load = useCallback(
    () => api.get("/api/me/warnings")
      .then((data) => data.warnings || [])
      .catch((error) => {
        // Never a banner of its own. A notice that could not be fetched is not
        // something the recipient can act on, and an error where a warning
        // would go reads as the warning.
        logger.error("Could not load administrator notices:", error);

        return null;
      }),
    []
  );

  // Keyed on the session rather than cleared by an effect: signing out has to
  // take the notices off screen in the same render, not one behind it.
  const [state, setState] = useState({ forSession: false, notices: [] });
  const notices = state.forSession === isLoggedIn ? state.notices : [];

  useEffect(() => {
    if (!isLoggedIn) return undefined;

    let ignore = false;
    load().then((waiting) => {
      if (!ignore && waiting) setState({ forSession: true, notices: waiting });
    });

    return () => { ignore = true; };
  }, [isLoggedIn, load]);

  const dismiss = async (notice) => {
    setDismissingId(notice.id);

    try {
      await api.post(`/api/me/warnings/${notice.id}/acknowledge`, {});
      setState((current) => ({
        forSession: true,
        notices: current.notices.filter((item) => item.id !== notice.id),
      }));
    } catch (error) {
      logger.error("Dismissing an administrator notice failed:", error);
      // Left on screen rather than hidden optimistically: a notice the server
      // still considers open would come back on the next page load anyway.
    } finally {
      setDismissingId(null);
    }
  };

  if (notices.length === 0) return null;

  return (
    <div className="border-b bg-amber-50 dark:bg-amber-950/30">
      <div className="container mx-auto space-y-3 px-4 py-3 md:px-6">
        {notices.map((notice) => (
          <Alert
            key={notice.id}
            className="flex flex-wrap items-start justify-between gap-3 border-amber-300 bg-transparent dark:border-amber-800"
          >
            <div className="flex min-w-0 items-start gap-3">
              <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" aria-hidden="true" />
              <div className="min-w-0">
                <AlertTitle className="text-amber-800 dark:text-amber-200">
                  {noticeHeading(notice)}
                </AlertTitle>
                {/* Preserved as written: an administrator explaining a problem
                    uses line breaks, and running them together makes a careful
                    message read as a shout. */}
                <AlertDescription className="whitespace-pre-wrap text-amber-700 dark:text-amber-300">
                  {notice.message}
                </AlertDescription>
                <p className="mt-1 text-xs text-amber-600/80 dark:text-amber-400/80">
                  Sent {formatDate(notice.createdAt)}
                </p>
              </div>
            </div>
            <Button
              size="sm"
              variant="outline"
              disabled={dismissingId === notice.id}
              onClick={() => dismiss(notice)}
              aria-label={`Dismiss notice: ${noticeHeading(notice)}`}
            >
              {dismissingId === notice.id
                ? <Loader2 className="h-4 w-4 animate-spin" />
                : <X className="h-4 w-4" />}
              <span className="ml-2">Dismiss</span>
            </Button>
          </Alert>
        ))}
      </div>
    </div>
  );
}

/** Names what the notice is about, so a message about one comic says which. */
function noticeHeading(notice) {
  if (notice.subject === "comic" && notice.subjectLabel) {
    return `A notice about your comic “${notice.subjectLabel}”`;
  }
  if (notice.subject === "share" && notice.subjectLabel) {
    return `A notice about a comic you shared: “${notice.subjectLabel}”`;
  }

  return "A notice about your account";
}
