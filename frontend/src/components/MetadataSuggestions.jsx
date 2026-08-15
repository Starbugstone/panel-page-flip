import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { TagBadge } from "@/components/TagBadge";
import { useConfig } from "@/hooks/use-config";
import { Sparkles, Check, Search, Tag as TagIcon, RefreshCw, ChevronDown, ChevronRight } from "lucide-react";

const FIELD_LABELS = {
  title: "Title",
  series: "Series",
  issueNumber: "Issue",
  issueCount: "Issues in series",
  volume: "Volume",
  publisher: "Publisher",
  description: "Description",
  publishedAt: "Published",
  languageCode: "Language",
  ageRating: "Age rating",
  creators: "Credits",
};

const SOURCE_LABELS = {
  comicinfo: "from the file",
  filename: "from the filename",
  provider: "from a provider",
  user: "yours",
};

const CONFIDENCE_LABELS = {
  exact: "Exact match",
  high: "Likely",
  ambiguous: "Possible",
  low: "Unlikely",
};

const CONFIDENCE_STYLES = {
  exact: "bg-emerald-500/15 text-emerald-700 dark:text-emerald-300",
  high: "bg-sky-500/15 text-sky-700 dark:text-sky-300",
  ambiguous: "bg-amber-500/15 text-amber-700 dark:text-amber-300",
  low: "bg-muted text-muted-foreground",
};

const CLASSIFICATION_LABELS = {
  characters: "Characters",
  teams: "Teams",
  locations: "Locations",
  storyArcs: "Story arcs",
};

/** Credits are role → names; everything else is a scalar. */
const summarise = (value) => {
  if (value === null || value === undefined || value === "") return "empty";
  if (Array.isArray(value)) return value.join(", ") || "empty";
  if (typeof value === "object") {
    const text = Object.entries(value)
      .map(([role, names]) => `${role}: ${(names ?? []).join(", ")}`)
      .join(" · ");
    return text.length > 60 ? `${text.slice(0, 60)}…` : text || "empty";
  }
  const text = String(value);
  return text.length > 60 ? `${text.slice(0, 60)}…` : text;
};

/**
 * Proposals about a comic, and a way to take them one at a time.
 *
 * Accepting never submits the form: it stages the value into the fields the
 * user is already editing, so what they see is what they are about to save and
 * they keep the chance to change their mind.
 *
 * The provider search runs off those same staged fields rather than off what is
 * saved, so a filename suggestion accepted a moment ago is usable immediately
 * instead of after a save and a reopen.
 */
export function MetadataSuggestions({ comicId, onAccept, onAddTag, currentTags = [], staged = {}, metadataOrigin = null }) {
  const { config } = useConfig();
  const [suggestions, setSuggestions] = useState([]);
  const [tagSuggestions, setTagSuggestions] = useState([]);
  const [classification, setClassification] = useState(null);
  const [candidates, setCandidates] = useState(null);
  const [providerResults, setProviderResults] = useState([]);
  const [searchedProvider, setSearchedProvider] = useState(null);
  const [openRecord, setOpenRecord] = useState(null);
  const [accepted, setAccepted] = useState([]);
  const [busy, setBusy] = useState(null);
  const [error, setError] = useState(null);
  const [showAllTags, setShowAllTags] = useState(false);
  // One provider at a time by default: a search spends somebody's quota, and
  // asking all of them to answer the same question spends all of them.
  const [provider, setProvider] = useState(null);

  // Which providers would actually answer this user. A provider that is off or
  // has no credential is still listed, with the reason, because "no results"
  // and "nobody was asked" call for different things from the person reading.
  const providers = (config?.metadataProviders ?? []).filter((entry) => entry.available !== false);
  const unavailable = (config?.metadataProviders ?? []).filter((entry) => entry.available === false);
  const chosen = provider ?? providers[0]?.key ?? null;

  useEffect(() => {
    if (!comicId) return undefined;

    let cancelled = false;
    api.get(`/api/comics/${comicId}/metadata-suggestions`)
      .then((result) => {
        if (cancelled) return;
        setSuggestions(result.suggestions ?? []);
        setTagSuggestions(result.tags ?? []);
        setClassification(result.classification ?? null);
      })
      .catch(() => { /* Suggestions are an extra; failing to load one is not an error worth a toast. */ });

    return () => { cancelled = true; };
  }, [comicId]);

  const lookUp = async () => {
    setBusy("search");
    setError(null);
    try {
      const result = await api.post(`/api/comics/${comicId}/metadata-candidates`, {
        provider: chosen,
        // The values in front of the user, not the ones last saved.
        query: {
          series: staged.series || null,
          issueNumber: staged.issueNumber || null,
          year: staged.publishedAt ? Number(String(staged.publishedAt).slice(0, 4)) : null,
          volume: staged.volume || null,
        },
      });
      setCandidates(result.candidates ?? []);
      setProviderResults(result.providers ?? []);
      setSearchedProvider(result.searched ?? null);
      setOpenRecord(null);
    } catch (loadError) {
      setError(loadError.message || "Could not reach the metadata providers.");
    } finally {
      setBusy(null);
    }
  };

  /**
   * The full record behind a candidate.
   *
   * A search row carries a fraction of what a provider knows — Metron's issue
   * list has no publisher, description or genres at all — so the rest is
   * fetched only for the one somebody picked.
   */
  const openFullRecord = async (candidate) => {
    setBusy(`record:${candidate.provider}:${candidate.externalId}`);
    setError(null);
    try {
      const result = await api.post(`/api/comics/${comicId}/metadata-record`, {
        provider: candidate.provider,
        externalId: candidate.externalId,
      });
      setOpenRecord({ key: `${candidate.provider}-${candidate.externalId}`, ...result });
      if (result.tags) setTagSuggestions(result.tags);
    } catch (loadError) {
      setError(loadError.message || "That record could not be read.");
    } finally {
      setBusy(null);
    }
  };

  /** Ask again about the record this comic was already matched to. */
  const refresh = async () => {
    setBusy("refresh");
    setError(null);
    try {
      const result = await api.post(`/api/comics/${comicId}/metadata-refresh`, {});
      setOpenRecord({ key: "refresh", ...result });
      if (result.tags) setTagSuggestions(result.tags);
    } catch (loadError) {
      setError(loadError.message || "That record could not be refreshed.");
    } finally {
      setBusy(null);
    }
  };

  const accept = (suggestion) => {
    onAccept({ [suggestion.field]: suggestion.suggested });
    setAccepted((current) => [...current, `${suggestion.source}:${suggestion.field}`]);
  };

  /**
   * Remember which record was chosen, so a later refresh can ask for that exact
   * issue rather than repeating a fuzzy search and hoping for the same answer.
   */
  const acceptRecord = (candidate, fields) => {
    onAccept({
      ...Object.fromEntries(fields.map((field) => [field.field, field.suggested])),
      metadataProvider: candidate.provider,
      metadataExternalId: candidate.externalId,
    });
    setAccepted((current) => [...current, ...fields.map((field) => `${field.source}:${field.field}`)]);
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
          : `Use ${FIELD_LABELS[suggestion.field] ?? suggestion.field} ${summarise(suggestion.suggested)}`}
      >
        {isAccepted(suggestion) ? <><Check className="mr-1 h-3 w-3" /> Added</> : "Use"}
      </Button>
    </div>
  );

  const renderRecord = (candidate, fields, key) => (
    <div key={key} className="space-y-2 rounded-md border p-2">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="text-sm font-medium">
            {candidate.series}
            {candidate.issueNumber ? ` #${candidate.issueNumber}` : ""}
            {candidate.title ? ` — ${candidate.title}` : ""}
          </p>
          <p className="text-xs text-muted-foreground">
            {[candidate.publisher, candidate.publishedAt, candidate.provider].filter(Boolean).join(" · ")}
          </p>
        </div>
        <span className={`shrink-0 rounded px-1.5 py-0.5 text-[11px] ${CONFIDENCE_STYLES[candidate.confidence] ?? CONFIDENCE_STYLES.low}`}>
          {CONFIDENCE_LABELS[candidate.confidence] ?? candidate.confidence}
        </span>
      </div>

      {fields.length === 0
        ? <p className="text-xs text-muted-foreground">Matches what you already have.</p>
        : fields.map((field, index) => renderSuggestion(field, `${key}-${index}`))}

      {fields.length > 1 && (
        <Button type="button" variant="secondary" size="sm" className="h-7" onClick={() => acceptRecord(candidate, fields)}>
          Use all {fields.length} fields
        </Button>
      )}
    </div>
  );

  // A tag already on the comic is not a suggestion, and once every proposal has
  // been taken the section has nothing left to say.
  const unusedTags = tagSuggestions.filter(
    (tag) => !currentTags.some((name) => name.toLowerCase() === tag.name.toLowerCase())
  );
  const libraryTags = unusedTags.filter((tag) => tag.kind !== "genre");
  const genreTags = unusedTags.filter((tag) => tag.kind === "genre");
  // Collapsed once there are enough of them to be a wall rather than a hint.
  const visibleGenres = showAllTags ? genreTags : genreTags.slice(0, 4);

  const structuredClassification = Object.entries(CLASSIFICATION_LABELS)
    .map(([field, label]) => [label, classification?.[field] ?? []])
    .filter(([, values]) => values.length > 0);

  const hasAnything = suggestions.length > 0 || unusedTags.length > 0 || candidates !== null;
  if (!comicId) return null;

  return (
    <div className="space-y-3 rounded-md bg-muted/40 p-3">
      <div className="flex items-center justify-between gap-2">
        <p className="flex items-center gap-1.5 text-sm font-medium">
          <Sparkles className="h-4 w-4" /> Suggestions
        </p>
        <div className="flex items-center gap-2">
          {/* Only shown when there is a choice to make. One provider is asked
              per search, because each one answering costs its own quota. */}
          {providers.length > 1 && (
            <Select value={chosen ?? undefined} onValueChange={setProvider}>
              <SelectTrigger className="h-8 w-36" aria-label="Provider to search">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {providers.map((p) => (
                  <SelectItem key={p.key} value={p.key}>{p.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
          {metadataOrigin?.provider && (
            <Button type="button" variant="ghost" size="sm" disabled={busy !== null} onClick={refresh}>
              <RefreshCw className="mr-1 h-3 w-3" />
              {busy === "refresh" ? "Refreshing…" : "Refresh"}
            </Button>
          )}
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={busy !== null || providers.length === 0}
            onClick={lookUp}
          >
            <Search className="mr-1 h-3 w-3" />
            {busy === "search" ? "Searching…" : providers.length === 1 ? `Search ${providers[0].label}` : "Search provider"}
          </Button>
        </div>
      </div>

      <p className="text-xs text-muted-foreground">
        Nothing here changes the comic until you use it, and nothing is saved until you save.
      </p>

      {suggestions.map((suggestion, index) => renderSuggestion(suggestion, `local-${index}`))}

      {libraryTags.length > 0 && (
        <div className="space-y-2 rounded-md border px-3 py-2">
          <p className="flex items-center gap-1.5 text-sm font-medium">
            <TagIcon className="h-3.5 w-3.5" /> Tags you already use
          </p>
          <div className="flex flex-wrap gap-2">
            {libraryTags.map((tag) => (
              <Button
                key={tag.name}
                type="button"
                variant="outline"
                size="sm"
                className="h-7"
                onClick={() => onAddTag?.(tag.name)}
                aria-label={`Add tag ${tag.name}`}
                title={`Matches ${tag.matchedField}: ${tag.matchedValue}`}
              >
                <TagBadge tag={tag.name} className="mr-1" />
                <span className="text-xs text-muted-foreground">+ {tag.matchedField}</span>
              </Button>
            ))}
          </div>
        </div>
      )}

      {/* Genres a file or a provider proposed. Nothing is selected by default,
          and accepting one is what turns it into a tag — a metadata lookup must
          never reorganise somebody's library on its own. */}
      {genreTags.length > 0 && (
        <div className="space-y-2 rounded-md border px-3 py-2">
          <p className="flex items-center gap-1.5 text-sm font-medium">
            <TagIcon className="h-3.5 w-3.5" /> Suggested tags
          </p>
          <div className="flex flex-wrap gap-2">
            {visibleGenres.map((tag) => (
              <Button
                key={`${tag.source}-${tag.name}`}
                type="button"
                variant="outline"
                size="sm"
                className="h-7"
                onClick={() => onAddTag?.(tag.name)}
                aria-label={`Add tag ${tag.name}`}
                title={tag.exists
                  ? `Suggested ${SOURCE_LABELS[tag.source] ?? tag.source} — you already have this tag`
                  : `Suggested ${SOURCE_LABELS[tag.source] ?? tag.source} — adding it creates a personal tag`}
              >
                <TagBadge tag={tag.name} className="mr-1" />
                <span className="text-xs text-muted-foreground">{tag.exists ? "+ yours" : "+ new"}</span>
              </Button>
            ))}
          </div>
          {genreTags.length > visibleGenres.length && (
            <Button type="button" variant="ghost" size="sm" className="h-6 px-1 text-xs" onClick={() => setShowAllTags(true)}>
              <ChevronDown className="mr-1 h-3 w-3" /> {genreTags.length - visibleGenres.length} more
            </Button>
          )}
        </div>
      )}

      {/* Read-only. These are what the comic is about, not how it is filed. */}
      {structuredClassification.length > 0 && (
        <div className="space-y-1 rounded-md border px-3 py-2 text-xs text-muted-foreground">
          {structuredClassification.map(([label, values]) => (
            <p key={label}><span className="font-medium text-foreground">{label}:</span> {values.join(", ")}</p>
          ))}
        </div>
      )}

      {openRecord && renderRecord(openRecord.candidate, openRecord.suggestions ?? [], `record-${openRecord.key}`)}

      {candidates !== null && candidates.length === 0 && searchedProvider && (
        <p className="text-xs text-muted-foreground">
          No matches from {providers.find((p) => p.key === searchedProvider)?.label ?? searchedProvider}.
        </p>
      )}

      {(candidates ?? []).map(({ candidate, suggestions: fields }) => (
        <div key={`${candidate.provider}-${candidate.externalId}`} className="space-y-2 rounded-md border p-2">
          <div className="flex items-start justify-between gap-2">
            <div className="flex min-w-0 gap-2">
              {candidate.coverUrl && (
                <img src={candidate.coverUrl} alt="" className="h-14 w-10 shrink-0 rounded object-cover" loading="lazy" />
              )}
              <div className="min-w-0">
                <p className="text-sm font-medium">
                  {candidate.series}
                  {candidate.issueNumber ? ` #${candidate.issueNumber}` : ""}
                  {candidate.title ? ` — ${candidate.title}` : ""}
                </p>
                <p className="text-xs text-muted-foreground">
                  {[candidate.publisher, candidate.publishedAt, candidate.provider].filter(Boolean).join(" · ")}
                </p>
              </div>
            </div>
            <span className={`shrink-0 rounded px-1.5 py-0.5 text-[11px] ${CONFIDENCE_STYLES[candidate.confidence] ?? CONFIDENCE_STYLES.low}`}>
              {CONFIDENCE_LABELS[candidate.confidence] ?? candidate.confidence}
            </span>
          </div>

          {fields.length === 0
            ? <p className="text-xs text-muted-foreground">Matches what you already have.</p>
            : fields.map((field, index) => renderSuggestion(field, `${candidate.provider}-${candidate.externalId}-${index}`))}

          <div className="flex flex-wrap gap-2">
            {fields.length > 1 && (
              <Button type="button" variant="secondary" size="sm" className="h-7" onClick={() => acceptRecord(candidate, fields)}>
                Use all {fields.length} fields
              </Button>
            )}
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-7"
              disabled={busy !== null}
              onClick={() => openFullRecord(candidate)}
            >
              <ChevronRight className="mr-1 h-3 w-3" />
              {busy === `record:${candidate.provider}:${candidate.externalId}` ? "Loading…" : "Show everything"}
            </Button>
          </div>
        </div>
      ))}

      {/* A provider that was not asked says why. An empty result and a switched
          off provider look identical otherwise, and only one is worth acting on. */}
      {providerResults.filter((entry) => entry.status !== "ok").map((entry) => (
        <p key={entry.provider} className="text-xs text-amber-700 dark:text-amber-300">
          {entry.provider}: {entry.message}
        </p>
      ))}

      {providers.length === 0 && (
        <div className="space-y-1">
          <p className="text-xs text-muted-foreground">No metadata provider is available for your account.</p>
          {unavailable.map((entry) => (
            <p key={entry.key} className="text-xs text-muted-foreground">{entry.label}: {entry.message}</p>
          ))}
        </div>
      )}

      {error && <p className="text-xs text-destructive">{error}</p>}

      {!hasAnything && !error && (
        <p className="text-xs text-muted-foreground">Nothing to suggest from this comic's file or name.</p>
      )}
    </div>
  );
}
