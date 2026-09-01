import { Folder, Tag as TagIcon, Users } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { CardContent } from "@/components/ui/card";
import { TagBadge } from "@/components/TagBadge";

const VISIBLE_TAGS = 3;

/** Title, author, where it lives, and who else can see it. */
export function ComicCardDetails({ comic, locationName }) {
  const tags = comic.tags || [];

  return (
    <CardContent className="p-4">
      <h3 className="break-words whitespace-normal font-bold">{comic.title}</h3>
      {comic.autoRenameOriginalTitle && (
        <p className="break-words text-xs text-muted-foreground">Was {comic.autoRenameOriginalTitle}</p>
      )}
      <p className="truncate text-sm text-muted-foreground">{comic.author}</p>
      {locationName && (
        <p className="mt-1 flex items-center gap-1 truncate text-xs text-muted-foreground">
          <Folder className="h-3 w-3 shrink-0" /> {locationName}
        </p>
      )}

      {comic.sharedWithCount > 0 && (
        <p className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
          <Users size={12} />
          Shared with {comic.sharedWithCount} {comic.sharedWithCount === 1 ? "person" : "people"}
        </p>
      )}

      {tags.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1">
          {tags.slice(0, VISIBLE_TAGS).map((tag) => (
            <TagBadge
              key={tag}
              tag={tag}
              hideFromLibrary={comic.hiddenTagNames?.includes(tag)}
              className="flex items-center gap-1 text-xs"
            >
              <TagIcon size={10} />
              {tag}
            </TagBadge>
          ))}
          {tags.length > VISIBLE_TAGS && (
            <Badge variant="outline" className="text-xs">+{tags.length - VISIBLE_TAGS}</Badge>
          )}
        </div>
      )}
    </CardContent>
  );
}
