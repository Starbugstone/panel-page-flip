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

export function ComicTableView({ comics, onEditComic, onBulkAddTag, onBulkDelete }) {
  const [selectedIds, setSelectedIds] = useState(() => new Set());
  const [tagName, setTagName] = useState("");
  const [isUpdating, setIsUpdating] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
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

  const deleteSelected = async () => {
    setIsUpdating(true);
    try {
      await onBulkDelete(selectedComicIds);
      setSelectedIds(new Set());
      setIsDeleteDialogOpen(false);
    } catch {
      // The dashboard reports the API error and keeps the confirmation open for retry.
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
                  <TableCell>{new Intl.DateTimeFormat(undefined, { dateStyle: "medium" }).format(new Date(comic.uploadedAt))}</TableCell>
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

      <AlertDialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete {selectedComicIds.length} selected comic(s)?</AlertDialogTitle>
            <AlertDialogDescription>
              They will be removed from your library and their files moved to recoverable quarantine storage.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={isUpdating}>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={(event) => { event.preventDefault(); deleteSelected(); }} disabled={isUpdating} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
              {isUpdating ? "Deleting..." : "Delete selected"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
