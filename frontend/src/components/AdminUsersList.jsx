import { useMemo, useState } from "react";
import { AlertTriangle, BadgeCheck, Mail, Trash } from "lucide-react";
import { AdminWarnDialog } from "@/components/AdminWarnDialog";
import { AdminBulkActionsBar } from "@/components/admin/AdminBulkActionsBar";
import { AdminConfirmDialog } from "@/components/admin/AdminConfirmDialog";
import { AdminUserCreateDialog } from "@/components/admin/AdminUserCreateDialog";
import { AdminUserEditDialog } from "@/components/admin/AdminUserEditDialog";
import { AdminUsersTable } from "@/components/admin/AdminUsersTable";
import { AdminUsersToolbar } from "@/components/admin/AdminUsersToolbar";
import { useAdminBulkAction } from "@/hooks/use-admin-bulk-action";
import { useAdminList } from "@/hooks/use-admin-list";
import { useAdminTableControls } from "@/hooks/use-admin-table-controls";
import { useAdminUserActions } from "@/hooks/use-admin-user-actions";
import { useAuth } from "@/hooks/use-auth";
import { useRowSelection } from "@/hooks/use-row-selection";
import { api } from "@/lib/api";
import { ROLE_USER } from "@/lib/admin-user-roles";
import { pluralize, summariseLabels } from "@/lib/bulk-actions";

const BLANK_NEW_USER = { name: "", email: "", password: "", roles: [ROLE_USER] };

const nameOf = (user) => user.name || user.email;

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
  // Always a list, so one dialog serves the row button and the bulk button and
  // the two cannot drift apart in what they say or what they send.
  const [warningTargets, setWarningTargets] = useState([]);

  const tableControls = useAdminTableControls({ defaultSort: "createdAt" });

  const filters = useMemo(() => ({
    ...(showOnlyUnverified ? { verified: "false" } : {}),
    ...tableControls.query,
  }), [showOnlyUnverified, tableControls.query]);

  const { items: users, setItems: setUsers, listKey, pagination, isLoading, searchInput, setSearch, setPage, setLimit, reload } = useAdminList({
    basePath: "/api/users",
    filters,
    // Separate keys so the pending and users tabs do not share a page number.
    urlKey: showOnlyUnverified ? "pending" : "users",
    itemsKey: "users",
    errorTitle: "Failed to load users",
  });

  const actions = useAdminUserActions({ reload, setUsers, showOnlyUnverified, currentUser });
  const selection = useRowSelection({ rows: users, resetKey: listKey });
  const bulk = useAdminBulkAction({ reload });

  // The server refuses both of these against your own account, and being told
  // so once per row is worse than not offering it.
  const notSelf = selection.selectedRows.filter((user) => user.id !== currentUser?.id);
  const selfExcluded = "your own account is never included";

  const bulkActions = [
    {
      key: "warn",
      label: "Warn selected",
      icon: AlertTriangle,
      eligible: notSelf,
      ineligibleReason: selfExcluded,
      onClick: () => setWarningTargets(notSelf),
    },
    ...(showOnlyUnverified ? [
      {
        key: "verify",
        label: "Verify selected",
        icon: BadgeCheck,
        eligible: selection.selectedRows,
        onClick: () => bulk.run(
          selection.selectedRows,
          (user) => api.post(`/api/users/${user.id}/verify`, {}),
          { noun: "account", verbPast: "verified", labelOf: nameOf }
        ),
      },
      {
        key: "resend",
        label: "Resend verification",
        icon: Mail,
        eligible: selection.selectedRows,
        onClick: () => bulk.run(
          selection.selectedRows,
          (user) => api.post("/api/email-verification/resend", { email: user.email }),
          { noun: "email", verbPast: "sent", labelOf: nameOf }
        ),
      },
    ] : []),
    {
      key: "delete",
      label: "Delete selected",
      icon: Trash,
      variant: "destructive",
      eligible: notSelf,
      ineligibleReason: selfExcluded,
      onClick: () => setConfirmAction({
        title: `Delete ${pluralize(notSelf.length, "user")}?`,
        description: `Delete ${summariseLabels(notSelf, nameOf)}. Accounts that own comics cannot be deleted until their comics are explicitly removed, and those will be reported and skipped. This cannot be undone.`,
        onConfirm: () => bulk.run(
          notSelf,
          (user) => api.delete(`/api/users/${user.id}`),
          { noun: "user", verbPast: "deleted", labelOf: nameOf }
        ),
      }),
    },
  ];

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
        <>
          <AdminBulkActionsBar
            selectedCount={selection.selectedCount}
            totalCount={users.length}
            noun={showOnlyUnverified ? "pending user" : "user"}
            actions={bulkActions}
            progress={bulk.progress}
            onClear={selection.clear}
          />
          <AdminUsersTable
            users={users}
            selection={selection}
            pagination={pagination}
            isLoading={isLoading}
            emptyMessage={showOnlyUnverified ? "No pending verifications found" : "No users found matching your search"}
            label={showOnlyUnverified ? "pending users" : "users"}
            onPageChange={setPage}
            onLimitChange={setLimit}
            rowActions={{
              onEdit: openEditor,
              onWarn: (user) => setWarningTargets([user]),
              onDelete: confirmDelete,
              onVerify: (user) => actions.verifyUser(user.id),
              onResendVerification: actions.resendVerification,
            }}
            tableControls={tableControls}
          />
        </>
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
        targets={warningTargets.map((user) => ({ target: { userId: user.id }, label: nameOf(user) }))}
        subjectLabel={describeWarnTargets(warningTargets)}
        recipientLabel={describeWarnTargets(warningTargets)}
        onClose={() => setWarningTargets([])}
      />
    </div>
  );
}

function describeWarnTargets(users) {
  if (users.length === 0) return undefined;
  return users.length === 1 ? nameOf(users[0]) : pluralize(users.length, "user");
}
