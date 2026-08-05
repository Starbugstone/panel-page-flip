import { useCallback, useState, useEffect } from "react";
import { Table, TableHeader, TableBody, TableFooter, TableHead, TableRow, TableCell } from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Search, Plus, Trash, Edit } from "lucide-react";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { formatDate, matchesQuery } from "@/lib/format";
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

export function AdminTagsList() {
  const { toast } = useToast();
  const [tags, setTags] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState("");
  const [isAddDialogOpen, setIsAddDialogOpen] = useState(false);
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [currentTag, setCurrentTag] = useState(null);
  const [tagToDelete, setTagToDelete] = useState(null);
  const [newTagName, setNewTagName] = useState("");

  const loadTags = useCallback(async () => {
    setIsLoading(true);
    try {
      const data = await api.get("/api/tags?all=true&adminContext=true");
      setTags(data.tags || data || []);
    } catch (error) {
      logger.error("Failed to load tags:", error);
      toast({ title: "Error", description: error.message || "Could not load tags.", variant: "destructive" });
      setTags([]);
    } finally {
      setIsLoading(false);
    }
  }, [toast]);

  useEffect(() => {
    loadTags();
  }, [loadTags]);

  const query = searchQuery.toLowerCase();
  const filteredTags = tags.filter(tag =>
    matchesQuery(tag.name, query) ||
    matchesQuery(tag.creator?.name || tag.creator?.email, query)
  );

  const handleAddTag = async () => {
    if (!newTagName.trim()) {
      toast({ title: "Error", description: "Tag name cannot be empty", variant: "destructive" });
      return;
    }
    const tagExists = tags.some(tag => tag.name.toLowerCase() === newTagName.trim().toLowerCase());
    if (tagExists) {
      toast({ title: "Error", description: "A tag with this name already exists", variant: "destructive" });
      return;
    }

    try {
      const createdTag = await api.post("/api/tags", { name: newTagName.trim() });
      setTags([...tags, createdTag.tag || createdTag]);
      setNewTagName("");
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
    setIsEditDialogOpen(true);
  };

  const handleEditTag = async () => {
    if (!currentTag || !newTagName.trim()) {
      toast({ title: "Error", description: "Tag name cannot be empty or tag not selected.", variant: "destructive" });
      return;
    }
    const tagExists = tags.some(tag =>
      tag.id !== currentTag.id &&
      tag.name.toLowerCase() === newTagName.trim().toLowerCase()
    );
    if (tagExists) {
      toast({ title: "Error", description: "A tag with this name already exists", variant: "destructive" });
      return;
    }

    try {
      const updatedTagData = await api.put(`/api/tags/${currentTag.id}`, { name: newTagName.trim() });
      const finalUpdatedTag = updatedTagData.tag || updatedTagData;
      setTags(tags.map(tag => (tag.id === currentTag.id ? finalUpdatedTag : tag)));
      setNewTagName("");
      setIsEditDialogOpen(false);
      setCurrentTag(null);
      toast({ title: "Success", description: "Tag updated successfully" });
    } catch (error) {
      logger.error("Failed to update tag:", error);
      toast({ title: "Error", description: error.message || "Could not update tag.", variant: "destructive" });
    }
  };

  const handleDeleteTag = async (tagId) => {
    const tagToDelete = tags.find(tag => tag.id === tagId);
    if (!tagToDelete) {
        toast({ title: "Error", description: "Tag not found.", variant: "destructive" });
        return;
    }
    if (tagToDelete.comicCount > 0) {
      toast({ title: "Cannot Delete", description: `This tag is used by ${tagToDelete.comicCount} comics`, variant: "destructive" });
      return;
    }

    try {
      await api.delete(`/api/tags/${tagId}`);
      setTags((currentTags) => currentTags.filter((tag) => tag.id !== tagId));
      toast({ title: "Success", description: "Tag deleted successfully" });
    } catch (error) {
      logger.error(`Failed to delete tag ${tagId}:`, error);
      toast({ title: "Error", description: error.message || "Could not delete tag.", variant: "destructive" });
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex justify-between items-center">
        <h2 className="text-xl font-bold">Tags Management</h2>
        <div className="flex items-center gap-4">
          <div className="relative">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              type="search"
              placeholder="Search tags..."
              className="pl-8 w-[250px]"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
            />
          </div>
          <Button onClick={() => setIsAddDialogOpen(true)}>
            <Plus className="mr-2 h-4 w-4" />
            Add Tag
          </Button>
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
                <TableHead>Tag Name</TableHead>
                <TableHead>Comics Using</TableHead>
                <TableHead>Created By</TableHead>
                <TableHead>Created Date</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {filteredTags.length > 0 ? (
                filteredTags.map((tag) => (
                  <TableRow key={tag.id}>
                    <TableCell>
                      <span className="font-medium">{tag.name}</span>
                    </TableCell>
                    <TableCell>{tag.comicCount}</TableCell>
                    <TableCell>{tag.creator?.name || tag.creator?.email || 'N/A'}</TableCell>
                    <TableCell>{formatDate(tag.createdAt)}</TableCell>
                    <TableCell className="text-right">
                      <div className="flex justify-end gap-2">
                        <Button variant="ghost" size="sm" onClick={() => handleOpenEditDialog(tag)}>
                          <Edit className="h-4 w-4" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setTagToDelete(tag)}
                          disabled={tag.comicCount > 0}
                        >
                          <Trash className="h-4 w-4" />
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))
              ) : (
                <TableRow>
                  <TableCell colSpan={5} className="text-center py-8">
                    {searchQuery ? "No tags found matching your search" : "No tags available"}
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
            <TableFooter>
              <TableRow>
                <TableCell colSpan={5} className="text-right">
                  Total Tags: {filteredTags.length}
                </TableCell>
              </TableRow>
            </TableFooter>
          </Table>
        </div>
      )}

      {/* Add Tag Dialog */}
      <Dialog open={isAddDialogOpen} onOpenChange={setIsAddDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Add New Tag</DialogTitle>
          </DialogHeader>
          <div className="py-4">
            <Input
              placeholder="Tag name"
              value={newTagName}
              onChange={(e) => setNewTagName(e.target.value)}
              autoFocus
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => { setIsAddDialogOpen(false); setNewTagName(""); }}>Cancel</Button>
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
        }
      }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit Tag</DialogTitle>
          </DialogHeader>
          <div className="py-4">
            <Input
              placeholder="Tag name"
              value={newTagName}
              onChange={(e) => setNewTagName(e.target.value)}
              autoFocus
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => { setIsEditDialogOpen(false); setCurrentTag(null); setNewTagName(""); }}>Cancel</Button>
            <Button onClick={handleEditTag}>Update Tag</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={!!tagToDelete} onOpenChange={(open) => !open && setTagToDelete(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete tag?</AlertDialogTitle>
            <AlertDialogDescription>
              Delete {tagToDelete?.name}. This cannot be undone.
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
