
import { useState, useEffect } from "react";
import { Table, TableHeader, TableBody, TableFooter, TableHead, TableRow, TableCell } from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { Search, Tag as TagIcon, Trash, Edit, Eye } from "lucide-react";

const mockComics = [
  {
    id: 1,
    title: "Batman: The Dark Knight Returns",
    author: "Frank Miller",
    owner: {
      id: 1,
      name: "Admin User",
      email: "admin@example.com"
    },
    uploadedAt: "2023-05-10T08:30:00Z",
    pageCount: 202,
    tags: ["DC", "Batman", "Dark", "Classic"]
  },
  {
    id: 2,
    title: "Watchmen",
    author: "Alan Moore",
    owner: {
      id: 2,
      name: "Regular User",
      email: "user1@example.com"
    },
    uploadedAt: "2023-06-15T14:20:00Z",
    pageCount: 416,
    tags: ["DC", "Alan Moore", "Classic", "Dystopian"]
  },
  {
    id: 3,
    title: "Saga",
    author: "Brian K. Vaughan",
    owner: {
      id: 3,
      name: "Comic Fan",
      email: "user2@example.com"
    },
    uploadedAt: "2023-07-22T11:45:00Z",
    pageCount: 168,
    tags: ["Image", "Sci-Fi", "Fantasy"]
  },
  {
    id: 4,
    title: "Sandman",
    author: "Neil Gaiman",
    owner: {
      id: 4,
      name: "Editor User",
      email: "editor@example.com"
    },
    uploadedAt: "2023-08-05T09:15:00Z",
    pageCount: 234,
    tags: ["Vertigo", "Fantasy", "Horror", "Dream"]
  },
];

export function AdminComicsList() {
  const [comics, setComics] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState("");
  
  useEffect(() => {
    // Mock API call
    const loadComics = async () => {
      try {
        await new Promise(resolve => setTimeout(resolve, 800));
        setComics(mockComics);
      } catch (error) {
        console.error("Failed to load comics:", error);
      } finally {
        setIsLoading(false);
      }
    };
    
    loadComics();
  }, []);
  
  const filteredComics = comics.filter(comic => 
    comic.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
    comic.author.toLowerCase().includes(searchQuery.toLowerCase()) ||
    comic.owner.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
    comic.tags.some(tag => tag.toLowerCase().includes(searchQuery.toLowerCase()))
  );
  
  const formatDate = (dateString) => {
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('en-US', {
      dateStyle: 'medium',
    }).format(date);
  };
  
  const handleDeleteComic = (comicId) => {
    // This would call an API endpoint to delete the comic
    console.log(`Delete comic with ID: ${comicId}`);
    // For demo, just remove from local state
    setComics(comics.filter(comic => comic.id !== comicId));
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
                        <span>{comic.owner.name}</span>
                        <span className="text-xs text-muted-foreground">{comic.owner.email}</span>
                      </div>
                    </TableCell>
                    <TableCell>{formatDate(comic.uploadedAt)}</TableCell>
                    <TableCell>{comic.pageCount}</TableCell>
                    <TableCell>
                      <div className="flex flex-wrap gap-1 max-w-[200px]">
                        {comic.tags.map((tag, index) => (
                          <Badge key={index} variant="outline" className="flex items-center gap-1">
                            <TagIcon size={12} />
                            {tag}
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
