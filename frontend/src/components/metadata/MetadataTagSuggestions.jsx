import { useState } from "react";
import { Button } from "@/components/ui/button";
import { TagBadge } from "@/components/TagBadge";
import { sourceLabel, VISIBLE_GENRE_LIMIT } from "@/lib/metadata-suggestions";
import { ChevronDown, Tag as TagIcon } from "lucide-react";

function TagSection({ title, children }) {
  return (
    <div className="space-y-2 rounded-md border px-3 py-2">
      <p className="flex items-center gap-1.5 text-sm font-medium">
        <TagIcon className="h-3.5 w-3.5" /> {title}
      </p>
      <div className="flex flex-wrap gap-2">{children}</div>
    </div>
  );
}

function TagButton({ tag, title, note, onAdd }) {
  return (
    <Button
      type="button"
      variant="outline"
      size="sm"
      className="h-7"
      onClick={() => onAdd?.(tag.name)}
      aria-label={`Add tag ${tag.name}`}
      title={title}
    >
      <TagBadge tag={tag.name} className="mr-1" />
      <span className="text-xs text-muted-foreground">{note}</span>
    </Button>
  );
}

/**
 * Tags worth offering, in two sections that mean different things.
 *
 * Reusing a name the library already has costs nothing. Accepting a genre a
 * file or a provider proposed creates a personal tag, so those are kept apart
 * and nothing is selected by default — a metadata lookup must never
 * reorganise somebody's library on its own.
 */
export function MetadataTagSuggestions({ libraryTags, genreTags, onAddTag }) {
  const [showAll, setShowAll] = useState(false);
  const visibleGenres = showAll ? genreTags : genreTags.slice(0, VISIBLE_GENRE_LIMIT);

  return (
    <>
      {libraryTags.length > 0 && (
        <TagSection title="Tags you already use">
          {libraryTags.map((tag) => (
            <TagButton
              key={tag.name}
              tag={tag}
              title={`Matches ${tag.matchedField}: ${tag.matchedValue}`}
              note={`+ ${tag.matchedField}`}
              onAdd={onAddTag}
            />
          ))}
        </TagSection>
      )}

      {genreTags.length > 0 && (
        <TagSection title="Suggested tags">
          {visibleGenres.map((tag) => (
            <TagButton
              key={`${tag.source}-${tag.name}`}
              tag={tag}
              title={tag.exists
                ? `Suggested ${sourceLabel(tag.source)} — you already have this tag`
                : `Suggested ${sourceLabel(tag.source)} — adding it creates a personal tag`}
              note={tag.exists ? "+ yours" : "+ new"}
              onAdd={onAddTag}
            />
          ))}
          {genreTags.length > visibleGenres.length && (
            <Button type="button" variant="ghost" size="sm" className="h-6 px-1 text-xs" onClick={() => setShowAll(true)}>
              <ChevronDown className="mr-1 h-3 w-3" /> {genreTags.length - visibleGenres.length} more
            </Button>
          )}
        </TagSection>
      )}
    </>
  );
}
