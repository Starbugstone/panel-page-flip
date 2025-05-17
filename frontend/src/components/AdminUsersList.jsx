
import { useState, useEffect } from "react";
import { Table, TableHeader, TableBody, TableFooter, TableHead, TableRow, TableCell } from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { 
  Search, 
  UserPlus, 
  User, 
  Users, 
  Settings, 
  Trash,
  Edit
} from "lucide-react";

const mockUsers = [
  { 
    id: 1, 
    email: "admin@example.com", 
    name: "Admin User", 
    roles: ["ROLE_ADMIN", "ROLE_USER"], 
    createdAt: "2023-01-15T10:30:00Z",
    comicCount: 12
  },
  { 
    id: 2, 
    email: "user1@example.com", 
    name: "Regular User", 
    roles: ["ROLE_USER"], 
    createdAt: "2023-02-20T15:45:00Z",
    comicCount: 5
  },
  { 
    id: 3, 
    email: "user2@example.com", 
    name: "Comic Fan", 
    roles: ["ROLE_USER"], 
    createdAt: "2023-03-10T09:15:00Z",
    comicCount: 28
  },
  { 
    id: 4, 
    email: "editor@example.com", 
    name: "Editor User", 
    roles: ["ROLE_EDITOR", "ROLE_USER"], 
    createdAt: "2023-04-05T14:20:00Z", 
    comicCount: 8
  },
];

export function AdminUsersList() {
  const [users, setUsers] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState("");
  
  useEffect(() => {
    // Mock API call
    const loadUsers = async () => {
      try {
        await new Promise(resolve => setTimeout(resolve, 800));
        setUsers(mockUsers);
      } catch (error) {
        console.error("Failed to load users:", error);
      } finally {
        setIsLoading(false);
      }
    };
    
    loadUsers();
  }, []);
  
  const filteredUsers = users.filter(user => 
    user.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
    user.email.toLowerCase().includes(searchQuery.toLowerCase())
  );
  
  const formatDate = (dateString) => {
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('en-US', {
      dateStyle: 'medium',
      timeStyle: 'short'
    }).format(date);
  };
  
  const handleDeleteUser = (userId) => {
    // This would call an API endpoint to delete the user
    console.log(`Delete user with ID: ${userId}`);
    // For demo, just remove from local state
    setUsers(users.filter(user => user.id !== userId));
  };
  
  const handleEditUser = (userId) => {
    // This would navigate to a user edit form
    console.log(`Edit user with ID: ${userId}`);
  };
  
  const handlePromoteToAdmin = (userId) => {
    // This would call an API endpoint to promote the user to admin
    console.log(`Promote user with ID: ${userId} to admin`);
    setUsers(users.map(user => {
      if (user.id === userId) {
        return {
          ...user,
          roles: [...user.roles, "ROLE_ADMIN"]
        };
      }
      return user;
    }));
  };
  
  return (
    <div className="space-y-4">
      <div className="flex justify-between items-center">
        <h2 className="text-xl font-bold">Users Management</h2>
        <div className="flex items-center gap-4">
          <div className="relative">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              type="search"
              placeholder="Search users..."
              className="pl-8 w-[250px]"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
            />
          </div>
          <Button>
            <UserPlus className="mr-2 h-4 w-4" />
            Add User
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
                <TableHead>Name / Email</TableHead>
                <TableHead>Role</TableHead>
                <TableHead>Created</TableHead>
                <TableHead>Comics</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {filteredUsers.length > 0 ? (
                filteredUsers.map((user) => (
                  <TableRow key={user.id}>
                    <TableCell>
                      <div className="flex flex-col">
                        <span className="font-medium">{user.name}</span>
                        <span className="text-sm text-muted-foreground">{user.email}</span>
                      </div>
                    </TableCell>
                    <TableCell>
                      <div className="flex flex-wrap gap-1">
                        {user.roles.includes("ROLE_ADMIN") && (
                          <Badge variant="default">Admin</Badge>
                        )}
                        {user.roles.includes("ROLE_EDITOR") && (
                          <Badge variant="secondary">Editor</Badge>
                        )}
                        {user.roles.length === 1 && user.roles.includes("ROLE_USER") && (
                          <Badge variant="outline">User</Badge>
                        )}
                      </div>
                    </TableCell>
                    <TableCell>{formatDate(user.createdAt)}</TableCell>
                    <TableCell>{user.comicCount}</TableCell>
                    <TableCell className="text-right">
                      <div className="flex justify-end gap-2">
                        <Button variant="ghost" size="sm" onClick={() => handleEditUser(user.id)}>
                          <Edit className="h-4 w-4" />
                        </Button>
                        {!user.roles.includes("ROLE_ADMIN") && (
                          <Button variant="ghost" size="sm" onClick={() => handlePromoteToAdmin(user.id)}>
                            <Settings className="h-4 w-4" />
                          </Button>
                        )}
                        <Button variant="ghost" size="sm" onClick={() => handleDeleteUser(user.id)}>
                          <Trash className="h-4 w-4" />
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))
              ) : (
                <TableRow>
                  <TableCell colSpan={5} className="text-center py-8">
                    No users found matching your search
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
            <TableFooter>
              <TableRow>
                <TableCell colSpan={5} className="text-right">
                  Total Users: {filteredUsers.length}
                </TableCell>
              </TableRow>
            </TableFooter>
          </Table>
        </div>
      )}
    </div>
  );
}
