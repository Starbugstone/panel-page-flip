import { useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Edit, Eye, Tags, Trash2 } from "lucide-react";
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from "@/components/ui/alert-dialog";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Progress } from "@/components/ui/progress";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { cn } from "@/lib/utils";
import { getComicProgressState } from "@/lib/comic-progress";
import { formatDate } from "@/lib/format";

export function ComicTableView({ comics, onEditComic, onBulkAddTag, onBulkDelete }) {
  const [selectedIds, setSelectedIds] = useState(() => new Set());
  const [tagName, setTagName] = useState("");
  const [isUpdating, setIsUpdating] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [orphanedComics, setOrphanedComics] = useState([]);
  const comicIds = useMemo(() => comics.map((comic) => comic.id), [comics]);
  const selectedComicIds = comicIds.filter((comicId) => selectedIds.has(comicId));
  const allSelected = comicIds.length > 0 && selectedComicIds.length === comicIds.length;

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

  const addTag = async () => {
    const trimmedTag = tagName.trim();
    if (!trimmedTag || selectedComicIds.length === 0) return;

    setIsUpdating(true);
    try {
      await onBulkAddTag(selectedComicIds, trimmedTag);
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

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 rounded-lg border bg-card p-4 lg:flex-row lg:items-center lg:justify-between">
        <p className="text-sm font-medium" aria-live="polite">
          {selectedComicIds.length} of {comics.length} selected
        </p>
        <div className="flex flex-col gap-2 sm:flex-row">
          <Input
            value={tagName}
            onChange={(event) => setTagName(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === "Enter") {
                event.preventDefault();
                addTag();
              }
            }}
            maxLength={50}
            placeholder="Tag selected comics"
            aria-label="Tag selected comics"
            disabled={selectedComicIds.length === 0 || isUpdating}
            className="sm:w-56"
          />
          <Button
            variant="secondary"
            onClick={addTag}
            disabled={selectedComicIds.length === 0 || !tagName.trim() || isUpdating}
          >
            <Tags className="mr-2 h-4 w-4" />
            Add tag
          </Button>
          <Button
            variant="destructive"
            onClick={() => setIsDeleteDialogOpen(true)}
            disabled={selectedComicIds.length === 0 || isUpdating}
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
              <TableHead className="w-64">Progress</TableHead>
              <TableHead>Uploaded</TableHead>
              <TableHead className="text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {comics.map((comic) => {
              const progress = getComicProgressState(comic);
              return (
                <TableRow key={comic.id} className={cn(progress.rowClass, selectedIds.has(comic.id) && "ring-1 ring-inset ring-primary")}>
                  <TableCell>
                    <Checkbox
                      checked={selectedIds.has(comic.id)}
                      onCheckedChange={(checked) => toggleComic(comic.id, checked)}
                      aria-label={`Select ${comic.title}`}
                    />
                  </TableCell>
                  <TableCell>
                    <Link to={`/read/${comic.id}`} className="font-medium hover:underline">
                      {comic.title}
                    </Link>
                    {comic.publisher && <p className="text-xs text-muted-foreground">{comic.publisher}</p>}
                  </TableCell>
                  <TableCell>{comic.author || "—"}</TableCell>
                  <TableCell>
                    <div className="flex max-w-52 flex-wrap gap-1">
                      {(comic.tags || []).slice(0, 3).map((tag) => <Badge key={tag} variant="outline">{tag}</Badge>)}
                      {(comic.tags || []).length > 3 && <Badge variant="outline">+{comic.tags.length - 3}</Badge>}
                    </div>
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
                    <Button variant="ghost" size="icon" onClick={() => onEditComic(comic)} aria-label={`Edit ${comic.title}`}>
                      <Edit className="h-4 w-4" />
                    </Button>
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
                    The CBZ file{orphanedComics.length === 1 ? " is" : "s are"} no longer present.
                  </span>
                  <span className="block">
                    {orphanedComics.map((comic) => comic.title).join(", ")}. Only the orphaned library record{orphanedComics.length === 1 ? "" : "s"} can be removed.
                  </span>
                </span>
              ) : (
                "They will be removed from your library and their existing files moved to recoverable quarantine storage."
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
    </div>
  );
}
