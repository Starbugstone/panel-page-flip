import { useMemo, useState } from "react";
import { SelectAllCheckbox } from "@/components/SelectionCheckbox";
import { Table, TableBody, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { ComicBulkActionsBar } from "@/components/library/ComicBulkActionsBar";
import { ComicBulkDeleteDialog } from "@/components/library/ComicBulkDeleteDialog";
import { ComicBulkResetDialog } from "@/components/library/ComicBulkResetDialog";
import { ComicTableRow } from "@/components/library/ComicTableRow";
import { MoveToFolderDialog } from "@/components/library/MoveToFolderDialog";
import { useComicSelection } from "@/hooks/use-comic-selection";

const COLUMNS = [
  ["Comic", undefined],
  ["Author", "hidden md:table-cell"],
  ["Tags", "hidden xl:table-cell"],
  ["Location", "hidden xl:table-cell"],
  ["Progress", "hidden w-48 sm:table-cell xl:w-64"],
  ["Uploaded", "hidden xl:table-cell"],
  ["Actions", "w-20 px-2 text-right sm:w-auto sm:px-4"],
];

/**
 * The library as rows, with the bulk operations that only a table makes
 * practical: reset progress, tag, move, share or delete a whole selection.
 */
export function ComicTableView({
  comics,
  folders = [],
  onEditComic,
  onBulkAddTag,
  onBulkDelete,
  onBulkMove,
  onBulkResetProgress,
  onShareSelected,
}) {
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [isMoveDialogOpen, setIsMoveDialogOpen] = useState(false);
  const [isResetDialogOpen, setIsResetDialogOpen] = useState(false);

  const selection = useComicSelection({
    comics,
    onBulkAddTag,
    onBulkDelete,
    onBulkMove,
    onBulkResetProgress,
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
        canReset={Boolean(onBulkResetProgress)}
        onShare={() => onShareSelected(selection.selectedComicIds)}
        onMove={() => setIsMoveDialogOpen(true)}
        onReset={() => setIsResetDialogOpen(true)}
        onDelete={() => setIsDeleteDialogOpen(true)}
      />

      <div className="rounded-lg border">
        <Table className="table-fixed sm:table-auto">
          <TableHeader>
            <TableRow>
              <TableHead className="w-10 px-2 sm:w-12 sm:px-4">
                <SelectAllCheckbox
                  state={selection.headerState}
                  onToggleAll={selection.toggleAll}
                  label="Select all comics"
                />
              </TableHead>
              {COLUMNS.map(([column, className]) => (
                <TableHead
                  key={column}
                  className={className}
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

      <ComicBulkResetDialog
        open={isResetDialogOpen}
        onOpenChange={setIsResetDialogOpen}
        selection={selection}
        onConfirm={async () => {
          if (await selection.resetSelected()) setIsResetDialogOpen(false);
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
