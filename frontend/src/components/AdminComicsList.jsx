
import { useMemo, useState } from "react";
import { Table, TableHeader, TableBody, TableHead, TableRow, TableCell } from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { TagBadge } from "@/components/TagBadge";
import { Search, Tag as TagIcon, Trash, Edit, Eye } from "lucide-react";
import { useNavigate } from "react-router-dom";
import { useToast } from "@/hooks/use-toast";
import { useAdminList } from "@/hooks/use-admin-list";
import { AdminPagination } from "@/components/AdminPagination";
import { ComicEditDialog } from "@/components/ComicEditDialog";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { formatDate } from "@/lib/format";
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

// const mockComics = [ // Mock data removed


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

  const filters = useMemo(
    () => ({ adminContext: "true", ...(ownerId ? { ownerId } : {}) }),
    [ownerId]
  );

  const {
    items: comics,
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
      <div className="flex justify-between items-center">
        <h2 className="text-xl font-bold">{embedded ? "Comics owned by this user" : "Comics Management"}</h2>
        <div className="relative">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            type="search"
            placeholder="Search comics..."
            className="pl-8 w-[300px]"
            value={searchInput}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
      </div>

      {isLoading ? (
        <div className="flex justify-center p-8">
          <div className="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-primary"></div>
        </div>
      ) : (
        <div className="border rounded-md">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Title / Author</TableHead>
                <TableHead>Owner</TableHead>
                <TableHead>Uploaded</TableHead>
                <TableHead>Pages</TableHead>
                <TableHead>Tags</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {comics.length > 0 ? (
                comics.map((comic) => (
                  <TableRow key={comic.id}>
                    <TableCell>
                      <div className="flex flex-col">
                        <span className="font-medium">{comic.title}</span>
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
                        <Button variant="ghost" size="sm" onClick={() => handleViewComic(comic.id)}>
                          <Eye className="h-4 w-4" />
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => handleEditComic(comic)}>
                          <Edit className="h-4 w-4" />
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => setComicToDelete(comic)}>
                          <Trash className="h-4 w-4" />
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))
              ) : (
                <TableRow>
                  <TableCell colSpan={6} className="text-center py-8">
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
      )}
      <ComicEditDialog
        comic={editingComic}
        isOpen={!!editingComic}
        onClose={() => setEditingComic(null)}
        onSave={handleSaveComic}
      />
      <AlertDialog open={!!comicToDelete} onOpenChange={(open) => !open && setComicToDelete(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete comic?</AlertDialogTitle>
            <AlertDialogDescription>
              Delete {comicToDelete?.title}. This removes the comic from the library.
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
