import { useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { persistCookieNoticeDismissal, wasCookieNoticeDismissed } from "@/lib/cookie-notice-storage";
import { NOTIFICATION_LAYER_CLASSES } from "@/lib/overlay-layers";
import { cn } from "@/lib/utils";

export function CookieNotice() {
  const { pathname } = useLocation();
  const [visible, setVisible] = useState(() => !wasCookieNoticeDismissed());
  const isReaderPage = pathname.startsWith("/read/");

  const dismiss = () => {
    persistCookieNoticeDismissal();
    setVisible(false);
  };

  if (!visible) return null;

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
          We use necessary session and security cookies, plus a theme preference.
          No advertising or analytics cookies are used.{" "}
          <Link className="font-medium text-foreground underline" to="/cookies">Learn more</Link>
        </p>
        <Button className="shrink-0" size="sm" onClick={dismiss}>Got it</Button>
      </div>
    </aside>
  );
}
