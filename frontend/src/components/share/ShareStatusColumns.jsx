import { AdminColumnHeader } from "@/components/admin/AdminColumnHeader";
import { TableHead } from "@/components/ui/table";

export function ShareStatusColumns({ tableControls }) {
  return (
    <>
      <TableHead>
        <AdminColumnHeader
          label="Status"
          sortField="status"
          filterField="filterStatus"
          filterType="select"
          filterOptions={["Accepted", "Pending", "Declined", "Revoked"]}
          filterValue={tableControls.columnFilters.filterStatus}
          {...tableControls.headerProps}
        />
      </TableHead>
      <TableHead>
        <AdminColumnHeader
          label="Shared"
          sortField="createdAt"
          filterField="filterCreatedAt"
          filterType="date"
          filterValue={tableControls.columnFilters.filterCreatedAt}
          {...tableControls.headerProps}
        />
      </TableHead>
      <TableHead className="text-right">Actions</TableHead>
    </>
  );
}
