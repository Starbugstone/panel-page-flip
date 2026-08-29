import { Link } from "react-router-dom";
import { Edit, Eye } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Progress } from "@/components/ui/progress";
import { TableCell, TableRow } from "@/components/ui/table";
import { TagBadge } from "@/components/TagBadge";
import { getComicProgressState } from "@/lib/comic-progress";
import { formatDate } from "@/lib/format";
import { cn } from "@/lib/utils";

const VISIBLE_TAGS = 3;

/** One comic in the table, with its reading state and the two things a row can do. */
export function ComicTableRow({ comic, checked, folderNames, onToggle, onEdit }) {
  const progress = getComicProgressState(comic);
  const tags = comic.tags || [];

  return (
    <TableRow className={cn(progress.rowClass, checked && "ring-1 ring-inset ring-primary")}>
      <TableCell>
        <Checkbox
          checked={checked}
          onCheckedChange={(next) => onToggle(comic.id, next)}
          aria-label={`Select ${comic.title}`}
        />
      </TableCell>
      <TableCell>
        <Link to={`/read/${comic.id}`} className="font-medium hover:underline">{comic.title}</Link>
        {comic.isShared && (
          <Badge variant="secondary" className="ml-2 align-middle text-xs">
            Shared by {comic.sharedBy?.name || "another user"}
          </Badge>
        )}
        {comic.publisher && <p className="text-xs text-muted-foreground">{comic.publisher}</p>}
      </TableCell>
      <TableCell>{comic.author || "—"}</TableCell>
      <TableCell>
        <div className="flex max-w-52 flex-wrap gap-1">
          {tags.slice(0, VISIBLE_TAGS).map((tag) => (
            <TagBadge key={tag} tag={tag} hideFromLibrary={comic.hiddenTagNames?.includes(tag)} />
          ))}
          {tags.length > VISIBLE_TAGS && <Badge variant="outline">+{tags.length - VISIBLE_TAGS}</Badge>}
        </div>
      </TableCell>
      <TableCell className="text-sm text-muted-foreground">
        {comic.libraryFolderId == null
          ? "My Library"
          : folderNames.get(Number(comic.libraryFolderId)) || "Unknown folder"}
      </TableCell>
      <TableCell>
        <div className="space-y-2">
          <div className="flex items-center justify-between gap-2 text-xs">
            <Badge variant="outline" className={progress.badgeClass}>{progress.label}</Badge>
            <span>{progress.currentPage} / {comic.pageCount || 0}</span>
          </div>
          <Progress value={progress.percent} className={cn("h-2", progress.progressClass)} />
        </div>
      </TableCell>
      <TableCell>{formatDate(comic.uploadedAt)}</TableCell>
      <TableCell className="text-right">
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
