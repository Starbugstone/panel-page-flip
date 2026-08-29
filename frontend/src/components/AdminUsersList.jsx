import { useMemo, useState } from "react";
import { AdminWarnDialog } from "@/components/AdminWarnDialog";
import { AdminConfirmDialog } from "@/components/admin/AdminConfirmDialog";
import { AdminUserCreateDialog } from "@/components/admin/AdminUserCreateDialog";
import { AdminUserEditDialog } from "@/components/admin/AdminUserEditDialog";
import { AdminUsersTable } from "@/components/admin/AdminUsersTable";
import { AdminUsersToolbar } from "@/components/admin/AdminUsersToolbar";
import { useAdminList } from "@/hooks/use-admin-list";
import { useAdminUserActions } from "@/hooks/use-admin-user-actions";
import { useAuth } from "@/hooks/use-auth";
import { ROLE_USER } from "@/lib/admin-user-roles";

const BLANK_NEW_USER = { name: "", email: "", password: "", roles: [ROLE_USER] };

/**
 * Every account on the installation, or only the ones still unverified.
 *
 * Both tabs are this component with a different filter, so the two can never
 * disagree about what a row looks like or what can be done to it.
 */
export function AdminUsersList({ showOnlyUnverified = false }) {
  const { user: currentUser } = useAuth();
  const [editingUser, setEditingUser] = useState(null);
  const [editForm, setEditForm] = useState({ name: "", email: "", password: "", roles: [] });
  const [newUser, setNewUser] = useState(null);
  const [confirmAction, setConfirmAction] = useState(null);
  const [warningTarget, setWarningTarget] = useState(null);

  const filters = useMemo(() => (showOnlyUnverified ? { verified: "false" } : {}), [showOnlyUnverified]);

  const { items: users, setItems: setUsers, pagination, isLoading, searchInput, setSearch, setPage, setLimit, reload } = useAdminList({
    basePath: "/api/users",
    filters,
    // Separate keys so the pending and users tabs do not share a page number.
    urlKey: showOnlyUnverified ? "pending" : "users",
    itemsKey: "users",
    errorTitle: "Failed to load users",
  });

  const actions = useAdminUserActions({ reload, setUsers, showOnlyUnverified, currentUser });

  const openEditor = (user) => {
    // Password stays blank: it is set here, never read back.
    setEditForm({
      name: user.name || "",
      email: user.email || "",
      password: "",
      roles: Array.isArray(user.roles) ? [...user.roles] : [],
    });
    setEditingUser(user);
  };

  const confirmDelete = (user) => setConfirmAction({
    title: "Delete user?",
    description: `Delete ${user.name || user.email}. Accounts that own comics cannot be deleted until their comics are explicitly removed. This cannot be undone.`,
    onConfirm: () => actions.deleteUser(user.id),
  });

  return (
    <div className="space-y-4">
      <AdminUsersToolbar
        title={showOnlyUnverified ? "Pending Verifications" : "Users Management"}
        description={showOnlyUnverified
          ? "Review unverified accounts, resend email verification links, or manually verify accounts."
          : null}
        searchPlaceholder={showOnlyUnverified ? "Search pending users..." : "Search users..."}
        searchInput={searchInput}
        onSearch={setSearch}
        onAddUser={showOnlyUnverified ? null : () => setNewUser(BLANK_NEW_USER)}
      />

      {/* The spinner replaces the table only on the first load. Turning a page
          keeps the table and its pager on screen, disabled, rather than
          collapsing the layout and moving the button under the cursor. */}
      {isLoading && users.length === 0 ? (
        <div className="flex justify-center p-8">
          <div className="h-8 w-8 animate-spin rounded-full border-b-2 border-t-2 border-primary" />
        </div>
      ) : (
        <AdminUsersTable
          users={users}
          pagination={pagination}
          isLoading={isLoading}
          emptyMessage={showOnlyUnverified ? "No pending verifications found" : "No users found matching your search"}
          label={showOnlyUnverified ? "pending users" : "users"}
          onPageChange={setPage}
          onLimitChange={setLimit}
          rowActions={{
            onEdit: openEditor,
            onWarn: setWarningTarget,
            onDelete: confirmDelete,
            onVerify: (user) => actions.verifyUser(user.id),
            onResendVerification: actions.resendVerification,
          }}
        />
      )}

      <AdminUserEditDialog
        open={Boolean(editingUser)}
        onOpenChange={(open) => { if (!open) setEditingUser(null); }}
        user={editingUser}
        form={editForm}
        onChange={setEditForm}
        currentUser={currentUser}
        onSave={async () => {
          if (await actions.saveUser(editForm, editingUser)) setEditingUser(null);
        }}
      />

      {newUser && (
        <AdminUserCreateDialog
          open
          onOpenChange={(open) => { if (!open) setNewUser(null); }}
          form={newUser}
          onChange={setNewUser}
          onCreate={async () => {
            if (await actions.createUser(newUser)) setNewUser(null);
          }}
        />
      )}

      <AdminConfirmDialog action={confirmAction} onClear={() => setConfirmAction(null)} />

      <AdminWarnDialog
        target={warningTarget ? { userId: warningTarget.id } : null}
        subjectLabel={warningTarget ? (warningTarget.name || warningTarget.email) : undefined}
        recipientLabel={warningTarget ? (warningTarget.name || warningTarget.email) : undefined}
        onClose={() => setWarningTarget(null)}
      />
    </div>
  );
}
