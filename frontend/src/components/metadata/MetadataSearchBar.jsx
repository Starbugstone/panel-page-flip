import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { RefreshCw, Search, Sparkles } from "lucide-react";

/**
 * The heading and the two things that can be asked of a provider.
 *
 * Refresh appears only for a comic already matched to a record, because it
 * asks for that exact issue again rather than repeating a fuzzy search.
 */
export function MetadataSearchBar({ providers, chosen, onProviderChange, busy, canRefresh, onRefresh, onSearch }) {
  return (
    <div className="flex items-center justify-between gap-2">
      <p className="flex items-center gap-1.5 text-sm font-medium">
        <Sparkles className="h-4 w-4" /> Suggestions
      </p>
      <div className="flex items-center gap-2">
        {/* Only shown when there is a choice to make. One provider is asked
            per search, because each one answering costs its own quota. */}
        {providers.length > 1 && (
          <Select value={chosen ?? undefined} onValueChange={onProviderChange}>
            <SelectTrigger className="h-8 w-36" aria-label="Provider to search">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {providers.map((provider) => (
                <SelectItem key={provider.key} value={provider.key}>{provider.label}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}
        {canRefresh && (
          <Button type="button" variant="ghost" size="sm" disabled={busy !== null} onClick={onRefresh}>
            <RefreshCw className="mr-1 h-3 w-3" />
            {busy === "refresh" ? "Refreshing…" : "Refresh"}
          </Button>
        )}
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={busy !== null || providers.length === 0}
          onClick={onSearch}
        >
          <Search className="mr-1 h-3 w-3" />
          {busy === "search" ? "Searching…" : providers.length === 1 ? `Search ${providers[0].label}` : "Search provider"}
        </Button>
      </div>
    </div>
  );
}
