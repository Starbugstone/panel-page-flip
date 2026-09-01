import { useMemo, useState } from "react";
import { Table, TableHeader, TableBody, TableHead, TableRow, TableCell } from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Search, Plus, Trash, Edit, Globe2, EyeOff } from "lucide-react";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { TagBadge, HIDDEN_TAG_EXPLANATION } from "@/components/TagBadge";
import { useToast } from "@/hooks/use-toast";
import { useAdminList } from "@/hooks/use-admin-list";
import { useAdminTableControls } from "@/hooks/use-admin-table-controls";
import { AdminPagination } from "@/components/AdminPagination";
import { AdminBulkActionsBar } from "@/components/admin/AdminBulkActionsBar";
import { SelectAllCheckbox, SelectionCheckbox } from "@/components/SelectionCheckbox";
import { useAdminBulkAction } from "@/hooks/use-admin-bulk-action";
import { useRowSelection } from "@/hooks/use-row-selection";
import { pluralize, summariseLabels } from "@/lib/bulk-actions";
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
 * @param {number} [props.creatorId] Restrict to one user's personal tags. Applied
 *        by the backend, so paging and totals stay correct.
 * @param {boolean} [props.embedded] Rendered inside another admin page; keeps its
 *        paging state local instead of claiming the page's query string.
 */
export function AdminTagsList({ creatorId, embedded = false }) {
  const { toast } = useToast();
  const [isAddDialogOpen, setIsAddDialogOpen] = useState(false);
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [currentTag, setCurrentTag] = useState(null);
  const [tagToDelete, setTagToDelete] = useState(null);
  const [bulkDeleteOpen, setBulkDeleteOpen] = useState(false);
  const [newTagName, setNewTagName] = useState("");
  const [hideFromLibrary, setHideFromLibrary] = useState(false);
  const tableControls = useAdminTableControls({ defaultSort: "name", defaultDirection: "ASC" });

  const filters = useMemo(
    () => ({ all: "true", adminContext: "true", ...(creatorId ? { creatorId } : {}), ...tableControls.query }),
    [creatorId, tableControls.query]
  );

  const {
    items: tags,
    listKey,
    pagination,
    isLoading,
    searchInput,
    setSearch,
    setPage,
    setLimit,
    reload,
  } = useAdminList({
    basePath: "/api/tags",
    filters,
    urlKey: embedded ? undefined : "tags",
    itemsKey: "tags",
    errorTitle: "Could not load tags",
  });

  const selection = useRowSelection({ rows: tags, resetKey: listKey });
  const bulk = useAdminBulkAction({ reload });
  const selected = selection.selectedRows;
  // Deletion is the only bulk operation here: a name is one tag's own, and
  // "hide from library" is a global-tag setting a mixed selection cannot answer.
  const bulkActions = [{
    key: "delete",
    label: "Delete selected",
    icon: Trash,
    variant: "destructive",
    eligible: selected,
    onClick: () => setBulkDeleteOpen(true),
  }];

  const handleAddTag = async () => {
    if (!newTagName.trim()) {
      toast({ title: "Error", description: "Tag name cannot be empty", variant: "destructive" });
      return;
    }
    // The visible page is no longer the whole table, so a name clash outside it
    // can only be caught by the backend, which answers 409.
    try {
      await api.post("/api/tags", {
        name: newTagName.trim(),
        isGlobal: true,
        hideFromLibrary,
      });
      reload();
      setNewTagName("");
      setHideFromLibrary(false);
      setIsAddDialogOpen(false);
      toast({ title: "Success", description: "Tag created successfully" });
    } catch (error) {
      logger.error("Failed to create tag:", error);
      toast({ title: "Error", description: error.message || "Could not create tag.", variant: "destructive" });
    }
  };

  const handleOpenEditDialog = (tag) => {
    setCurrentTag(tag);
    setNewTagName(tag.name);
    setHideFromLibrary(tag.hideFromLibrary === true);
    setIsEditDialogOpen(true);
  };

  const handleEditTag = async () => {
    if (!currentTag || !newTagName.trim()) {
      toast({ title: "Error", description: "Tag name cannot be empty or tag not selected.", variant: "destructive" });
      return;
    }
    try {
      await api.put(`/api/tags/${currentTag.id}`, {
        name: newTagName.trim(),
        ...(currentTag.isGlobal ? { hideFromLibrary } : {}),
      });
      // Reload rather than patch the row in place: the list is sorted by name on
      // the server, so a rename can move the tag to a different page entirely.
      reload();
      setNewTagName("");
      setHideFromLibrary(false);
      setIsEditDialogOpen(false);
      setCurrentTag(null);
      toast({ title: "Success", description: "Tag updated successfully" });
    } catch (error) {
      logger.error("Failed to update tag:", error);
      toast({ title: "Error", description: error.message || "Could not update tag.", variant: "destructive" });
    }
  };

  const handleDeleteTag = async (tagId) => {
    try {
      await api.delete(`/api/tags/${tagId}`);
      reload();
      toast({ title: "Success", description: "Tag deleted successfully" });
    } catch (error) {
      logger.error(`Failed to delete tag ${tagId}:`, error);
      toast({ title: "Error", description: error.message || "Could not delete tag.", variant: "destructive" });
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <h2 className="text-xl font-bold">{embedded ? "Tags created by this user" : "Tags Management"}</h2>
        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center sm:gap-4">
          <div className="relative w-full sm:w-auto">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              type="search"
              placeholder="Search tags..."
              className="w-full pl-8 sm:w-[250px]"
              value={searchInput}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          {!embedded && (
            <Button className="w-full sm:w-auto" onClick={() => setIsAddDialogOpen(true)}>
              <Plus className="mr-2 h-4 w-4" />
              Add Tag
            </Button>
          )}
        </div>
      </div>
      <p className="text-sm text-muted-foreground">
        {embedded
          ? "Personal tags belong to this user alone. Global tags have no creator and are managed from the Tags tab."
          : "Tags added here are global and available to every user. The hide option can be changed independently for any global tag."}
      </p>

      {/* Spinner only on the first load; turning a page keeps the table and its
          pager on screen, disabled, rather than collapsing the layout. */}
      {isLoading && tags.length === 0 ? (
        <div className="flex justify-center p-8">
          <div className="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-primary"></div>
        </div>
      ) : (
        <>
          <AdminBulkActionsBar
            selectedCount={selection.selectedCount}
            totalCount={tags.length}
            noun="tag"
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
                      label="Select all tags"
                    />
                  </TableHead>
                  <TableHead><AdminColumnHeader label="Tag name" sortField="name" filterField="filterName" filterSuggestions={adminFilterSuggestions(tags, (tag) => tag.name)} filterValue={tableControls.columnFilters.filterName} {...tableControls.headerProps} /></TableHead>
                  <TableHead><AdminColumnHeader label="Scope" sortField="isGlobal" filterField="filterScope" filterPlaceholder="Global or personal…" filterSuggestions={["Global", "Personal"]} filterValue={tableControls.columnFilters.filterScope} {...tableControls.headerProps} /></TableHead>
                  <TableHead><AdminColumnHeader label="Default library" sortField="hideFromLibrary" filterField="filterVisibility" filterPlaceholder="Visible or hidden…" filterSuggestions={["Visible", "Hidden"]} filterValue={tableControls.columnFilters.filterVisibility} {...tableControls.headerProps} /></TableHead>
                  <TableHead><AdminColumnHeader label="Comics using" sortField="comicCount" filterField="filterComicCount" filterPlaceholder="Exact count…" filterValue={tableControls.columnFilters.filterComicCount} {...tableControls.headerProps} /></TableHead>
                  <TableHead><AdminColumnHeader label="Created by" sortField="creator" filterField="filterCreator" filterSuggestions={["System", ...adminFilterSuggestions(tags, (tag) => [tag.creator?.name, tag.creator?.email])]} filterValue={tableControls.columnFilters.filterCreator} {...tableControls.headerProps} /></TableHead>
                  <TableHead><AdminColumnHeader label="Created date" sortField="createdAt" filterField="filterCreatedAt" filterType="date" filterValue={tableControls.columnFilters.filterCreatedAt} {...tableControls.headerProps} /></TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {tags.length > 0 ? (
                  tags.map((tag) => (
                    <TableRow key={tag.id} data-state={selection.isChecked(tag) ? "selected" : undefined}>
                      <TableCell>
                        <SelectionCheckbox
                          checked={selection.isChecked(tag)}
                          onToggle={(checked, options) => selection.toggle(tag.id, checked, options)}
                          label={`Select ${tag.name}`}
                        />
                      </TableCell>
                      <TableCell>
                        <TagBadge tag={tag} className="font-medium" />
                      </TableCell>
                      <TableCell>{tag.isGlobal ? <span className="inline-flex items-center gap-1"><Globe2 className="h-4 w-4" /> Global</span> : "Personal"}</TableCell>
                      <TableCell>{tag.hideFromLibrary ? <span className="inline-flex items-center gap-1 text-pink-700 dark:text-pink-300"><EyeOff className="h-4 w-4" /> Hidden</span> : "Visible"}</TableCell>
                      <TableCell>{tag.comicCount}</TableCell>
                      <TableCell>{tag.creator?.name || tag.creator?.email || 'System'}</TableCell>
                      <TableCell>{formatDate(tag.createdAt)}</TableCell>
                      <TableCell className="text-right">
                        <div className="flex justify-end gap-2">
                          <Button
                            variant="ghost"
                            size="sm"
                            aria-label={`Edit ${tag.name}`}
                            title="Edit tag"
                            onClick={() => handleOpenEditDialog(tag)}
                          >
                            <Edit className="h-4 w-4" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            aria-label={`Delete ${tag.name}`}
                            title="Delete tag"
                            onClick={() => setTagToDelete(tag)}
                          >
                            <Trash className="h-4 w-4" />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={8} className="text-center py-8">
                      {searchInput ? "No tags found matching your search" : "No tags available"}
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
            <AdminPagination
              pagination={pagination}
              itemCount={tags.length}
              isLoading={isLoading}
              onPageChange={setPage}
              onLimitChange={setLimit}
              label="tags"
            />
          </div>
        </>
      )}

      {/* Add Tag Dialog */}
      <Dialog open={isAddDialogOpen} onOpenChange={setIsAddDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Add New Tag</DialogTitle>
            <DialogDescription>Create a global tag that can be used by every account.</DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <Input
              placeholder="Tag name"
              value={newTagName}
              onChange={(e) => setNewTagName(e.target.value)}
              autoFocus
            />
            <div className="flex items-start justify-between gap-4 rounded-md border p-3">
              <div className="space-y-1">
                <Label htmlFor="add-hide-from-library">Hide comics from the default library</Label>
                <p className="text-xs text-muted-foreground">{HIDDEN_TAG_EXPLANATION}</p>
              </div>
              <Switch id="add-hide-from-library" checked={hideFromLibrary} onCheckedChange={setHideFromLibrary} />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => { setIsAddDialogOpen(false); setNewTagName(""); setHideFromLibrary(false); }}>Cancel</Button>
            <Button onClick={handleAddTag}>Add Tag</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Edit Tag Dialog */}
      <Dialog open={isEditDialogOpen} onOpenChange={(isOpen) => {
        setIsEditDialogOpen(isOpen);
        if (!isOpen) {
          setCurrentTag(null);
          setNewTagName("");
          setHideFromLibrary(false);
        }
      }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit Tag</DialogTitle>
            <DialogDescription>
              {currentTag?.isGlobal
                ? "Change this tag's name and default library visibility."
                : "Change this tag's name."}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <Input
              placeholder="Tag name"
              value={newTagName}
              onChange={(e) => setNewTagName(e.target.value)}
              autoFocus
            />
            {currentTag?.isGlobal && (
              <div className="flex items-start justify-between gap-4 rounded-md border p-3">
                <div className="space-y-1">
                  <Label htmlFor="edit-hide-from-library">Hide comics from the default library</Label>
                  <p className="text-xs text-muted-foreground">{HIDDEN_TAG_EXPLANATION}</p>
                </div>
                <Switch id="edit-hide-from-library" checked={hideFromLibrary} onCheckedChange={setHideFromLibrary} />
              </div>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => { setIsEditDialogOpen(false); setCurrentTag(null); setNewTagName(""); setHideFromLibrary(false); }}>Cancel</Button>
            <Button onClick={handleEditTag}>Update Tag</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={bulkDeleteOpen} onOpenChange={setBulkDeleteOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete {pluralize(selection.selectedCount, "tag")}?</AlertDialogTitle>
            <AlertDialogDescription>
              Delete {summariseLabels(selected, (tag) => tag.name)}. They will also be removed from
              the {selected.reduce((total, tag) => total + (tag.comicCount || 0), 0)} comic(s) using
              them. This cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={() => {
              setBulkDeleteOpen(false);
              bulk.run(
                selected,
                (tag) => api.delete(`/api/tags/${tag.id}`),
                { noun: "tag", verbPast: "deleted", labelOf: (tag) => tag.name }
              );
            }}>
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog open={!!tagToDelete} onOpenChange={(open) => !open && setTagToDelete(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete tag?</AlertDialogTitle>
            <AlertDialogDescription>
              Delete {tagToDelete?.name}. It will also be removed from {tagToDelete?.comicCount || 0} comic(s). This cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={() => {
              const id = tagToDelete?.id;
              setTagToDelete(null);
              if (id) {
                handleDeleteTag(id);
              }
            }}>
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
