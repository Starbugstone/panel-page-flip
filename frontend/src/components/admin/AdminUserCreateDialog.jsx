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
import { ROLE_ADMIN, ROLE_USER, toggleNewUserRole } from "@/lib/admin-user-roles";
import { validatePassword } from "@/lib/password-policy";

/**
 * An account created by an administrator rather than by its owner.
 *
 * It can log in immediately: somebody with admin rights vouching for the
 * address stands in for the verification mail. Create stays disabled until the
 * password would actually be accepted, so the policy is met before the request
 * rather than reported after it.
 */
export function AdminUserCreateDialog({ open, onOpenChange, form, onChange, onCreate }) {
  const errors = form.password ? validatePassword(form.password) : [];
  const canCreate = Boolean(form.name.trim() && form.email.trim() && form.password && errors.length === 0);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[425px]">
        <DialogHeader>
          <DialogTitle>Add New User</DialogTitle>
          <DialogDescription>Enter the details for the new user. Default role is 'User'.</DialogDescription>
        </DialogHeader>
        <div className="grid gap-4 py-4">
          <AdminUserFormField
            id="new-name"
            label="Name"
            required
            placeholder="Full Name"
            value={form.name}
            onChange={(event) => onChange({ ...form, name: event.target.value })}
          />
          <AdminUserFormField
            id="new-email"
            label="Email"
            type="email"
            required
            placeholder="user@example.com"
            value={form.email}
            onChange={(event) => onChange({ ...form, email: event.target.value })}
          />
          <AdminUserFormField
            id="new-password"
            label="Password"
            type="password"
            required
            placeholder="Min. 12 characters with mixed case, digit and symbol"
            value={form.password}
            onChange={(event) => onChange({ ...form, password: event.target.value })}
          />
          <PasswordPolicyHint password={form.password} errors={errors} />

          <div className="grid grid-cols-4 items-center gap-4">
            <Label htmlFor="new-role-admin" className="text-right">Roles</Label>
            <div className="col-span-3 space-y-2">
              <div className="flex items-center space-x-2">
                <Checkbox
                  id="new-role-admin"
                  checked={form.roles.includes(ROLE_ADMIN)}
                  onCheckedChange={(checked) => onChange({ ...form, roles: toggleNewUserRole(form.roles, ROLE_ADMIN, checked) })}
                />
                <Label htmlFor="new-role-admin" className="font-normal">Administrator</Label>
              </div>
              {/* Shown ticked and disabled: the base role is granted whatever
                  this form says, and hiding it would make the list look wrong. */}
              <div className="flex items-center space-x-2">
                <Checkbox id="new-role-user" checked={form.roles.includes(ROLE_USER)} disabled />
                <Label htmlFor="new-role-user" className="font-normal text-muted-foreground">User (Base)</Label>
              </div>
            </div>
          </div>
        </div>
        <DialogFooter>
          <DialogClose asChild><Button type="button" variant="outline">Cancel</Button></DialogClose>
          <Button type="button" disabled={!canCreate} onClick={onCreate}>Create User</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
