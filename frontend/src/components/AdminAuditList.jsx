import { useMemo, useState } from "react";
import { Search } from "lucide-react";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { AdminPagination } from "@/components/AdminPagination";
import { useAdminList } from "@/hooks/use-admin-list";
import { formatDateTime } from "@/lib/format";

const ALL_ACTIONS = "all";

export function AdminAuditList() {
  const [action, setAction] = useState(ALL_ACTIONS);
  const filters = useMemo(
    () => (action === ALL_ACTIONS ? {} : { action }),
    [action]
  );

  const {
    items: logs,
    payload,
    pagination,
    isLoading,
    searchInput,
    setSearch,
    setPage,
    setLimit,
  } = useAdminList({
    basePath: "/api/admin/audit-logs",
    filters,
    urlKey: "audit",
    itemsKey: "logs",
    errorTitle: "Failed to load audit logs",
  });

  const availableActions = payload?.filters?.actions || [];

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 className="text-xl font-bold">Admin Audit Log</h2>
          <p className="text-sm text-muted-foreground">
            The whole history is now searchable, not just the most recent entries.
          </p>
        </div>
        <div className="flex items-center gap-4">
          <div className="relative">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              type="search"
              placeholder="Search audit log..."
              className="pl-8 w-[250px]"
              value={searchInput}
              onChange={(event) => setSearch(event.target.value)}
            />
          </div>
          <Select value={action} onValueChange={setAction}>
            <SelectTrigger className="w-[200px]" aria-label="Filter by action">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL_ACTIONS}>All actions</SelectItem>
              {availableActions.map((option) => (
                <SelectItem key={option} value={option}>{option}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      {/* Spinner only on the first load; turning a page keeps the table and its
          pager on screen, disabled, rather than collapsing the layout. */}
      {isLoading && logs.length === 0 ? (
        <div className="flex justify-center p-8"><div className="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-primary" /></div>
      ) : (
        <div className="border rounded-md">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>When</TableHead>
                <TableHead>Admin</TableHead>
                <TableHead>Action</TableHead>
                <TableHead>Target</TableHead>
                <TableHead>Details</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {logs.length > 0 ? logs.map((log) => (
                <TableRow key={log.id}>
                  <TableCell>{formatDateTime(log.createdAt)}</TableCell>
                  <TableCell>{log.admin?.name || log.admin?.email}</TableCell>
                  <TableCell>{log.action}</TableCell>
                  <TableCell>{log.targetType} {log.targetId || ""}</TableCell>
                  <TableCell className="max-w-[360px] truncate">{log.payload ? JSON.stringify(log.payload) : "N/A"}</TableCell>
                </TableRow>
              )) : (
                <TableRow>
                  <TableCell colSpan={5} className="text-center py-8">
                    {searchInput || action !== ALL_ACTIONS
                      ? "No audit entries match your search"
                      : "No audit logs yet"}
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
          <AdminPagination
            pagination={pagination}
            itemCount={logs.length}
            isLoading={isLoading}
            onPageChange={setPage}
            onLimitChange={setLimit}
            label="entries"
          />
        </div>
      )}
    </div>
  );
}
