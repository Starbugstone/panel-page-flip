import { Link } from "react-router-dom";
import { AlertTriangle, Edit, Trash, UserRoundCog } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { TableCell, TableRow } from "@/components/ui/table";
import { UserStorageUsage } from "@/components/UserStorageUsage";
import { describeRoles } from "@/lib/admin-user-roles";
import { formatDateTime } from "@/lib/format";

/** One account, with everything an administrator can do to it from the list. */
export function AdminUserRow({ user, onEdit, onWarn, onDelete, onVerify, onResendVerification }) {
  const who = user.name || user.email;

  return (
    <TableRow>
      <TableCell>
        <div className="flex flex-col">
          <span className="font-medium">{user.name}</span>
          <span className="text-sm text-muted-foreground">{user.email}</span>
        </div>
      </TableCell>
      <TableCell>
        <div className="flex flex-wrap gap-1">
          {describeRoles(user.roles).map((badge) => (
            <Badge key={badge.role} variant={badge.variant}>{badge.label}</Badge>
          ))}
        </div>
      </TableCell>
      <TableCell>
        {user.isEmailVerified
          ? <Badge variant="outline">Verified</Badge>
          : <Badge variant="destructive">Pending</Badge>}
      </TableCell>
      <TableCell>{formatDateTime(user.createdAt)}</TableCell>
      <TableCell>{formatDateTime(user.lastLoginAt, "Never")}</TableCell>
      <TableCell>{user.comicCount}</TableCell>
      <TableCell>
        <UserStorageUsage
          usedBytes={user.storageUsedBytes}
          quotaBytes={user.storageQuotaBytes}
          unmeasuredComicCount={user.unmeasuredComicCount}
        />
      </TableCell>
      <TableCell className="text-right">
        <div className="flex justify-end gap-2">
          <Button variant="ghost" size="sm" aria-label={`Edit ${who}`} title="Edit user" onClick={onEdit}>
            <Edit className="h-4 w-4" />
          </Button>
          <Button variant="ghost" size="sm" aria-label={`Warn ${who}`} title="Warn user" onClick={onWarn}>
            <AlertTriangle className="h-4 w-4" />
          </Button>
          {/* Replaces the cog that used to promote a user to administrator on a
              single click. Role changes are in the edit dialog, where they are
              harder to hit by accident. */}
          <Button variant="ghost" size="sm" asChild title="Manage user">
            <Link to={`/admin/users/${user.id}`} aria-label={`Manage ${who}`}>
              <UserRoundCog className="h-4 w-4" />
            </Link>
          </Button>
          {!user.isEmailVerified && (
            <>
              <Button variant="ghost" size="sm" onClick={onResendVerification}>Resend</Button>
              <Button variant="ghost" size="sm" onClick={onVerify}>Verify</Button>
            </>
          )}
          <Button variant="ghost" size="sm" aria-label={`Delete ${who}`} title="Delete user" onClick={onDelete}>
            <Trash className="h-4 w-4" />
          </Button>
        </div>
      </TableCell>
    </TableRow>
  );
}
