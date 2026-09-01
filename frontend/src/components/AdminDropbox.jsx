import { useCallback, useEffect, useMemo, useState } from "react";
import { DownloadCloud, Unplug } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { AdminBulkActionsBar } from "@/components/admin/AdminBulkActionsBar";
import { SelectAllCheckbox, SelectionCheckbox } from "@/components/SelectionCheckbox";
import { useAdminBulkAction } from "@/hooks/use-admin-bulk-action";
import { useRowSelection } from "@/hooks/use-row-selection";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { formatDateTime } from "@/lib/format";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { pluralize, summariseLabels } from "@/lib/bulk-actions";
import { AdminColumnHeader } from "@/components/admin/AdminColumnHeader";
import { useAdminTableControls } from "@/hooks/use-admin-table-controls";
import { filterAndSortAdminRows } from "@/lib/admin-client-table";
import { adminFilterSuggestions, matchesAdminDateRange, matchesAdminIntegerRange } from "@/lib/admin-table-filters";

const nameOf = (user) => user.name || user.email;

export function AdminDropbox() {
  const { toast } = useToast();
  const [users, setUsers] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [busyUserId, setBusyUserId] = useState(null);
  const [confirmDisconnect, setConfirmDisconnect] = useState(false);
  // Bumped on every load, so a selection made against one list of accounts is
  // not carried over to the list that replaces it.
  const [generation, setGeneration] = useState(0);
  const tableControls = useAdminTableControls({ defaultSort: "user", defaultDirection: "ASC" });
  const visibleUsers = useMemo(() => filterAndSortAdminRows(users, tableControls, {
    user: { value: (user) => `${nameOf(user)} ${user.email}` },
    lastSyncedAt: {
      value: (user) => user.lastSyncedAt,
      filter: (value, query) => matchesAdminDateRange(value, query),
    },
    dropboxComicCount: {
      value: (user) => user.dropboxComicCount,
      filter: (value, query) => matchesAdminIntegerRange(value, query),
    },
  }), [tableControls, users]);

  const loadUsers = useCallback(async () => {
    setIsLoading(true);
    try {
      const data = await api.get("/api/admin/dropbox-users");
      setUsers(data.users || []);
      setGeneration((count) => count + 1);
    } catch (error) {
      toast({ title: "Failed to load Dropbox users", description: error.message, variant: "destructive" });
    } finally {
      setIsLoading(false);
    }
  }, [toast]);

  // loadUsers is for the actions below, where flipping the spinner on before
  // the request is exactly right. Mounting asks directly instead, so the first
  // render is not immediately followed by a second, and a response that arrives
  // after this list is gone is dropped rather than applied.
  useEffect(() => {
    let ignore = false;
    api.get("/api/admin/dropbox-users")
      .then((data) => { if (!ignore) setUsers(data.users || []); })
      .catch((error) => {
        if (ignore) return;
        toast({ title: "Failed to load Dropbox users", description: error.message, variant: "destructive" });
      })
      .finally(() => { if (!ignore) setIsLoading(false); });

    return () => { ignore = true; };
  }, [toast]);

  const selection = useRowSelection({ rows: visibleUsers, resetKey: `${generation}|${JSON.stringify(tableControls.query)}` });
  const bulk = useAdminBulkAction({ reload: loadUsers });
  const selected = selection.selectedRows;
  const importedComicMax = Math.max(0, ...users.map((user) => Number(user.dropboxComicCount) || 0));

  const bulkActions = [
    {
      key: "sync",
      label: "Force import selected",
      icon: DownloadCloud,
      eligible: selected,
      onClick: () => bulk.run(
        selected,
        (user) => api.post(`/api/admin/dropbox-users/${user.id}/sync`, {}),
        { noun: "import", verbPast: "completed", labelOf: nameOf }
      ),
    },
    {
      key: "disconnect",
      label: "Disconnect selected",
      icon: Unplug,
      variant: "destructive",
      eligible: selected,
      onClick: () => setConfirmDisconnect(true),
    },
  ];

  const runAction = async (userId, action) => {
    setBusyUserId(userId);
    try {
      await api.post(`/api/admin/dropbox-users/${userId}/${action}`, {});
      toast({ title: action === "sync" ? "Dropbox import completed" : "Dropbox disconnected" });
      await loadUsers();
    } catch (error) {
      toast({ title: "Dropbox action failed", description: error.message, variant: "destructive" });
    } finally {
      setBusyUserId(null);
    }
  };

  if (isLoading) {
    return <div className="flex justify-center p-8"><div className="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-primary" /></div>;
  }

  return (
    <div className="space-y-4">
      <h2 className="text-xl font-bold">Dropbox Imports</h2>
      <AdminBulkActionsBar
        selectedCount={selection.selectedCount}
        totalCount={visibleUsers.length}
        noun="account"
        actions={bulkActions}
        progress={bulk.progress}
        onClear={selection.clear}
      />
      <div className="border rounded-md">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-12">
                <SelectAllCheckbox
                  state={selection.headerState}
                  onToggleAll={selection.toggleAll}
                  label="Select all accounts"
                />
              </TableHead>
              <TableHead><AdminColumnHeader label="User" sortField="user" filterField="user" filterSuggestions={adminFilterSuggestions(users, (user) => [user.name, user.email])} filterValue={tableControls.columnFilters.user} {...tableControls.headerProps} /></TableHead>
              <TableHead><AdminColumnHeader label="Last import" sortField="lastSyncedAt" filterField="lastSyncedAt" filterType="date" emptyDateLabel="Never" filterValue={tableControls.columnFilters.lastSyncedAt} {...tableControls.headerProps} /></TableHead>
              <TableHead><AdminColumnHeader label="Imported comics" sortField="dropboxComicCount" filterField="dropboxComicCount" filterType="range" filterMax={importedComicMax} filterValue={tableControls.columnFilters.dropboxComicCount} {...tableControls.headerProps} /></TableHead>
              <TableHead className="text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {visibleUsers.length > 0 ? visibleUsers.map((user) => (
              <TableRow key={user.id} data-state={selection.isChecked(user) ? "selected" : undefined}>
                <TableCell>
                  <SelectionCheckbox
                    checked={selection.isChecked(user)}
                    onToggle={(checked, options) => selection.toggle(user.id, checked, options)}
                    label={`Select ${nameOf(user)}`}
                  />
                </TableCell>
                <TableCell>{nameOf(user)}<div className="text-sm text-muted-foreground">{user.email}</div></TableCell>
                <TableCell>{formatDateTime(user.lastSyncedAt, "Never")}</TableCell>
                <TableCell>{user.dropboxComicCount}</TableCell>
                <TableCell className="text-right space-x-2">
                  <Button size="sm" variant="outline" disabled={busyUserId === user.id} onClick={() => runAction(user.id, "sync")}>Force import</Button>
                  <Button size="sm" variant="destructive" disabled={busyUserId === user.id} onClick={() => runAction(user.id, "disconnect")}>Disconnect</Button>
                </TableCell>
              </TableRow>
            )) : (
              <TableRow><TableCell colSpan={5} className="text-center py-8">No connected Dropbox users</TableCell></TableRow>
            )}
          </TableBody>
        </Table>
      </div>

      <AlertDialog open={confirmDisconnect} onOpenChange={setConfirmDisconnect}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Disconnect {pluralize(selection.selectedCount, "account")}?</AlertDialogTitle>
            <AlertDialogDescription>
              {summariseLabels(selected, nameOf)} will stop importing from Dropbox until they connect
              it again themselves. Comics already imported stay in their libraries.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={() => {
              setConfirmDisconnect(false);
              bulk.run(
                selected,
                (user) => api.post(`/api/admin/dropbox-users/${user.id}/disconnect`, {}),
                { noun: "account", verbPast: "disconnected", labelOf: nameOf }
              );
            }}>
              Disconnect
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
