
import { useState, useEffect } from "react";
import { Table, TableHeader, TableBody, TableFooter, TableHead, TableRow, TableCell } from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { Search, Tag as TagIcon, Trash, Edit, Eye } from "lucide-react";

// const mockComics = [ // Mock data removed


export function AdminComicsList() {
  const [comics, setComics] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState("");
  
  useEffect(() => {
    const fetchComics = async () => {
      setIsLoading(true);
      try {
        const response = await fetch('/api/comics', { // Admins get all comics
          credentials: 'include',
        });
        if (!response.ok) {
          throw new Error('Failed to fetch comics');
        }
        const data = await response.json();
        // Assuming API returns { comics: [...] } or just [...] 
        // And each comic has an 'owner' object with 'email' or 'username'
        // And 'tags' is an array of strings or objects with a 'name' property
        setComics(data.comics || data || []); 
      } catch (error) {
        console.error("Failed to load comics:", error);
        setComics([]);
      } finally {
        setIsLoading(false);
      }
    };

    fetchComics();
  }, []);
  
  const filteredComics = comics.filter(comic => 
    comic.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
    comic.author.toLowerCase().includes(searchQuery.toLowerCase()) ||
    (comic.owner && comic.owner.email && comic.owner.email.toLowerCase().includes(searchQuery.toLowerCase())) || // Adjusted for potential API structure
    (comic.owner && comic.owner.username && comic.owner.username.toLowerCase().includes(searchQuery.toLowerCase())) ||
    (comic.tags && comic.tags.some(tag => 
      typeof tag === 'string' ? tag.toLowerCase().includes(searchQuery.toLowerCase()) : 
      (tag.name && tag.name.toLowerCase().includes(searchQuery.toLowerCase()))
    ))
  );
  
  const formatDate = (dateString) => {
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('en-US', {
      dateStyle: 'medium',
    }).format(date);
  };
  
  const handleDeleteComic = async (comicId) => {
    if (!window.confirm('Are you sure you want to delete this comic?')) {
      return;
    }
    try {
      const response = await fetch(`/api/comics/${comicId}`, {
        method: 'DELETE',
        credentials: 'include',
      });
      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.message || 'Failed to delete comic');
      }
      setComics(comics.filter(comic => comic.id !== comicId));
      console.log(`Comic ${comicId} deleted successfully`);
      // Add success toast/message here
    } catch (error) {
      console.error(`Failed to delete comic ${comicId}:`, error);
      alert(`Error: ${error.message}`);
      // Add error toast/message here
    }
  };
  
  const handleEditComic = (comicId) => {
    // This would navigate to a comic edit form
    console.log(`Edit comic with ID: ${comicId}`);
  };
  
  const handleViewComic = (comicId) => {
    // This would navigate to the comic reader
    console.log(`View comic with ID: ${comicId}`);
  };
  
  return (
    <div className="space-y-4">
      <div className="flex justify-between items-center">
        <h2 className="text-xl font-bold">Comics Management</h2>
        <div className="relative">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            type="search"
            placeholder="Search comics..."
            className="pl-8 w-[300px]"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
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
              {filteredComics.length > 0 ? (
                filteredComics.map((comic) => (
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
                        <span>{comic.owner?.username || comic.owner?.email || 'N/A'}</span> 
                        {comic.owner?.email && <span className="text-xs text-muted-foreground">{comic.owner.email}</span>}
                      </div>
                    </TableCell>
                    <TableCell>{formatDate(comic.uploadedAt)}</TableCell>
                    <TableCell>{comic.pageCount}</TableCell>
                    <TableCell>
                      <div className="flex flex-wrap gap-1 max-w-[200px]">
                        {comic.tags && comic.tags.map((tag, index) => (
                          <Badge key={tag.id || index} variant="outline" className="flex items-center gap-1">
                            <TagIcon size={12} />
                            {typeof tag === 'string' ? tag : tag.name} {/* Display tag name if it's an object */}
                          </Badge>
                        ))}
                      </div>
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex justify-end gap-2">
                        <Button variant="ghost" size="sm" onClick={() => handleViewComic(comic.id)}>
                          <Eye className="h-4 w-4" />
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => handleEditComic(comic.id)}>
                          <Edit className="h-4 w-4" />
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => handleDeleteComic(comic.id)}>
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
            <TableFooter>
              <TableRow>
                <TableCell colSpan={6} className="text-right">
                  Total Comics: {filteredComics.length}
                </TableCell>
              </TableRow>
            </TableFooter>
          </Table>
        </div>
      )}
    </div>
  );
}
