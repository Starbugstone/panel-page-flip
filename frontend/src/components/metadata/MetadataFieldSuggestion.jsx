import { Button } from "@/components/ui/button";
import { fieldLabel, sourceLabel, summarise } from "@/lib/metadata-suggestions";
import { Check } from "lucide-react";

/**
 * One proposed change: what the field holds now, what it would hold, and who
 * said so. The button reads "Added" rather than disappearing, so a list does
 * not reflow under the pointer that just used it.
 */
export function MetadataFieldSuggestion({ suggestion, accepted, onAccept }) {
  const label = fieldLabel(suggestion.field);

  return (
    <div className="flex items-start justify-between gap-3 rounded-md border px-3 py-2 text-sm">
      <div className="min-w-0">
        <p className="font-medium">{label}</p>
        <p className="text-xs text-muted-foreground">
          {summarise(suggestion.current)} → <span className="text-foreground">{summarise(suggestion.suggested)}</span>
          {" · "}{sourceLabel(suggestion.source)}
        </p>
      </div>
      <Button
        type="button"
        variant={accepted ? "ghost" : "outline"}
        size="sm"
        disabled={accepted}
        onClick={onAccept}
        aria-label={accepted ? `${label} added` : `Use ${label} ${summarise(suggestion.suggested)}`}
      >
        {accepted ? <><Check className="mr-1 h-3 w-3" /> Added</> : "Use"}
      </Button>
    </div>
  );
}
