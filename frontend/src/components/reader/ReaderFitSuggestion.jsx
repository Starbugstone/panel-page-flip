import { X } from "lucide-react";

import { Button } from "@/components/ui/button";

/**
 * A suggestion, and only that. Rotating a device or picking one up must not
 * silently rewrite how somebody has chosen to read, so the reader is offered
 * the better fit for this screen and left to decide.
 */
export function ReaderFitSuggestion({ fitLabel, contextLabel, onAccept, onDismiss }) {
  return (
    <div
      role="status"
      className="reader-suggestion"
    >
      <p className="text-sm leading-snug">
        <span className="font-medium">{fitLabel}</span> reads better on {contextLabel}.
      </p>
      <div className="flex shrink-0 items-center gap-1">
        <Button size="sm" variant="secondary" onClick={onAccept}>Use it here</Button>
        <Button size="icon" variant="ghost" onClick={onDismiss} aria-label="Dismiss suggestion" title="Dismiss suggestion">
          <X className="h-4 w-4" />
        </Button>
      </div>
    </div>
  );
}
