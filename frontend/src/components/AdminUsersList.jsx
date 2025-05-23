
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
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox"; // Assuming Checkbox component is available
import { useAuth } from "@/hooks/use-auth"; // Import useAuth hook

export function AdminUsersList() {
  const [users, setUsers] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState("");
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [editingUser, setEditingUser] = useState(null);
  const [editFormData, setEditFormData] = useState({ name: '', email: '', password: '', roles: [] });
  const { user: currentUser } = useAuth(); // Get the currently logged-in user

  // State for Add User Dialog
  const [isAddUserDialogOpen, setIsAddUserDialogOpen] = useState(false);
  const [newUserData, setNewUserData] = useState({ name: '', email: '', password: '', roles: ['ROLE_USER'] });
  
  useEffect(() => {
    const fetchUsers = async () => {
      setIsLoading(true);
      try {
        const response = await fetch('/api/users', {
          credentials: 'include',
        });
        if (!response.ok) {
          throw new Error('Failed to fetch users');
        }
        const data = await response.json();
        setUsers(data.users || []); // Assuming backend returns { users: [...] }
      } catch (error) {
        console.error("Failed to load users:", error);
        // Potentially set an error state here to show in UI
        setUsers([]); // Clear users on error
      } finally {
        setIsLoading(false);
      }
    };

    fetchUsers();
  }, []);
  
  const filteredUsers = users.filter(user => {
    const query = searchQuery.toLowerCase();
    const nameMatch = user.name && typeof user.name === 'string' && user.name.toLowerCase().includes(query);
    const emailMatch = user.email && typeof user.email === 'string' && user.email.toLowerCase().includes(query);
    return nameMatch || emailMatch;
  });
  
  const formatDate = (dateString) => {
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('en-US', {
      dateStyle: 'medium',
      timeStyle: 'short'
    }).format(date);
  };
  
  const handleDeleteUser = async (userId) => {
    if (!window.confirm('Are you sure you want to delete this user?')) {
      return;
    }
    try {
      const response = await fetch(`/api/users/${userId}`, {
        method: 'DELETE',
        credentials: 'include',
      });
      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.message || 'Failed to delete user');
      }
      // Refetch users or remove from local state
      setUsers(users.filter(user => user.id !== userId));
      // Consider showing a success toast/message
      console.log(`User ${userId} deleted successfully`);
    } catch (error) {
      console.error(`Failed to delete user ${userId}:`, error);
      // Consider showing an error toast/message
      alert(`Error: ${error.message}`);
    }
  };
  
  const handleEditUser = (user) => {
    setEditingUser(user);
    // Pre-fill form. Email is typically not editable or handled with care.
    // Password field is kept blank for security reasons on edit.
    setEditFormData({ 
      name: user.name || '', 
      email: user.email || '', 
      password: '', 
      roles: Array.isArray(user.roles) ? [...user.roles] : [] 
    }); 
    setIsEditDialogOpen(true);
    console.log(`Editing user:`, user);
  };
  
  const handlePromoteToAdmin = async (userId) => {
    const userToPromote = users.find(u => u.id === userId);
    if (!userToPromote) return;

    if (!window.confirm(`Are you sure you want to promote ${userToPromote.name || userToPromote.email} to Admin?`)) {
      return;
    }

    const newRoles = Array.from(new Set([...userToPromote.roles, 'ROLE_ADMIN']));

    try {
      const response = await fetch(`/api/users/${userId}`, {
        method: 'PUT', // Or PATCH
        headers: {
          'Content-Type': 'application/json',
        },
        credentials: 'include',
        body: JSON.stringify({ roles: newRoles }),
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.message || 'Failed to promote user');
      }
      const updatedUser = await response.json(); // Assuming backend returns the updated user object
      // Update local state
      setUsers(users.map(user => (user.id === userId ? { ...user, roles: updatedUser.user.roles } : user)));
      // Consider showing a success toast/message
      console.log(`User ${userId} promoted to admin successfully`);
    } catch (error) {
      console.error(`Failed to promote user ${userId}:`, error);
      // Consider showing an error toast/message
      alert(`Error: ${error.message}`);
    }
  };
  
  const handleSaveUserUpdate = async () => {
    if (!editingUser) return;

    const payload = {};
    // Only add name to payload if it has actually changed and is not empty
    if (editFormData.name && editFormData.name.trim() !== '' && editFormData.name !== editingUser.name) {
      payload.name = editFormData.name.trim();
    }
    // Only add password to payload if it's not empty
    if (editFormData.password && editFormData.password.trim() !== '') {
      payload.password = editFormData.password.trim();
    }

    // Handle roles update, but not if admin is editing themselves
    const rolesChanged = JSON.stringify(editFormData.roles.sort()) !== JSON.stringify(editingUser.roles.sort());
    if (currentUser && editingUser.id !== currentUser.id && rolesChanged) {
      // Ensure ROLE_USER is always present, and remove duplicates
      const newRoles = Array.from(new Set([...editFormData.roles, 'ROLE_USER']));
      payload.roles = newRoles;
    } else if (rolesChanged && editingUser.id === currentUser.id) {
      console.warn("Admin cannot change their own roles through this form.");
      // Optionally, provide feedback to the user that their own roles cannot be changed here.
    }

    // If nothing to update, just close the dialog
    if (Object.keys(payload).length === 0) {
      setIsEditDialogOpen(false);
      console.log("No changes to save.");
      return;
    }

    console.log("Attempting to save user update with payload:", payload, "for user ID:", editingUser.id);

    try {
      const response = await fetch(`/api/users/${editingUser.id}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
        },
        credentials: 'include', // Important for sending cookies if session-based auth
        body: JSON.stringify(payload),
      });

      if (!response.ok) {
        // Try to parse error response, provide a fallback message if parsing fails
        const errorData = await response.json().catch(() => ({ message: 'Failed to update user. The server returned an error without a specific message.' }));
        throw new Error(errorData.message || 'Failed to update user due to a server error.');
      }

      const updatedUserResponse = await response.json(); // Expecting { message: '...', user: { id, email, name, roles, ... } }

      // Update local state with the user data from the response
      setUsers(users.map(user => 
        user.id === editingUser.id ? { ...user, ...updatedUserResponse.user } : user
      ));
      setIsEditDialogOpen(false);
      setEditingUser(null); // Clear editing state
      // alert('User updated successfully!'); // Removed alert, consider toast notification
      console.log(`User ${editingUser.id} updated successfully:`, updatedUserResponse.user);
    } catch (error) {
      console.error(`Failed to update user ${editingUser.id}:`, error);
      // alert(`Error updating user: ${error.message}`); // Removed alert, consider toast notification
      // For now, log the error, but a toast would be better for user feedback.
      console.error(`Error updating user: ${error.message}`);
    }
  };

  const handleOpenAddUserDialog = () => {
    setNewUserData({ name: '', email: '', password: '', roles: ['ROLE_USER'] }); // Reset form
    setIsAddUserDialogOpen(true);
  };

  const handleCreateUser = async () => {
    // TODO: Implement user creation logic
    // This will involve a POST request to a new backend endpoint (e.g., /api/users)
    console.log("Creating user with data:", newUserData);
    // For now, just close the dialog
    alert('Create user functionality to be implemented with backend.');
    setIsAddUserDialogOpen(false);
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
          <Button onClick={handleOpenAddUserDialog}>
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
                        <Button variant="ghost" size="sm" onClick={() => handleEditUser(user)}>
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
                <TableCell colSpan={4}>Total Users</TableCell>
                <TableCell className="text-right">{filteredUsers.length}</TableCell>
              </TableRow>
            </TableFooter>
          </Table>
        </div>
      )}

      {/* Edit User Dialog */}
      {editingUser && (
        <Dialog open={isEditDialogOpen} onOpenChange={setIsEditDialogOpen}>
          <DialogContent className="sm:max-w-[425px]">
            <DialogHeader>
              <DialogTitle>Edit User: {editingUser.name || editingUser.email}</DialogTitle>
              <DialogDescription>
                Make changes to the user's profile here. Click save when you're done.
              </DialogDescription>
            </DialogHeader>
            <div className="grid gap-4 py-4">
              <div className="grid grid-cols-4 items-center gap-4">
                <Label htmlFor="name" className="text-right">
                  Name
                </Label>
                <Input 
                  id="name" 
                  value={editFormData.name}
                  onChange={(e) => setEditFormData({...editFormData, name: e.target.value})}
                  className="col-span-3" 
                />
              </div>
              <div className="grid grid-cols-4 items-center gap-4">
                <Label htmlFor="email-display" className="text-right">
                  Email
                </Label>
                <Input 
                  id="email-display" 
                  value={editFormData.email}
                  className="col-span-3" 
                  disabled // Email is displayed but not editable through this form
                />
              </div>
              <div className="grid grid-cols-4 items-center gap-4">
                <Label htmlFor="password" className="text-right">
                  New Password
                </Label>
                <Input 
                  id="password" 
                  type="password"
                  placeholder="Leave blank to keep current password"
                  value={editFormData.password}
                  onChange={(e) => setEditFormData({...editFormData, password: e.target.value})}
                  className="col-span-3" 
                />
              </div>
              {/* Role Editing Section */}
              {currentUser && editingUser && (
                <div className="grid grid-cols-4 items-center gap-4">
                  <Label htmlFor="roles" className="text-right">
                    Roles
                  </Label>
                  <div className="col-span-3 space-y-2">
                    <div className="flex items-center space-x-2">
                      <Checkbox 
                        id="role-admin"
                        checked={editFormData.roles.includes('ROLE_ADMIN')}
                        onCheckedChange={(checked) => {
                          const newRoles = checked 
                            ? [...editFormData.roles, 'ROLE_ADMIN'] 
                            : editFormData.roles.filter(role => role !== 'ROLE_ADMIN');
                          setEditFormData({...editFormData, roles: Array.from(new Set(newRoles)) });
                        }}
                        disabled={editingUser.id === currentUser.id} // Safeguard: Admin cannot change their own roles
                      />
                      <Label htmlFor="role-admin" className="font-normal">
                        Administrator
                        {editingUser.id === currentUser.id && <span className="text-xs text-muted-foreground ml-1">(Cannot change own role)</span>}
                      </Label>
                    </div>
                    {/* Add other roles like ROLE_EDITOR here if needed */}
                    {/* Example for ROLE_USER (though usually managed by backend) */}
                    {/* <div className="flex items-center space-x-2">
                      <Checkbox 
                        id="role-user"
                        checked={editFormData.roles.includes('ROLE_USER')}
                        disabled // ROLE_USER is typically a base role and not directly toggled here
                      />
                      <Label htmlFor="role-user" className="font-normal text-muted-foreground">
                        User (Base Role)
                      </Label>
                    </div> */}
                  </div>
                </div>
              )}
            </div>
            <DialogFooter>
              <DialogClose asChild>
                <Button type="button" variant="outline">Cancel</Button>
              </DialogClose>
              <Button type="button" onClick={handleSaveUserUpdate}>Save changes</Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      )}

      {/* Add User Dialog */}
      {isAddUserDialogOpen && (
        <Dialog open={isAddUserDialogOpen} onOpenChange={setIsAddUserDialogOpen}>
          <DialogContent className="sm:max-w-[425px]">
            <DialogHeader>
              <DialogTitle>Add New User</DialogTitle>
              <DialogDescription>
                Enter the details for the new user. Default role is 'User'.
              </DialogDescription>
            </DialogHeader>
            <div className="grid gap-4 py-4">
              <div className="grid grid-cols-4 items-center gap-4">
                <Label htmlFor="new-name" className="text-right">Name</Label>
                <Input 
                  id="new-name" 
                  value={newUserData.name}
                  onChange={(e) => setNewUserData({...newUserData, name: e.target.value})}
                  className="col-span-3" 
                  placeholder="Full Name"
                />
              </div>
              <div className="grid grid-cols-4 items-center gap-4">
                <Label htmlFor="new-email" className="text-right">Email</Label>
                <Input 
                  id="new-email" 
                  type="email"
                  value={newUserData.email}
                  onChange={(e) => setNewUserData({...newUserData, email: e.target.value})}
                  className="col-span-3" 
                  placeholder="user@example.com"
                />
              </div>
              <div className="grid grid-cols-4 items-center gap-4">
                <Label htmlFor="new-password" className="text-right">Password</Label>
                <Input 
                  id="new-password" 
                  type="password"
                  value={newUserData.password}
                  onChange={(e) => setNewUserData({...newUserData, password: e.target.value})}
                  className="col-span-3" 
                  placeholder="Min. 6 characters"
                />
              </div>
              <div className="grid grid-cols-4 items-center gap-4">
                <Label htmlFor="new-roles" className="text-right">Roles</Label>
                <div className="col-span-3 space-y-2">
                  <div className="flex items-center space-x-2">
                    <Checkbox 
                      id="new-role-admin"
                      checked={newUserData.roles.includes('ROLE_ADMIN')}
                      onCheckedChange={(checked) => {
                        const updatedRoles = checked 
                          ? [...newUserData.roles, 'ROLE_ADMIN'] 
                          : newUserData.roles.filter(role => role !== 'ROLE_ADMIN');
                        // Ensure ROLE_USER is always present if other roles are removed
                        if (!updatedRoles.includes('ROLE_USER') && updatedRoles.length === 0) {
                            updatedRoles.push('ROLE_USER');
                        } else if (!updatedRoles.includes('ROLE_USER') && updatedRoles.includes('ROLE_ADMIN')) {
                            updatedRoles.push('ROLE_USER'); // Ensure user has ROLE_USER if admin
                        }
                        setNewUserData({...newUserData, roles: Array.from(new Set(updatedRoles)) });
                      }}
                    />
                    <Label htmlFor="new-role-admin" className="font-normal">Administrator</Label>
                  </div>
                  {/* ROLE_USER is implicitly added or managed by backend, display for info */}
                  <div className="flex items-center space-x-2">
                    <Checkbox 
                      id="new-role-user"
                      checked={newUserData.roles.includes('ROLE_USER')}
                      disabled // Usually, ROLE_USER is a base role
                    />
                    <Label htmlFor="new-role-user" className="font-normal text-muted-foreground">User (Base)</Label>
                  </div>
                </div>
              </div>
            </div>
            <DialogFooter>
              <DialogClose asChild>
                <Button type="button" variant="outline">Cancel</Button>
              </DialogClose>
              <Button type="button" onClick={handleCreateUser}>Create User</Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      )}
    </div>
  );
}
