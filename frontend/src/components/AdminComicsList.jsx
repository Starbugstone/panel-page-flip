import { useMemo, useState } from "react";
import { Table, TableHeader, TableBody, TableHead, TableRow, TableCell } from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { TagBadge } from "@/components/TagBadge";
import { AlertTriangle, Search, ShieldAlert, Tag as TagIcon, Trash, Edit, Eye } from "lucide-react";
import { useNavigate } from "react-router-dom";
import { useToast } from "@/hooks/use-toast";
import { useAdminList } from "@/hooks/use-admin-list";
import { useAdminTableControls } from "@/hooks/use-admin-table-controls";
import { AdminPagination } from "@/components/AdminPagination";
import { AdminWarnDialog } from "@/components/AdminWarnDialog";
import { AdminBulkActionsBar } from "@/components/admin/AdminBulkActionsBar";
import { SelectAllCheckbox, SelectionCheckbox } from "@/components/SelectionCheckbox";
import { useAdminBulkAction } from "@/hooks/use-admin-bulk-action";
import { useRowSelection } from "@/hooks/use-row-selection";
import { pluralize, summariseLabels } from "@/lib/bulk-actions";
import { Badge } from "@/components/ui/badge";
import { ComicEditDialog } from "@/components/ComicEditDialog";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { formatDate } from "@/lib/format";
import { AdminColumnHeader } from "@/components/admin/AdminColumnHeader";
import { adminFilterSuggestions } from "@/lib/admin-table-filters";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";

/**
 * @param {object} props
 * @param {number} [props.ownerId] Restrict the list to one user's library. Passed
 *        to the backend rather than filtered here, so paging stays correct.
 * @param {boolean} [props.embedded] Rendered inside another admin page; keeps its
 *        paging state local instead of claiming the page's query string.
 */
export function AdminComicsList({ ownerId, embedded = false }) {
  const navigate = useNavigate();
  const { toast } = useToast();
  const [editingComic, setEditingComic] = useState(null);
  const [comicToDelete, setComicToDelete] = useState(null);
  const [bulkDeleteOpen, setBulkDeleteOpen] = useState(false);
  // Always a list, so the row button and the bulk button share one dialog and
  // cannot drift apart in what they say or what they send.
  const [warningTargets, setWarningTargets] = useState([]);
  const tableControls = useAdminTableControls({ defaultSort: "uploadedAt" });

  const filters = useMemo(
    () => ({ adminContext: "true", ...(ownerId ? { ownerId } : {}), ...tableControls.query }),
    [ownerId, tableControls.query]
  );

  const {
    items: comics,
    listKey,
    pagination,
    isLoading,
    searchInput,
    setSearch,
    setPage,
    setLimit,
    reload,
  } = useAdminList({
    basePath: "/api/comics",
    filters,
    urlKey: embedded ? undefined : "comics",
    itemsKey: "comics",
    errorTitle: "Failed to load comics",
  });

  const selection = useRowSelection({ rows: comics, resetKey: listKey });
  const bulk = useAdminBulkAction({ reload });
  const selected = selection.selectedRows;

  const bulkActions = [
    {
      key: "warn",
      label: "Warn owners",
      icon: AlertTriangle,
      eligible: selected,
      onClick: () => setWarningTargets(selected),
    },
    {
      key: "delete",
      label: "Delete selected",
      icon: Trash,
      variant: "destructive",
      eligible: selected,
      onClick: () => setBulkDeleteOpen(true),
    },
  ];

  const handleDeleteComic = async (comicId) => {
    try {
      await api.delete(`/api/comics/${comicId}`);
      reload();
      toast({ title: "Comic deleted" });
    } catch (error) {
      logger.error(`Failed to delete comic ${comicId}:`, error);
      toast({ title: "Delete failed", description: error.message, variant: "destructive" });
    }
  };
  
  const handleEditComic = (comic) => {
    setEditingComic({
      ...comic,
      tags: (comic.tags || []).map((tag) => typeof tag === "string" ? tag : tag.name),
    });
  };
  
  const handleViewComic = (comicId) => {
    navigate(`/read/${comicId}`);
  };

  const handleSaveComic = async (payload) => {
    const data = await api.patch(`/api/comics/${payload.id}`, payload);
    // Patch response only returns id/title; reload so TagBadge gets full tag
    // metadata (id, isGlobal, hideFromLibrary) instead of rebuilt name stubs.
    reload();
    toast({ title: "Comic updated" });
    return data.comic || payload;
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <h2 className="text-xl font-bold">{embedded ? "Comics owned by this user" : "Comics Management"}</h2>
        <div className="relative w-full sm:w-auto">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            type="search"
            placeholder="Search comics..."
            className="w-full pl-8 sm:w-[300px]"
            value={searchInput}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
      </div>

      {/* Spinner only on the first load; turning a page keeps the table and its
          pager on screen, disabled, rather than collapsing the layout. */}
      {isLoading && comics.length === 0 ? (
        <div className="flex justify-center p-8">
          <div className="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-primary"></div>
        </div>
      ) : (
        <>
          <AdminBulkActionsBar
            selectedCount={selection.selectedCount}
            totalCount={comics.length}
            noun="comic"
            actions={bulkActions}
            progress={bulk.progress}
            onClear={selection.clear}
          />
          <div className="overflow-x-auto rounded-md border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-12">
                    <SelectAllCheckbox
                      state={selection.headerState}
                      onToggleAll={selection.toggleAll}
                      label="Select all comics"
                    />
                  </TableHead>
                  <TableHead><AdminColumnHeader label="Title / Author" sortField="title" filterField="filterTitleAuthor" filterSuggestions={adminFilterSuggestions(comics, (comic) => [comic.title, comic.author])} filterValue={tableControls.columnFilters.filterTitleAuthor} {...tableControls.headerProps} /></TableHead>
                  <TableHead><AdminColumnHeader label="Owner" sortField="owner" filterField="filterOwner" filterSuggestions={adminFilterSuggestions(comics, (comic) => [comic.owner?.name, comic.owner?.email])} filterValue={tableControls.columnFilters.filterOwner} {...tableControls.headerProps} /></TableHead>
                  <TableHead><AdminColumnHeader label="Uploaded" sortField="uploadedAt" filterField="filterUploadedAt" filterType="date" filterValue={tableControls.columnFilters.filterUploadedAt} {...tableControls.headerProps} /></TableHead>
                  <TableHead><AdminColumnHeader label="Pages" sortField="pageCount" filterField="filterPageCount" filterPlaceholder="Exact page count…" filterValue={tableControls.columnFilters.filterPageCount} {...tableControls.headerProps} /></TableHead>
                  <TableHead><AdminColumnHeader label="Tags" sortField="tags" filterField="filterTags" filterSuggestions={adminFilterSuggestions(comics, (comic) => (comic.tags || []).map((tag) => typeof tag === "string" ? tag : tag.name))} filterValue={tableControls.columnFilters.filterTags} {...tableControls.headerProps} /></TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {comics.length > 0 ? (
                  comics.map((comic) => (
                    <TableRow key={comic.id} data-state={selection.isChecked(comic) ? "selected" : undefined}>
                      <TableCell>
                        <SelectionCheckbox
                          checked={selection.isChecked(comic)}
                          onToggle={(checked, options) => selection.toggle(comic.id, checked, options)}
                          label={`Select ${comic.title}`}
                        />
                      </TableCell>
                      <TableCell>
                        <div className="flex flex-col">
                          <span className="flex items-center gap-2 font-medium">
                            {comic.title}
                            {/* Only when it is set. A "Not 18+" badge on every
                                other row would be noise on the majority to label
                                the minority, and absence already says it. */}
                            {comic.explicitContent && (
                              <Badge variant="destructive" className="gap-1">
                                <ShieldAlert className="h-3 w-3" aria-hidden="true" />
                                18+
                              </Badge>
                            )}
                          </span>
                          <span className="text-sm text-muted-foreground">{comic.author}</span>
                        </div>
                      </TableCell>
                      <TableCell>
                        <div className="flex flex-col">
                          {/* Adjust based on your User entity's fields available in Comic's owner serialization */}
                          <span>{comic.owner?.name || comic.owner?.email || 'N/A'}</span>
                          {comic.owner?.email && <span className="text-xs text-muted-foreground">{comic.owner.email}</span>}
                        </div>
                      </TableCell>
                      <TableCell>{formatDate(comic.uploadedAt)}</TableCell>
                      <TableCell>{comic.pageCount}</TableCell>
                      <TableCell>
                        <div className="flex flex-wrap gap-1 max-w-[200px]">
                          {comic.tags && comic.tags.map((tag, index) => (
                            <TagBadge key={tag.id || index} tag={tag} className="flex items-center gap-1">
                              <TagIcon size={12} />
                              {typeof tag === 'string' ? tag : tag.name} {/* Display tag name if it's an object */}
                            </TagBadge>
                          ))}
                        </div>
                      </TableCell>
                      <TableCell className="text-right">
                        <div className="flex justify-end gap-2">
                          <Button
                            variant="ghost"
                            size="sm"
                            title="Open this comic in the reader"
                            aria-label={`Read ${comic.title}`}
                            onClick={() => handleViewComic(comic.id)}
                          >
                            <Eye className="h-4 w-4" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            title="Edit comic"
                            aria-label={`Edit ${comic.title}`}
                            onClick={() => handleEditComic(comic)}
                          >
                            <Edit className="h-4 w-4" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            title="Warn the owner about this comic"
                            aria-label={`Warn the owner about ${comic.title}`}
                            onClick={() => setWarningTargets([comic])}
                          >
                            <AlertTriangle className="h-4 w-4" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            title="Delete comic"
                            aria-label={`Delete ${comic.title}`}
                            onClick={() => setComicToDelete(comic)}
                          >
                            <Trash className="h-4 w-4" />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={7} className="text-center py-8">
                      No comics found matching your search
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
            <AdminPagination
              pagination={pagination}
              itemCount={comics.length}
              isLoading={isLoading}
              onPageChange={setPage}
              onLimitChange={setLimit}
              label="comics"
            />
          </div>
        </>
      )}
      <ComicEditDialog
        comic={editingComic}
        isOpen={!!editingComic}
        onClose={() => setEditingComic(null)}
        onSave={handleSaveComic}
      />
      <AdminWarnDialog
        targets={warningTargets.map((comic) => ({ target: { comicId: comic.id }, label: comic.title }))}
        subjectLabel={warningTargets.length === 1
          ? warningTargets[0].title
          : warningTargets.length > 1 ? pluralize(warningTargets.length, "comic") : undefined}
        recipientLabel={describeOwners(warningTargets)}
        onClose={() => setWarningTargets([])}
      />

      <AlertDialog open={bulkDeleteOpen} onOpenChange={setBulkDeleteOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete {pluralize(selection.selectedCount, "comic")}?</AlertDialogTitle>
            <AlertDialogDescription>
              Delete {summariseLabels(selected, (comic) => comic.title)}. This permanently deletes
              each comic and its files. Any active shares will end.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={() => {
              setBulkDeleteOpen(false);
              bulk.run(
                selected,
                (comic) => api.delete(`/api/comics/${comic.id}`),
                { noun: "comic", verbPast: "deleted", labelOf: (comic) => comic.title }
              );
            }}>
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
      <AlertDialog open={!!comicToDelete} onOpenChange={(open) => !open && setComicToDelete(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete comic?</AlertDialogTitle>
            <AlertDialogDescription>
              Delete {comicToDelete?.title}. This permanently deletes the comic and its files.
              Any active shares will end.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={() => {
              const id = comicToDelete?.id;
              setComicToDelete(null);
              if (id) handleDeleteComic(id);
            }}>
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

/**
 * A warning about several comics reaches several owners, and one owner is
 * often all of them — naming the count of *comics* would say the wrong thing.
 */
function describeOwners(comics) {
  if (comics.length === 0) return undefined;

  const owners = new Set(comics.map((comic) => comic.owner?.name || comic.owner?.email || "The owner"));
  return owners.size === 1 ? [...owners][0] : pluralize(owners.size, "owner");
}
