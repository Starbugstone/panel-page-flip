import { useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Edit, Eye, FolderInput, Tags, Trash2 } from "lucide-react";
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from "@/components/ui/alert-dialog";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Progress } from "@/components/ui/progress";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { cn } from "@/lib/utils";
import { getComicProgressState } from "@/lib/comic-progress";
import { TagBadge } from "@/components/TagBadge";
import { TagCombobox } from "@/components/TagCombobox";
import { useTags } from "@/hooks/use-tags.jsx";
import { describeTagSubmission } from "@/lib/tag-suggestions";
import { formatDate } from "@/lib/format";
import { describeBulkShareImpactOfDeletion } from "@/lib/sharing";
import { MoveToFolderDialog } from "@/components/library/MoveToFolderDialog";

/**
 * Owner-only actions are derived from server capabilities. Selection itself is
 * broader because personal folder moves are valid for shared comics too.
 */
const isOwnerActionEligible = (comic) => comic.canEdit !== false && comic.canDelete !== false;

export function ComicTableView({ comics, folders = [], onEditComic, onBulkAddTag, onBulkDelete, onBulkMove }) {
  const { tags: availableTags } = useTags();
  const [selectedIds, setSelectedIds] = useState(() => new Set());
  const [tagName, setTagName] = useState("");
  const [isUpdating, setIsUpdating] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [isMoveDialogOpen, setIsMoveDialogOpen] = useState(false);
  const [orphanedComics, setOrphanedComics] = useState([]);
  const comicIds = useMemo(
    () => comics.map((comic) => comic.id),
    [comics]
  );
  const selectedComicIds = comicIds.filter((comicId) => selectedIds.has(comicId));
  const allSelected = comicIds.length > 0 && selectedComicIds.length === comicIds.length;
  // The selection as the server will see it. `selectedIds` is raw state that
  // outlives the list — a comic can leave `comics` and come back — so every
  // reader derives from this instead, and the checkbox, the counter, the
  // warning and the request cannot describe different sets of comics.
  const selectedComics = useMemo(
    () => comics.filter((comic) => selectedIds.has(comic.id)),
    [comics, selectedIds]
  );
  const bulkShareImpact = useMemo(
    () => describeBulkShareImpactOfDeletion(selectedComics),
    [selectedComics]
  );
  const ownerActionsAllowed = selectedComics.length > 0 && selectedComics.every(isOwnerActionEligible);
  const isChecked = (comic) => selectedIds.has(comic.id);

  const toggleAll = (checked) => {
    setSelectedIds(checked ? new Set(comicIds) : new Set());
  };

  const toggleComic = (comicId, checked) => {
    setSelectedIds((current) => {
      const next = new Set(current);
      checked ? next.add(comicId) : next.delete(comicId);
      return next;
    });
  };

  // Bulk tagging submits the canonical name of an existing tag where one
  // matches, so picking "sci fi" out of the list does not create a second
  // spelling of "Sci Fi".
  const tagSubmission = describeTagSubmission(availableTags, tagName);
  const canAddTag = selectedComicIds.length > 0
    && ownerActionsAllowed
    && !isUpdating
    && (tagSubmission.status === "existing" || tagSubmission.status === "new");

  const addTag = async (name = tagSubmission.name) => {
    if (!name || selectedComicIds.length === 0) return;

    setIsUpdating(true);
    try {
      await onBulkAddTag(selectedComicIds, name);
      setTagName("");
      setSelectedIds(new Set());
    } catch {
      // The dashboard reports the API error and keeps the selection available for retry.
    } finally {
      setIsUpdating(false);
    }
  };

  const deleteSelected = async (confirmOrphaned = false) => {
    setIsUpdating(true);
    try {
      await onBulkDelete(selectedComicIds, { confirmOrphaned });
      setSelectedIds(new Set());
      setOrphanedComics([]);
      setIsDeleteDialogOpen(false);
    } catch (error) {
      if (error.data?.code === "orphaned_comics_confirmation_required") {
        setOrphanedComics(error.data.orphanedComics || []);
      }
      // Other API errors are reported by the dashboard; keep the dialog open for retry.
    } finally {
      setIsUpdating(false);
    }
  };

  const moveSelected = async (folderId) => {
    await onBulkMove(selectedComicIds, folderId);
    setSelectedIds(new Set());
  };

  const folderNames = useMemo(() => new Map(folders.map((folder) => [Number(folder.id), folder.name])), [folders]);

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 rounded-lg border bg-card p-4 lg:flex-row lg:items-center lg:justify-between">
        <p className="text-sm font-medium" aria-live="polite">
          {selectedComicIds.length} of {comicIds.length} selected
          {selectedComics.length > 0 && !ownerActionsAllowed && (
            <span className="ml-1 font-normal text-muted-foreground">
              (Move is available; tagging and deletion require owned comics only)
            </span>
          )}
        </p>
        <div className="flex flex-col gap-2 sm:flex-row">
          <Button
            variant="outline"
            onClick={() => setIsMoveDialogOpen(true)}
            disabled={selectedComicIds.length === 0 || isUpdating || !onBulkMove}
          >
            <FolderInput className="mr-2 h-4 w-4" />
            Move selected
          </Button>
          <TagCombobox
            value={tagName}
            onChange={setTagName}
            onSubmit={(name) => addTag(name)}
            disabled={selectedComicIds.length === 0 || isUpdating || !ownerActionsAllowed}
            placeholder="Tag selected comics"
            label="Tag selected comics"
            className="sm:w-56"
          />
          <Button
            variant="secondary"
            onClick={() => addTag()}
            disabled={!canAddTag}
          >
            <Tags className="mr-2 h-4 w-4" />
            Add tag
          </Button>
          <Button
            variant="destructive"
            onClick={() => setIsDeleteDialogOpen(true)}
            disabled={selectedComicIds.length === 0 || isUpdating || !ownerActionsAllowed}
          >
            <Trash2 className="mr-2 h-4 w-4" />
            Delete selected
          </Button>
        </div>
      </div>

      <div className="rounded-lg border">
        <Table className="min-w-[920px]">
          <TableHeader>
            <TableRow>
              <TableHead className="w-12">
                <Checkbox
                  checked={allSelected ? true : selectedComicIds.length > 0 ? "indeterminate" : false}
                  onCheckedChange={toggleAll}
                  aria-label="Select all comics"
                />
              </TableHead>
              <TableHead>Comic</TableHead>
              <TableHead>Author</TableHead>
              <TableHead>Tags</TableHead>
              <TableHead>Location</TableHead>
              <TableHead className="w-64">Progress</TableHead>
              <TableHead>Uploaded</TableHead>
              <TableHead className="text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {comics.map((comic) => {
              const progress = getComicProgressState(comic);
              return (
                <TableRow key={comic.id} className={cn(progress.rowClass, isChecked(comic) && "ring-1 ring-inset ring-primary")}>
                  <TableCell>
                    <Checkbox
                      checked={isChecked(comic)}
                      onCheckedChange={(checked) => toggleComic(comic.id, checked)}
                      aria-label={`Select ${comic.title}`}
                    />
                  </TableCell>
                  <TableCell>
                    <Link to={`/read/${comic.id}`} className="font-medium hover:underline">
                      {comic.title}
                    </Link>
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
                      {(comic.tags || []).slice(0, 3).map((tag) => <TagBadge key={tag} tag={tag} hideFromLibrary={comic.hiddenTagNames?.includes(tag)} />)}
                      {(comic.tags || []).length > 3 && <Badge variant="outline">+{comic.tags.length - 3}</Badge>}
                    </div>
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {comic.libraryFolderId == null ? "My Library" : folderNames.get(Number(comic.libraryFolderId)) || "Unknown folder"}
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
                    {/* Editing belongs to the owner; a recipient reads and
                        nothing more. */}
                    {comic.canEdit !== false && (
                      <Button variant="ghost" size="icon" onClick={() => onEditComic(comic)} aria-label={`Edit ${comic.title}`}>
                        <Edit className="h-4 w-4" />
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </div>

      <AlertDialog
        open={isDeleteDialogOpen}
        onOpenChange={(open) => {
          setIsDeleteDialogOpen(open);
          if (!open) setOrphanedComics([]);
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {orphanedComics.length > 0
                ? `Delete ${orphanedComics.length} orphaned comic ${orphanedComics.length === 1 ? "record" : "records"}?`
                : `Delete ${selectedComicIds.length} selected ${selectedComicIds.length === 1 ? "comic" : "comics"}?`}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {orphanedComics.length > 0 ? (
                <span className="space-y-2">
                  <span className="block font-medium text-destructive">
                    The source file{orphanedComics.length === 1 ? " is" : "s are"} no longer present.
                  </span>
                  <span className="block">
                    {orphanedComics.map((comic) => comic.title).join(", ")}. Only the orphaned library record{orphanedComics.length === 1 ? "" : "s"} can be removed.
                  </span>
                </span>
              ) : (
                <span className="space-y-2">
                  <span className="block">
                    They will be removed from your library and their existing files moved to recoverable quarantine storage.
                  </span>
                  {/* A deletion that also cuts other people off is a bigger
                      decision than one that does not, and has to say so. */}
                  {bulkShareImpact && (
                    <span className="block font-medium text-destructive">{bulkShareImpact}</span>
                  )}
                </span>
              )}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={isUpdating}>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={(event) => { event.preventDefault(); deleteSelected(orphanedComics.length > 0); }} disabled={isUpdating} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
              {isUpdating ? "Deleting..." : orphanedComics.length === 1 ? "Delete orphaned record" : orphanedComics.length > 1 ? "Delete orphaned records" : "Delete selected"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
      <MoveToFolderDialog
        open={isMoveDialogOpen}
        onOpenChange={setIsMoveDialogOpen}
        folders={folders}
        currentFolderId={null}
        itemCount={selectedComicIds.length}
        onMove={moveSelected}
      />
    </div>
  );
}
