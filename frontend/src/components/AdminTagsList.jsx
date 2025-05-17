
import { useState, useEffect } from "react";
import { Table, TableHeader, TableBody, TableFooter, TableHead, TableRow, TableCell } from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Search, Plus, Trash, Edit } from "lucide-react";
import { useToast } from "@/hooks/use-toast";

const mockTags = [
  { id: 1, name: "DC", comicCount: 24, creator: { name: "Admin User", id: 1 }, createdAt: "2023-01-15T10:30:00Z" },
  { id: 2, name: "Marvel", comicCount: 42, creator: { name: "Admin User", id: 1 }, createdAt: "2023-01-15T10:35:00Z" },
  { id: 3, name: "Sci-Fi", comicCount: 18, creator: { name: "Comic Fan", id: 3 }, createdAt: "2023-03-10T09:20:00Z" },
  { id: 4, name: "Fantasy", comicCount: 15, creator: { name: "Comic Fan", id: 3 }, createdAt: "2023-03-10T09:25:00Z" },
  { id: 5, name: "Horror", comicCount: 12, creator: { name: "Editor User", id: 4 }, createdAt: "2023-04-05T14:30:00Z" },
  { id: 6, name: "Adventure", comicCount: 20, creator: { name: "Regular User", id: 2 }, createdAt: "2023-02-20T15:50:00Z" },
  { id: 7, name: "Superhero", comicCount: 35, creator: { name: "Admin User", id: 1 }, createdAt: "2023-01-15T10:40:00Z" },
  { id: 8, name: "Manga", comicCount: 28, creator: { name: "Comic Fan", id: 3 }, createdAt: "2023-03-10T09:30:00Z" },
];

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
    // Mock API call
    const loadTags = async () => {
      try {
        await new Promise(resolve => setTimeout(resolve, 800));
        setTags(mockTags);
      } catch (error) {
        console.error("Failed to load tags:", error);
      } finally {
        setIsLoading(false);
      }
    };
    
    loadTags();
  }, []);
  
  const filteredTags = tags.filter(tag => 
    tag.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
    tag.creator.name.toLowerCase().includes(searchQuery.toLowerCase())
  );
  
  const formatDate = (dateString) => {
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('en-US', {
      dateStyle: 'medium',
    }).format(date);
  };
  
  const handleAddTag = () => {
    if (!newTagName.trim()) {
      toast({
        title: "Error",
        description: "Tag name cannot be empty",
        variant: "destructive"
      });
      return;
    }
    
    const tagExists = tags.some(tag => 
      tag.name.toLowerCase() === newTagName.trim().toLowerCase()
    );
    
    if (tagExists) {
      toast({
        title: "Error",
        description: "A tag with this name already exists",
        variant: "destructive"
      });
      return;
    }
    
    // Add new tag
    const newTag = {
      id: Math.max(...tags.map(t => t.id)) + 1,
      name: newTagName.trim(),
      comicCount: 0,
      creator: { name: "Admin User", id: 1 }, // Assuming admin is creating
      createdAt: new Date().toISOString()
    };
    
    setTags([...tags, newTag]);
    setNewTagName("");
    setIsAddDialogOpen(false);
    
    toast({
      title: "Success",
      description: "Tag created successfully"
    });
  };
  
  const handleOpenEditDialog = (tag) => {
    setCurrentTag(tag);
    setNewTagName(tag.name);
    setIsEditDialogOpen(true);
  };
  
  const handleEditTag = () => {
    if (!newTagName.trim()) {
      toast({
        title: "Error",
        description: "Tag name cannot be empty",
        variant: "destructive"
      });
      return;
    }
    
    const tagExists = tags.some(tag => 
      tag.id !== currentTag.id && 
      tag.name.toLowerCase() === newTagName.trim().toLowerCase()
    );
    
    if (tagExists) {
      toast({
        title: "Error",
        description: "A tag with this name already exists",
        variant: "destructive"
      });
      return;
    }
    
    // Update tag
    setTags(tags.map(tag => 
      tag.id === currentTag.id ? { ...tag, name: newTagName.trim() } : tag
    ));
    
    setNewTagName("");
    setIsEditDialogOpen(false);
    setCurrentTag(null);
    
    toast({
      title: "Success",
      description: "Tag updated successfully"
    });
  };
  
  const handleDeleteTag = (tagId) => {
    const tagToDelete = tags.find(tag => tag.id === tagId);
    
    if (tagToDelete.comicCount > 0) {
      toast({
        title: "Cannot Delete",
        description: `This tag is used by ${tagToDelete.comicCount} comics`,
        variant: "destructive"
      });
      return;
    }
    
    // Delete tag
    setTags(tags.filter(tag => tag.id !== tagId));
    
    toast({
      title: "Success",
      description: "Tag deleted successfully"
    });
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
                    <TableCell>{tag.creator.name}</TableCell>
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
                    No tags found matching your search
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
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setIsAddDialogOpen(false)}>Cancel</Button>
            <Button onClick={handleAddTag}>Add Tag</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      
      {/* Edit Tag Dialog */}
      <Dialog open={isEditDialogOpen} onOpenChange={setIsEditDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit Tag</DialogTitle>
          </DialogHeader>
          <div className="py-4">
            <Input
              placeholder="Tag name"
              value={newTagName}
              onChange={(e) => setNewTagName(e.target.value)}
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setIsEditDialogOpen(false)}>Cancel</Button>
            <Button onClick={handleEditTag}>Update Tag</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
