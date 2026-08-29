import { useCallback, useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { formatDateTime } from "@/lib/format";

export function AdminDropbox() {
  const { toast } = useToast();
  const [users, setUsers] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [busyUserId, setBusyUserId] = useState(null);

  const loadUsers = useCallback(async () => {
    setIsLoading(true);
    try {
      const data = await api.get("/api/admin/dropbox-users");
      setUsers(data.users || []);
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
      <div className="border rounded-md">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>User</TableHead>
              <TableHead>Last import</TableHead>
              <TableHead>Imported comics</TableHead>
              <TableHead className="text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {users.length > 0 ? users.map((user) => (
              <TableRow key={user.id}>
                <TableCell>{user.name || user.email}<div className="text-sm text-muted-foreground">{user.email}</div></TableCell>
                <TableCell>{formatDateTime(user.lastSyncedAt, "Never")}</TableCell>
                <TableCell>{user.dropboxComicCount}</TableCell>
                <TableCell className="text-right space-x-2">
                  <Button size="sm" variant="outline" disabled={busyUserId === user.id} onClick={() => runAction(user.id, "sync")}>Force import</Button>
                  <Button size="sm" variant="destructive" disabled={busyUserId === user.id} onClick={() => runAction(user.id, "disconnect")}>Disconnect</Button>
                </TableCell>
              </TableRow>
            )) : (
              <TableRow><TableCell colSpan={4} className="text-center py-8">No connected Dropbox users</TableCell></TableRow>
            )}
          </TableBody>
        </Table>
      </div>
    </div>
  );
}
