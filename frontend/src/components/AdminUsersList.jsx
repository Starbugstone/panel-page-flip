
import { useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Table, TableHeader, TableBody, TableHead, TableRow, TableCell } from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import {
  Search,
  UserPlus,
  UserRoundCog,
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
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox"; // Assuming Checkbox component is available
import { useAuth } from "@/hooks/use-auth"; // Import useAuth hook
import { useToast } from "@/hooks/use-toast";
import { useAdminList } from "@/hooks/use-admin-list";
import { AdminPagination } from "@/components/AdminPagination";
import { api } from "@/lib/api";
import { validatePassword } from "@/lib/password-policy";
import { formatDateTime } from "@/lib/format";

export function AdminUsersList({ showOnlyUnverified = false }) {
  const { toast } = useToast();
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [editingUser, setEditingUser] = useState(null);
  const [editFormData, setEditFormData] = useState({ name: '', email: '', password: '', roles: [] });
  const [confirmAction, setConfirmAction] = useState(null);
  const { user: currentUser } = useAuth(); // Get the currently logged-in user

  // State for Add User Dialog
  const [isAddUserDialogOpen, setIsAddUserDialogOpen] = useState(false);
  const [newUserData, setNewUserData] = useState({ name: '', email: '', password: '', roles: ['ROLE_USER'] });
  const newUserPasswordErrors = newUserData.password ? validatePassword(newUserData.password) : [];
  const editPasswordErrors = editFormData.password ? validatePassword(editFormData.password) : [];

  const title = showOnlyUnverified ? "Pending Verifications" : "Users Management";
  const emptyMessage = showOnlyUnverified ? "No pending verifications found" : "No users found matching your search";
  const searchPlaceholder = showOnlyUnverified ? "Search pending users..." : "Search users...";

  const filters = useMemo(
    () => (showOnlyUnverified ? { verified: "false" } : {}),
    [showOnlyUnverified]
  );

  const {
    items: users,
    setItems: setUsers,
    pagination,
    isLoading,
    searchInput,
    setSearch,
    setPage,
    setLimit,
    reload,
  } = useAdminList({
    basePath: "/api/users",
    filters,
    // Separate keys so the pending and users tabs do not share a page number.
    urlKey: showOnlyUnverified ? "pending" : "users",
    itemsKey: "users",
    errorTitle: "Failed to load users",
  });

  const handleDeleteUser = async (userId) => {
    try {
      await api.delete(`/api/users/${userId}`);
      // Reload rather than splice: a row leaving this page pulls one up from
      // the next, and the total has changed.
      reload();
      toast({ title: "User deleted" });
    } catch (error) {
      toast({ title: "Delete failed", description: error.message, variant: "destructive" });
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
      if (editPasswordErrors.length > 0) {
        toast({
          title: "Password does not meet policy",
          description: editPasswordErrors.join(", "),
          variant: "destructive",
        });
        return;
      }
      payload.password = editFormData.password.trim();
    }

    // Handle roles update, but not if admin is editing themselves
    const rolesChanged = JSON.stringify([...editFormData.roles].sort()) !== JSON.stringify([...(editingUser.roles || [])].sort());
    if (currentUser && editingUser.id !== currentUser.id && rolesChanged) {
      // Ensure ROLE_USER is always present, and remove duplicates
      const newRoles = Array.from(new Set([...editFormData.roles, 'ROLE_USER']));
      payload.roles = newRoles;
    } else if (rolesChanged && editingUser.id === currentUser.id) {
      // Optionally, provide feedback to the user that their own roles cannot be changed here.
    }

    // If nothing to update, just close the dialog
    if (Object.keys(payload).length === 0) {
      setIsEditDialogOpen(false);
      return;
    }

    try {
      const updatedUserResponse = await api.put(`/api/users/${editingUser.id}`, payload);
      setUsers((currentUsers) => currentUsers.map((user) => (
        user.id === editingUser.id ? { ...user, ...updatedUserResponse.user } : user
      )));
      setIsEditDialogOpen(false);
      setEditingUser(null); // Clear editing state
      toast({ title: "User updated" });
    } catch (error) {
      toast({ title: "Update failed", description: error.message, variant: "destructive" });
    }
  };

  const handleOpenAddUserDialog = () => {
    setNewUserData({ name: '', email: '', password: '', roles: ['ROLE_USER'] }); // Reset form
    setIsAddUserDialogOpen(true);
  };

  const handleCreateUser = async () => {
    if (!newUserData.email || !newUserData.password || !newUserData.name) {
      toast({ title: "Missing fields", description: "Name, email and password are required.", variant: "destructive" });
      return;
    }

    if (newUserPasswordErrors.length > 0) {
      toast({
        title: "Password does not meet policy",
        description: newUserPasswordErrors.join(", "),
        variant: "destructive",
      });
      return;
    }

    try {
      const data = await api.post("/api/users", newUserData);
      reload();
      setIsAddUserDialogOpen(false);
      toast({ title: "User created", description: `${data.user.email} can log in immediately.` });
    } catch (error) {
      toast({ title: "Create user failed", description: error.message, variant: "destructive" });
    }
  };

  const handleVerifyUser = async (userId) => {
    try {
      const data = await api.post(`/api/users/${userId}/verify`, {});
      if (showOnlyUnverified) {
        // The row no longer belongs on this list, so the page has to be refilled.
        reload();
      } else {
        setUsers((currentUsers) => currentUsers.map(
          (user) => user.id === userId ? { ...user, ...data.user } : user
        ));
      }
      toast({ title: "User verified" });
    } catch (error) {
      toast({ title: "Verification failed", description: error.message, variant: "destructive" });
    }
  };

  const handleResendVerification = async (user) => {
    try {
      await api.post("/api/email-verification/resend", { email: user.email });
      toast({ title: "Verification email sent" });
    } catch (error) {
      toast({ title: "Resend failed", description: error.message, variant: "destructive" });
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex justify-between items-center">
        <div>
          <h2 className="text-xl font-bold">{title}</h2>
          {showOnlyUnverified && (
            <p className="text-sm text-muted-foreground">
              Review unverified accounts, resend email verification links, or manually verify accounts.
            </p>
          )}
        </div>
        <div className="flex items-center gap-4">
          <div className="relative">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              type="search"
              placeholder={searchPlaceholder}
              className="pl-8 w-[250px]"
              value={searchInput}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          {!showOnlyUnverified && (
            <Button onClick={handleOpenAddUserDialog}>
              <UserPlus className="mr-2 h-4 w-4" />
              Add User
            </Button>
          )}
        </div>
      </div>
      
      {/* The spinner replaces the table only on the first load. Turning a page
          keeps the table and its pager on screen, disabled, rather than
          collapsing the layout and moving the button under the cursor. */}
      {isLoading && users.length === 0 ? (
        <div className="flex justify-center p-8">
          <div className="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-primary"></div>
        </div>
      ) : (
        <div className="overflow-x-auto border rounded-md">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Name / Email</TableHead>
                <TableHead>Role</TableHead>
                <TableHead>Verified?</TableHead>
                <TableHead>Created</TableHead>
                <TableHead>Last login</TableHead>
                <TableHead>Comics</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {users.length > 0 ? (
                users.map((user) => (
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
                    <TableCell>
                      {user.isEmailVerified ? (
                        <Badge variant="outline">Verified</Badge>
                      ) : (
                        <Badge variant="destructive">Pending</Badge>
                      )}
                    </TableCell>
                    <TableCell>{formatDateTime(user.createdAt)}</TableCell>
                    <TableCell>{formatDateTime(user.lastLoginAt, "Never")}</TableCell>
                    <TableCell>{user.comicCount}</TableCell>
                    <TableCell className="text-right">
                      <div className="flex justify-end gap-2">
                        <Button
                          variant="ghost"
                          size="sm"
                          aria-label={`Edit ${user.name || user.email}`}
                          title="Edit user"
                          onClick={() => handleEditUser(user)}
                        >
                          <Edit className="h-4 w-4" />
                        </Button>
                        {/* Replaces the cog that used to promote a user to
                            administrator on a single click. Role changes are
                            already in the edit dialog, where they are harder to
                            hit by accident. */}
                        <Button
                          variant="ghost"
                          size="sm"
                          asChild
                          title="Manage user"
                        >
                          <Link
                            to={`/admin/users/${user.id}`}
                            aria-label={`Manage ${user.name || user.email}`}
                          >
                            <UserRoundCog className="h-4 w-4" />
                          </Link>
                        </Button>
                        {!user.isEmailVerified && (
                          <>
                            <Button variant="ghost" size="sm" onClick={() => handleResendVerification(user)}>
                              Resend
                            </Button>
                            <Button variant="ghost" size="sm" onClick={() => handleVerifyUser(user.id)}>
                              Verify
                            </Button>
                          </>
                        )}
                        <Button
                          variant="ghost"
                          size="sm"
                          aria-label={`Delete ${user.name || user.email}`}
                          title="Delete user"
                          onClick={() => setConfirmAction({
                            title: "Delete user?",
                            description: `Delete ${user.name || user.email}. This cannot be undone.`,
                            onConfirm: () => handleDeleteUser(user.id),
                          })}
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
                    {emptyMessage}
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
          <AdminPagination
            pagination={pagination}
            itemCount={users.length}
            isLoading={isLoading}
            onPageChange={setPage}
            onLimitChange={setLimit}
            label={showOnlyUnverified ? "pending users" : "users"}
          />
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
              {editFormData.password && editPasswordErrors.length > 0 && (
                <p className="col-span-4 text-sm text-muted-foreground">
                  Password must include: {editPasswordErrors.join(", ")}.
                </p>
              )}
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
                  placeholder="Min. 12 characters with mixed case, digit and symbol"
                />
              </div>
              {newUserData.password && newUserPasswordErrors.length > 0 && (
                <p className="col-span-4 text-sm text-muted-foreground">
                  Password must include: {newUserPasswordErrors.join(", ")}.
                </p>
              )}
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
      <AlertDialog open={!!confirmAction} onOpenChange={(open) => !open && setConfirmAction(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{confirmAction?.title}</AlertDialogTitle>
            <AlertDialogDescription>{confirmAction?.description}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={() => {
              const action = confirmAction?.onConfirm;
              setConfirmAction(null);
              action?.();
            }}>
              Confirm
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
