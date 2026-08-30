import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { AdminPagination } from "@/components/AdminPagination";
import { AdminUserRow } from "@/components/admin/AdminUserRow";
import { SelectAllCheckbox } from "@/components/SelectionCheckbox";

const COLUMNS = ["Name / Email", "Role", "Verified?", "Created", "Last login", "Comics", "Storage", "Actions"];

const headClass = (column) => {
  if (column === "Storage") return "w-[13rem]";
  return column === "Actions" ? "text-right" : undefined;
};

/**
 * The accounts on the current page, with the pager that moves between them.
 *
 * The pager stays mounted while a page loads rather than being replaced by a
 * spinner, so the layout does not collapse and move the next button out from
 * under the cursor.
 */
export function AdminUsersTable({ users, selection, pagination, isLoading, emptyMessage, label, onPageChange, onLimitChange, rowActions }) {
  return (
    <div className="overflow-x-auto rounded-md border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead className="w-12">
              <SelectAllCheckbox state={selection.headerState} onToggleAll={selection.toggleAll} label={`Select all ${label}`} />
            </TableHead>
            {COLUMNS.map((column) => <TableHead key={column} className={headClass(column)}>{column}</TableHead>)}
          </TableRow>
        </TableHeader>
        <TableBody>
          {users.length > 0 ? users.map((user) => (
            <AdminUserRow
              key={user.id}
              user={user}
              checked={selection.isChecked(user)}
              onToggle={(checked, options) => selection.toggle(user.id, checked, options)}
              onEdit={() => rowActions.onEdit(user)}
              onWarn={() => rowActions.onWarn(user)}
              onDelete={() => rowActions.onDelete(user)}
              onVerify={() => rowActions.onVerify(user)}
              onResendVerification={() => rowActions.onResendVerification(user)}
            />
          )) : (
            <TableRow>
              <TableCell colSpan={COLUMNS.length + 1} className="py-8 text-center">{emptyMessage}</TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
      <AdminPagination
        pagination={pagination}
        itemCount={users.length}
        isLoading={isLoading}
        onPageChange={onPageChange}
        onLimitChange={onLimitChange}
        label={label}
      />
    </div>
  );
}
