import { useState } from "react";
import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";

const STORAGE_KEY = "panel-page-flip:cookie-notice-dismissed";

export function CookieNotice() {
  const [visible, setVisible] = useState(() => localStorage.getItem(STORAGE_KEY) !== "true");

  const dismiss = () => {
    localStorage.setItem(STORAGE_KEY, "true");
    setVisible(false);
  };

  if (!visible) return null;

  return (
    <aside
      aria-label="Cookie notice"
      className="fixed inset-x-4 bottom-4 z-[100] mx-auto max-w-3xl rounded-lg border bg-background p-4"
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
