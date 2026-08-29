import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { AdminUserFormField, PasswordPolicyHint } from "@/components/admin/AdminUserFormField";
import { ROLE_ADMIN, toggleRole } from "@/lib/admin-user-roles";
import { validatePassword } from "@/lib/password-policy";

/**
 * Changing an existing account.
 *
 * The email address is shown but not editable: it is the account's identity and
 * changing it here would bypass the verification that proves somebody owns it.
 * An administrator cannot change their own roles, so nobody can lock the
 * installation out of its own admin screens with one tick.
 */
export function AdminUserEditDialog({ open, onOpenChange, user, form, onChange, onSave, currentUser }) {
  if (!user) return null;

  const errors = form.password ? validatePassword(form.password) : [];
  const editingSelf = Boolean(currentUser) && user.id === currentUser.id;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[425px]">
        <DialogHeader>
          <DialogTitle>Edit User: {user.name || user.email}</DialogTitle>
          <DialogDescription>
            Make changes to the user's profile here. Click save when you're done.
          </DialogDescription>
        </DialogHeader>
        <div className="grid gap-4 py-4">
          <AdminUserFormField
            id="name"
            label="Name"
            value={form.name}
            onChange={(event) => onChange({ ...form, name: event.target.value })}
          />
          <AdminUserFormField id="email-display" label="Email" value={form.email} disabled />
          <AdminUserFormField
            id="password"
            label="New Password"
            type="password"
            placeholder="Leave blank to keep current password"
            value={form.password}
            onChange={(event) => onChange({ ...form, password: event.target.value })}
          />
          <PasswordPolicyHint password={form.password} errors={errors} />

          <div className="grid grid-cols-4 items-center gap-4">
            <Label htmlFor="role-admin" className="text-right">Roles</Label>
            <div className="col-span-3 space-y-2">
              <div className="flex items-center space-x-2">
                <Checkbox
                  id="role-admin"
                  checked={form.roles.includes(ROLE_ADMIN)}
                  onCheckedChange={(checked) => onChange({ ...form, roles: toggleRole(form.roles, ROLE_ADMIN, checked) })}
                  disabled={editingSelf}
                />
                <Label htmlFor="role-admin" className="font-normal">
                  Administrator
                  {editingSelf && <span className="ml-1 text-xs text-muted-foreground">(Cannot change own role)</span>}
                </Label>
              </div>
            </div>
          </div>
        </div>
        <DialogFooter>
          <DialogClose asChild><Button type="button" variant="outline">Cancel</Button></DialogClose>
          <Button type="button" onClick={onSave}>Save changes</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
