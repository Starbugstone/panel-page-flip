import { BookOpen, CalendarPlus, Clock, Cloud, HardDrive, Tags } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { UserStorageUsage } from "@/components/UserStorageUsage";
import { AdminStorageQuotaForm } from "@/components/AdminStorageQuotaForm";
import { AdminUserSummaryCard } from "@/components/admin/AdminUserSummaryCard";
import { formatDateTime } from "@/lib/format";

/** What the account is, at a glance, and the one thing this tab can change. */
export function AdminUserOverviewTab({ user, onVerify, onQuotaUpdated }) {
  return (
    <>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <AdminUserSummaryCard icon={BookOpen} label="Comics owned" value={user.comicCount ?? 0} />
        <AdminUserSummaryCard icon={Tags} label="Personal tags" value={user.tagCount ?? 0} />
        <AdminUserSummaryCard icon={CalendarPlus} label="Created" value={formatDateTime(user.createdAt)} />
        <AdminUserSummaryCard icon={Clock} label="Last login" value={formatDateTime(user.lastLoginAt, "Never")} />
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><HardDrive className="h-5 w-5" /> Storage</CardTitle>
          <CardDescription>
            Canonical comic files owned by this account, against the quota uploads are held to.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <UserStorageUsage
            usedBytes={user.storageUsedBytes}
            quotaBytes={user.storageQuotaBytes}
            unmeasuredComicCount={user.unmeasuredComicCount}
            className="max-w-md"
          />
          <AdminStorageQuotaForm user={user} onUpdated={onQuotaUpdated} />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><Cloud className="h-5 w-5" /> Dropbox</CardTitle>
          <CardDescription>
            {user.dropboxConnected
              ? `Connected. Last imported ${formatDateTime(user.dropboxLastSyncedAt, "never")}.`
              : "Not connected."}
          </CardDescription>
        </CardHeader>
      </Card>

      {!user.isEmailVerified && (
        <Card>
          <CardHeader>
            <CardTitle>Email verification pending</CardTitle>
            <CardDescription>This account has not confirmed its email address.</CardDescription>
          </CardHeader>
          <CardContent>
            <Button onClick={onVerify}>Mark as verified</Button>
          </CardContent>
        </Card>
      )}
    </>
  );
}
