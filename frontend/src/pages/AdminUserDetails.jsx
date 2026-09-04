import { PageLayout, PageHeader, PageLoading } from "@/components/layout/PageLayout";
import { useState } from "react";
import { Link, useParams } from "react-router-dom";
import { ArrowLeft } from "lucide-react";

import { AdminComicsList } from "@/components/AdminComicsList";
import { AdminTagsList } from "@/components/AdminTagsList";
import { AdminUserAccountForm } from "@/components/admin/AdminUserAccountForm";
import { AdminUserDangerZone } from "@/components/admin/AdminUserDangerZone";
import { AdminUserDialogs } from "@/components/admin/AdminUserDialogs";
import { AdminUserOverviewTab } from "@/components/admin/AdminUserOverviewTab";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { useAdminUserDetails } from "@/hooks/use-admin-user-details";
import { ROLE_ADMIN } from "@/lib/admin-user-roles";

/**
 * Everything an administrator needs about one account, in one place.
 *
 * Reached from the Manage user button that replaced the promote-to-admin cog:
 * promotion is a role change like any other and belongs in the account form,
 * not on a single click in a table row.
 *
 * Remounted per account. Navigating from one user to another has to start from
 * nothing: leaving the previous account's name and roles in the form while the
 * next one loads means Save would post them to the new user's id. Clearing each
 * piece of state in an effect would do it a render too late; a new instance has
 * nothing to clear.
 */
export default function AdminUserDetails() {
  const { userId } = useParams();
  return <AdminUserDetailsPage key={userId} userId={userId} />;
}

function BackToUsers({ className }) {
  return (
    <Button asChild variant="outline" className={className}>
      {/* The users tab keeps its own page and search in the query string, so
          the browser's history entry restores exactly what was on screen. */}
      <Link to="/admin?tab=users"><ArrowLeft className="mr-2 h-4 w-4" /> Back to users</Link>
    </Button>
  );
}

function AdminUserDetailsPage({ userId }) {
  const details = useAdminUserDetails(userId);
  const [isDeleteOpen, setIsDeleteOpen] = useState(false);
  const [isRotateOpen, setIsRotateOpen] = useState(false);
  const { user } = details;

  if (details.isLoading) return <PageLayout><PageLoading label="Loading account…" /></PageLayout>;

  if (details.notFound || !user) {
    return (
      <PageLayout width="reading">
        <PageHeader title="User not found" description="This account may already have been deleted." />
        <BackToUsers className="mt-6" />
      </PageLayout>
    );
  }

  return (
    <PageLayout>
      <Button asChild variant="ghost" size="sm" className="-ml-2 mb-4">
        <Link to="/admin?tab=users"><ArrowLeft className="mr-2 h-4 w-4" /> Back to users</Link>
      </Button>

      <PageHeader title={user.name || user.email} description={user.name ? user.email : null}
        actions={<>
          {user.roles?.includes(ROLE_ADMIN) && <Badge>Admin</Badge>}
          {user.isEmailVerified ? <Badge variant="outline">Verified</Badge> : <Badge variant="destructive">Pending verification</Badge>}
        </>}
      />

      <Tabs defaultValue="overview" className="w-full">
        <TabsList className="mb-6 flex h-auto w-full flex-wrap justify-start sm:w-fit">
          <TabsTrigger value="overview">Overview</TabsTrigger>
          <TabsTrigger value="comics">Comics ({user.comicCount ?? 0})</TabsTrigger>
          <TabsTrigger value="tags">Tags ({user.tagCount ?? 0})</TabsTrigger>
          <TabsTrigger value="account">Account</TabsTrigger>
        </TabsList>

        <TabsContent value="overview" className="space-y-6">
          <AdminUserOverviewTab
            user={user}
            onVerify={details.verifyUser}
            onQuotaUpdated={details.updateUser}
          />
        </TabsContent>

        <TabsContent value="comics"><AdminComicsList ownerId={user.id} embedded /></TabsContent>
        <TabsContent value="tags"><AdminTagsList creatorId={user.id} embedded /></TabsContent>

        <TabsContent value="account" className="space-y-6">
          <AdminUserAccountForm
            user={user}
            form={details.form}
            onChange={details.setForm}
            isSelf={details.isSelf}
            isSaving={details.isSaving}
            onSave={details.saveAccount}
          />
          <AdminUserDangerZone
            user={user}
            isSelf={details.isSelf}
            isRotatingCode={details.isRotatingCode}
            onRotate={() => setIsRotateOpen(true)}
            onDelete={() => setIsDeleteOpen(true)}
          />
        </TabsContent>
      </Tabs>

      <AdminUserDialogs
        user={user}
        isRotatingCode={details.isRotatingCode}
        deleting={{
          open: isDeleteOpen,
          onOpenChange: setIsDeleteOpen,
          onConfirm: async () => { await details.deleteUser(); setIsDeleteOpen(false); },
        }}
        rotating={{
          open: isRotateOpen,
          onOpenChange: setIsRotateOpen,
          onConfirm: async () => { await details.rotateUserCode(); setIsRotateOpen(false); },
        }}
      />
    </PageLayout>
  );
}
