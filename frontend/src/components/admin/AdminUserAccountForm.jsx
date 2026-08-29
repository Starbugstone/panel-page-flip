import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ROLE_ADMIN, toggleRole } from "@/lib/admin-user-roles";
import { validatePassword } from "@/lib/password-policy";

/**
 * The account's own settings.
 *
 * The email address is shown but not editable — it is the identity the account
 * verified — and an administrator cannot take away their own administrator
 * role, which is the only thing standing between a mis-click and an
 * installation with nobody able to reach its admin screens.
 */
export function AdminUserAccountForm({ user, form, onChange, isSelf, isSaving, onSave }) {
  const passwordErrors = form.password ? validatePassword(form.password) : [];
  const update = (changes) => onChange((current) => ({ ...current, ...changes }));

  return (
    <Card>
      <CardHeader>
        <CardTitle>Account settings</CardTitle>
        <CardDescription>Change the display name, reset the password, or adjust roles.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="grid gap-2 sm:max-w-md">
          <Label htmlFor="admin-user-name">Name</Label>
          <Input
            id="admin-user-name"
            value={form.name}
            onChange={(event) => update({ name: event.target.value })}
          />
        </div>
        <div className="grid gap-2 sm:max-w-md">
          <Label htmlFor="admin-user-email">Email</Label>
          <Input id="admin-user-email" value={user.email} disabled />
        </div>
        <div className="grid gap-2 sm:max-w-md">
          <Label htmlFor="admin-user-password">New password</Label>
          <Input
            id="admin-user-password"
            type="password"
            placeholder="Leave blank to keep the current password"
            value={form.password}
            onChange={(event) => update({ password: event.target.value })}
          />
          {form.password && passwordErrors.length > 0 && (
            <p className="text-sm text-muted-foreground">
              Password must include: {passwordErrors.join(", ")}.
            </p>
          )}
        </div>

        <div className="flex items-center gap-2">
          <Checkbox
            id="admin-user-role-admin"
            checked={form.roles.includes(ROLE_ADMIN)}
            disabled={isSelf}
            onCheckedChange={(checked) => update({ roles: toggleRole(form.roles, ROLE_ADMIN, checked) })}
          />
          <Label htmlFor="admin-user-role-admin" className="font-normal">
            Administrator
            {isSelf && <span className="ml-1 text-xs text-muted-foreground">(cannot change your own role)</span>}
          </Label>
        </div>

        {/* Withdrawing this stops the account spending provider allowance. It
            does not touch metadata read from their own files or filenames,
            neither of which leaves the server. */}
        <div className="flex items-center gap-2">
          <Checkbox
            id="admin-user-metadata-api"
            checked={form.metadataApiEnabled}
            onCheckedChange={(checked) => update({ metadataApiEnabled: checked === true })}
          />
          <Label htmlFor="admin-user-metadata-api" className="font-normal">
            May look comics up with external metadata providers
            {user.hasPersonalMetadataCredential && (
              <span className="ml-1 text-xs text-muted-foreground">(has their own token)</span>
            )}
          </Label>
        </div>

        <Button onClick={onSave} disabled={isSaving}>{isSaving ? "Saving…" : "Save changes"}</Button>
      </CardContent>
    </Card>
  );
}
