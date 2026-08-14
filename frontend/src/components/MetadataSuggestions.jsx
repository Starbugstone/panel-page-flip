import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Sparkles, Check, Search } from "lucide-react";

const FIELD_LABELS = {
  series: "Series",
  issueNumber: "Issue",
  issueCount: "Issues in series",
  volume: "Volume",
  publisher: "Publisher",
  description: "Description",
  publishedAt: "Published",
};

const SOURCE_LABELS = {
  comicinfo: "from the file",
  filename: "from the filename",
  provider: "from a provider",
  user: "yours",
};

const summarise = (value) => {
  if (value === null || value === undefined || value === "") return "empty";
  const text = String(value);
  return text.length > 60 ? `${text.slice(0, 60)}…` : text;
};

/**
 * Proposals about a comic, and a way to take them one at a time.
 *
 * Accepting never submits the form: it stages the value into the fields the
 * user is already editing, so what they see is what they are about to save and
 * they keep the chance to change their mind.
 */
export function MetadataSuggestions({ comicId, onAccept }) {
  const [suggestions, setSuggestions] = useState([]);
  const [candidates, setCandidates] = useState(null);
  const [accepted, setAccepted] = useState([]);
  const [isSearching, setIsSearching] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (!comicId) return undefined;

    let cancelled = false;
    api.get(`/api/comics/${comicId}/metadata-suggestions`)
      .then((result) => { if (!cancelled) setSuggestions(result.suggestions ?? []); })
      .catch(() => { /* Suggestions are an extra; failing to load one is not an error worth a toast. */ });

    return () => { cancelled = true; };
  }, [comicId]);

  const lookUp = async () => {
    setIsSearching(true);
    setError(null);
    try {
      const result = await api.get(`/api/comics/${comicId}/metadata-candidates`);
      setCandidates(result.candidates ?? []);
    } catch (loadError) {
      setError(loadError.message || "Could not reach the metadata providers.");
    } finally {
      setIsSearching(false);
    }
  };

  const accept = (suggestion) => {
    onAccept({ [suggestion.field]: suggestion.suggested });
    setAccepted((current) => [...current, `${suggestion.source}:${suggestion.field}`]);
  };

  const isAccepted = (suggestion) => accepted.includes(`${suggestion.source}:${suggestion.field}`);

  const renderSuggestion = (suggestion, key) => (
    <div key={key} className="flex items-start justify-between gap-3 rounded-md border px-3 py-2 text-sm">
      <div className="min-w-0">
        <p className="font-medium">{FIELD_LABELS[suggestion.field] ?? suggestion.field}</p>
        <p className="text-xs text-muted-foreground">
          {summarise(suggestion.current)} → <span className="text-foreground">{summarise(suggestion.suggested)}</span>
          {" · "}{SOURCE_LABELS[suggestion.source] ?? suggestion.source}
        </p>
      </div>
      <Button
        type="button"
        variant={isAccepted(suggestion) ? "ghost" : "outline"}
        size="sm"
        disabled={isAccepted(suggestion)}
        onClick={() => accept(suggestion)}
        aria-label={isAccepted(suggestion)
          ? `${FIELD_LABELS[suggestion.field] ?? suggestion.field} added`
          : `Use ${FIELD_LABELS[suggestion.field] ?? suggestion.field} ${suggestion.suggested}`}
      >
        {isAccepted(suggestion) ? <><Check className="mr-1 h-3 w-3" /> Added</> : "Use"}
      </Button>
    </div>
  );

  const hasAnything = suggestions.length > 0 || candidates !== null;
  if (!comicId) return null;

  return (
    <div className="space-y-3 rounded-md bg-muted/40 p-3">
      <div className="flex items-center justify-between gap-2">
        <p className="flex items-center gap-1.5 text-sm font-medium">
          <Sparkles className="h-4 w-4" /> Suggestions
        </p>
        <Button type="button" variant="outline" size="sm" disabled={isSearching} onClick={lookUp}>
          <Search className="mr-1 h-3 w-3" />
          {isSearching ? "Searching…" : "Search providers"}
        </Button>
      </div>

      <p className="text-xs text-muted-foreground">
        Nothing here changes the comic until you use it, and nothing is saved until you save.
      </p>

      {suggestions.map((suggestion, index) => renderSuggestion(suggestion, `local-${index}`))}

      {candidates !== null && candidates.length === 0 && (
        <p className="text-xs text-muted-foreground">
          No provider matches. Providers may not be configured — an administrator sets those up under Admin → Metadata.
        </p>
      )}

      {(candidates ?? []).map(({ candidate, suggestions: fields }) => (
        <div key={`${candidate.provider}-${candidate.externalId}`} className="space-y-2 rounded-md border p-2">
          <p className="text-sm font-medium">
            {candidate.series}
            {candidate.issueNumber ? ` #${candidate.issueNumber}` : ""}
            {candidate.title ? ` — ${candidate.title}` : ""}
          </p>
          {fields.length === 0
            ? <p className="text-xs text-muted-foreground">Matches what you already have.</p>
            : fields.map((field, index) => renderSuggestion(field, `${candidate.provider}-${candidate.externalId}-${index}`))}
        </div>
      ))}

      {error && <p className="text-xs text-destructive">{error}</p>}

      {!hasAnything && !error && (
        <p className="text-xs text-muted-foreground">Nothing to suggest from this comic's file or name.</p>
      )}
    </div>
  );
}
