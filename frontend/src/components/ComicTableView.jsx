import { useMemo, useState } from "react";
import { SelectAllCheckbox } from "@/components/SelectionCheckbox";
import { Table, TableBody, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { ComicBulkActionsBar } from "@/components/library/ComicBulkActionsBar";
import { ComicBulkDeleteDialog } from "@/components/library/ComicBulkDeleteDialog";
import { ComicTableRow } from "@/components/library/ComicTableRow";
import { MoveToFolderDialog } from "@/components/library/MoveToFolderDialog";
import { useComicSelection } from "@/hooks/use-comic-selection";

const COLUMNS = ["Comic", "Author", "Tags", "Location", "Progress", "Uploaded", "Actions"];

/**
 * The library as rows, with the bulk operations that only a table makes
 * practical: tag, move, share or delete a whole selection at once.
 */
export function ComicTableView({ comics, folders = [], onEditComic, onBulkAddTag, onBulkDelete, onBulkMove, onShareSelected }) {
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [isMoveDialogOpen, setIsMoveDialogOpen] = useState(false);

  const selection = useComicSelection({
    comics,
    onBulkAddTag,
    onBulkDelete,
    onBulkMove,
    canShare: Boolean(onShareSelected),
  });

  const folderNames = useMemo(
    () => new Map(folders.map((folder) => [Number(folder.id), folder.name])),
    [folders]
  );

  const selectedCount = selection.selectedComicIds.length;

  return (
    <div className="space-y-4">
      <ComicBulkActionsBar
        selection={selection}
        totalCount={comics.length}
        canMove={Boolean(onBulkMove)}
        onShare={() => onShareSelected(selection.selectedComicIds)}
        onMove={() => setIsMoveDialogOpen(true)}
        onDelete={() => setIsDeleteDialogOpen(true)}
      />

      <div className="rounded-lg border">
        <Table className="min-w-[920px]">
          <TableHeader>
            <TableRow>
              <TableHead className="w-12">
                <SelectAllCheckbox
                  state={selection.headerState}
                  onToggleAll={selection.toggleAll}
                  label="Select all comics"
                />
              </TableHead>
              {COLUMNS.map((column) => (
                <TableHead
                  key={column}
                  className={column === "Progress" ? "w-64" : column === "Actions" ? "text-right" : undefined}
                >
                  {column}
                </TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            {comics.map((comic) => (
              <ComicTableRow
                key={comic.id}
                comic={comic}
                checked={selection.isChecked(comic)}
                folderNames={folderNames}
                onToggle={selection.toggle}
                onEdit={onEditComic}
              />
            ))}
          </TableBody>
        </Table>
      </div>

      <ComicBulkDeleteDialog
        open={isDeleteDialogOpen}
        onOpenChange={(open) => {
          setIsDeleteDialogOpen(open);
          if (!open) selection.forgetOrphans();
        }}
        selection={selection}
        onConfirm={async (confirmOrphaned) => {
          if (await selection.deleteSelected(confirmOrphaned)) setIsDeleteDialogOpen(false);
        }}
      />

      <MoveToFolderDialog
        open={isMoveDialogOpen}
        onOpenChange={setIsMoveDialogOpen}
        folders={folders}
        currentFolderId={null}
        itemCount={selectedCount}
        onMove={selection.moveSelected}
      />
    </div>
  );
}
