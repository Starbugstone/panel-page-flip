import { Link } from "react-router-dom";
import { Edit, Eye } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import { TableCell, TableRow } from "@/components/ui/table";
import { SelectionCheckbox } from "@/components/SelectionCheckbox";
import { TagBadge } from "@/components/TagBadge";
import { getComicProgressState } from "@/lib/comic-progress";
import { formatDate } from "@/lib/format";
import { cn } from "@/lib/utils";

const VISIBLE_TAGS = 3;

function ComicTags({ tags, hiddenTagNames, className }) {
  if (tags.length === 0) return null;

  return (
    <div className={className}>
      {tags.slice(0, VISIBLE_TAGS).map((tag) => (
        <TagBadge key={tag} tag={tag} hideFromLibrary={hiddenTagNames?.includes(tag)} />
      ))}
      {tags.length > VISIBLE_TAGS && <Badge variant="outline">+{tags.length - VISIBLE_TAGS}</Badge>}
    </div>
  );
}

function ComicMobileDetails({ comic, folderName, progress, tags }) {
  return (
    <>
      <div className="mt-1 flex flex-wrap gap-x-2 gap-y-1 text-xs text-muted-foreground">
        {comic.author && <span className="break-words md:hidden">{comic.author}</span>}
        <span className="break-words xl:hidden">{folderName}</span>
        {comic.uploadedAt && <span className="xl:hidden">{formatDate(comic.uploadedAt)}</span>}
      </div>
      <ComicTags
        tags={tags}
        hiddenTagNames={comic.hiddenTagNames}
        className="mt-2 flex max-w-full flex-wrap gap-1 xl:hidden"
      />
      <div className="mt-2 flex items-center justify-between gap-2 text-xs sm:hidden">
        <Badge variant="outline" className={cn("sm:hidden", progress.badgeClass)}>{progress.label}</Badge>
        <span>{progress.currentPage} / {comic.pageCount || 0}</span>
      </div>
      <Progress value={progress.percent} className={cn("mt-2 h-2 sm:hidden", progress.progressClass)} />
    </>
  );
}

/** One comic in the table, with its reading state and the two things a row can do. */
export function ComicTableRow({ comic, checked, folderNames, onToggle, onEdit }) {
  const progress = getComicProgressState(comic);
  const tags = comic.tags || [];
  const folderName = comic.libraryFolderId == null
    ? "My Library"
    : folderNames.get(Number(comic.libraryFolderId)) || "Unknown folder";

  return (
    <TableRow className={cn(progress.rowClass, checked && "ring-1 ring-inset ring-primary")}>
      <TableCell className="w-10 px-2 sm:w-12 sm:px-4">
        <SelectionCheckbox
          checked={checked}
          onToggle={(next, options) => onToggle(comic.id, next, options)}
          label={`Select ${comic.title}`}
        />
      </TableCell>
      <TableCell className="min-w-0 px-2 sm:px-4">
        <Link to={`/read/${comic.id}`} className="block break-words font-medium hover:underline">{comic.title}</Link>
        {comic.autoRenameOriginalTitle && (
          <p className="break-words text-xs text-muted-foreground">Was {comic.autoRenameOriginalTitle}</p>
        )}
        {comic.isShared && (
          <Badge variant="secondary" className="ml-2 align-middle text-xs">
            Shared by {comic.sharedBy?.name || "another user"}
          </Badge>
        )}
        {comic.publisher && <p className="text-xs text-muted-foreground">{comic.publisher}</p>}
        <ComicMobileDetails comic={comic} folderName={folderName} progress={progress} tags={tags} />
      </TableCell>
      <TableCell className="hidden md:table-cell">{comic.author || "—"}</TableCell>
      <TableCell className="hidden xl:table-cell">
        <ComicTags tags={tags} hiddenTagNames={comic.hiddenTagNames} className="flex max-w-52 flex-wrap gap-1" />
      </TableCell>
      <TableCell className="hidden text-sm text-muted-foreground xl:table-cell">
        {folderName}
      </TableCell>
      <TableCell className="hidden w-48 sm:table-cell xl:w-64">
        <div className="space-y-2">
          <div className="flex items-center justify-between gap-2 text-xs">
            <Badge variant="outline" className={progress.badgeClass}>{progress.label}</Badge>
            <span>{progress.currentPage} / {comic.pageCount || 0}</span>
          </div>
          <Progress value={progress.percent} className={cn("h-2", progress.progressClass)} />
        </div>
      </TableCell>
      <TableCell className="hidden xl:table-cell">{formatDate(comic.uploadedAt)}</TableCell>
      <TableCell className="w-20 px-1 text-right sm:w-auto sm:px-4">
        <Button variant="ghost" size="icon" asChild>
          <Link to={`/read/${comic.id}`} aria-label={`Read ${comic.title}`}><Eye className="h-4 w-4" /></Link>
        </Button>
        {/* Editing belongs to the owner; a recipient reads and nothing more. */}
        {comic.canEdit !== false && (
          <Button variant="ghost" size="icon" onClick={() => onEdit(comic)} aria-label={`Edit ${comic.title}`}>
            <Edit className="h-4 w-4" />
          </Button>
        )}
      </TableCell>
    </TableRow>
  );
}
