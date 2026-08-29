import { Button } from "@/components/ui/button";
import { MetadataCandidateCard } from "@/components/metadata/MetadataCandidateCard";
import { MetadataFieldSuggestion } from "@/components/metadata/MetadataFieldSuggestion";
import { MetadataProviderNotices } from "@/components/metadata/MetadataProviderNotices";
import { MetadataSearchBar } from "@/components/metadata/MetadataSearchBar";
import { MetadataTagSuggestions } from "@/components/metadata/MetadataTagSuggestions";
import { useMetadataSuggestions } from "@/hooks/use-metadata-suggestions";
import { listClassification, partitionTagSuggestions } from "@/lib/metadata-suggestions";
import { ChevronRight } from "lucide-react";

/**
 * Proposals about a comic, and a way to take them one at a time.
 *
 * Accepting never submits the form: it stages the value into the fields the
 * user is already editing, so what they see is what they are about to save and
 * they keep the chance to change their mind.
 *
 * This component arranges the panel and nothing else — what a provider is
 * asked, and what accepting does, live in `useMetadataSuggestions`.
 */
export function MetadataSuggestions({ comicId, onAccept, onAddTag, currentTags = [], staged = {}, metadataOrigin = null }) {
  const panel = useMetadataSuggestions({ comicId, onAccept, staged });

  const renderField = (suggestion, key) => (
    <MetadataFieldSuggestion
      key={key}
      suggestion={suggestion}
      accepted={panel.isAccepted(suggestion)}
      onAccept={() => panel.accept(suggestion)}
    />
  );

  const { unused, libraryTags, genreTags } = partitionTagSuggestions(panel.tagSuggestions, currentTags);
  const structuredClassification = listClassification(panel.classification);
  const hasAnything = panel.suggestions.length > 0 || unused.length > 0 || panel.candidates !== null;

  if (!comicId) return null;

  return (
    <div className="space-y-3 rounded-md bg-muted/40 p-3">
      <MetadataSearchBar
        providers={panel.providers}
        chosen={panel.chosen}
        onProviderChange={panel.setProvider}
        busy={panel.busy}
        canRefresh={Boolean(metadataOrigin?.provider)}
        onRefresh={panel.refresh}
        onSearch={panel.lookUp}
      />

      <p className="text-xs text-muted-foreground">
        Nothing here changes the comic until you use it, and nothing is saved until you save.
      </p>

      {panel.suggestions.map((suggestion, index) => renderField(suggestion, `local-${index}`))}

      <MetadataTagSuggestions libraryTags={libraryTags} genreTags={genreTags} onAddTag={onAddTag} />

      {/* Read-only. These are what the comic is about, not how it is filed. */}
      {structuredClassification.length > 0 && (
        <div className="space-y-1 rounded-md border px-3 py-2 text-xs text-muted-foreground">
          {structuredClassification.map(([label, values]) => (
            <p key={label}><span className="font-medium text-foreground">{label}:</span> {values.join(", ")}</p>
          ))}
        </div>
      )}

      {panel.openRecord?.candidate && (
        <MetadataCandidateCard
          candidate={panel.openRecord.candidate}
          fields={panel.openRecord.suggestions ?? []}
          fieldKey={`record-${panel.openRecord.key}`}
          renderField={renderField}
          onAcceptAll={() => panel.acceptRecord(panel.openRecord.candidate, panel.openRecord.suggestions ?? [])}
        />
      )}

      {(panel.candidates ?? []).map(({ candidate, suggestions: fields }) => (
        <MetadataCandidateCard
          key={`${candidate.provider}-${candidate.externalId}`}
          candidate={candidate}
          fields={fields}
          fieldKey={`${candidate.provider}-${candidate.externalId}`}
          renderField={renderField}
          onAcceptAll={() => panel.acceptRecord(candidate, fields)}
          showCover
          actions={(
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-7"
              disabled={panel.busy !== null}
              onClick={() => panel.openFullRecord(candidate)}
            >
              <ChevronRight className="mr-1 h-3 w-3" />
              {panel.busy === `record:${candidate.provider}:${candidate.externalId}` ? "Loading…" : "Show everything"}
            </Button>
          )}
        />
      ))}

      <MetadataProviderNotices
        providers={panel.providers}
        unavailable={panel.unavailable}
        providerResults={panel.providerResults}
        candidates={panel.candidates}
        searchedProvider={panel.searchedProvider}
      />

      {panel.error && <p className="text-xs text-destructive">{panel.error}</p>}

      {!hasAnything && !panel.error && (
        <p className="text-xs text-muted-foreground">Nothing to suggest from this comic's file or name.</p>
      )}
    </div>
  );
}
