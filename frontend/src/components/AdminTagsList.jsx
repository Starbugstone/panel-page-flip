import { useState, useEffect } from "react";
import { Table, TableHeader, TableBody, TableFooter, TableHead, TableRow, TableCell } from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Search, Plus, Trash, Edit } from "lucide-react";
import { useToast } from "@/hooks/use-toast";

export function AdminTagsList() {
  const { toast } = useToast();
  const [tags, setTags] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState("");
  const [isAddDialogOpen, setIsAddDialogOpen] = useState(false);
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [currentTag, setCurrentTag] = useState(null);
  const [newTagName, setNewTagName] = useState("");

  useEffect(() => {
    const fetchTags = async () => {
      setIsLoading(true);
      try {
        const response = await fetch('/api/tags', {
          credentials: 'include',
        });
        if (!response.ok) {
          const errorData = await response.json().catch(() => ({ message: 'Failed to fetch tags and parse error response' }));
          throw new Error(errorData.message || 'Failed to fetch tags');
        }
        const data = await response.json();
        setTags(data.tags || data || []);
      } catch (error) {
        console.error("Failed to load tags:", error);
        toast({ title: "Error", description: error.message || "Could not load tags.", variant: "destructive" });
        setTags([]);
      } finally {
        setIsLoading(false);
      }
    };

    fetchTags();
  }, [toast]);

  const filteredTags = tags.filter(tag =>
    tag.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
    (tag.creator && (tag.creator.username || tag.creator.email) &&
     (tag.creator.username || tag.creator.email).toLowerCase().includes(searchQuery.toLowerCase()))
  );

  const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('en-US', {
      dateStyle: 'medium',
    }).format(date);
  };

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
      const response = await fetch('/api/tags', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ name: newTagName.trim() }),
      });
      if (!response.ok) {
        const errorData = await response.json().catch(() => ({ message: 'Failed to create tag and parse error response' }));
        throw new Error(errorData.message || 'Failed to create tag');
      }
      const createdTag = await response.json();
      setTags([...tags, createdTag.tag || createdTag]);
      setNewTagName("");
      setIsAddDialogOpen(false);
      toast({ title: "Success", description: "Tag created successfully" });
    } catch (error) {
      console.error("Failed to create tag:", error);
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
      const response = await fetch(`/api/tags/${currentTag.id}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ name: newTagName.trim() }),
      });
      if (!response.ok) {
        const errorData = await response.json().catch(() => ({ message: 'Failed to update tag and parse error response' }));
        throw new Error(errorData.message || 'Failed to update tag');
      }
      const updatedTagData = await response.json();
      const finalUpdatedTag = updatedTagData.tag || updatedTagData;
      setTags(tags.map(tag => (tag.id === currentTag.id ? finalUpdatedTag : tag)));
      setNewTagName("");
      setIsEditDialogOpen(false);
      setCurrentTag(null);
      toast({ title: "Success", description: "Tag updated successfully" });
    } catch (error) {
      console.error("Failed to update tag:", error);
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
      const response = await fetch(`/api/tags/${tagId}`, {
        method: 'DELETE',
        credentials: 'include',
      });
      if (!response.ok) {
         const errorData = await response.json().catch(() => ({ message: 'Failed to delete tag and parse error response' }));
        throw new Error(errorData.message || 'Failed to delete tag');
      }
      setTags(tags.filter(tag => tag.id !== tagId));
      toast({ title: "Success", description: "Tag deleted successfully" });
    } catch (error) {
      console.error(`Failed to delete tag ${tagId}:`, error);
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
                    <TableCell>{tag.creator?.username || tag.creator?.email || 'N/A'}</TableCell>
                    <TableCell>{formatDate(tag.createdAt)}</TableCell>
                    <TableCell className="text-right">
                      <div className="flex justify-end gap-2">
                        <Button variant="ghost" size="sm" onClick={() => handleOpenEditDialog(tag)}>
                          <Edit className="h-4 w-4" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => handleDeleteTag(tag.id)}
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
    </div>
  );
}
