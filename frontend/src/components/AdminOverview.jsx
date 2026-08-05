import { useEffect, useState } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { formatDateTime, formatFileSize } from "@/lib/format";

export function AdminOverview() {
  const { toast } = useToast();
  const [stats, setStats] = useState(null);
  const [cleanup, setCleanup] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isCleaning, setIsCleaning] = useState(false);

  useEffect(() => {
    api.get("/api/admin/stats")
      .then((data) => setStats(data.stats))
      .catch((error) => toast({ title: "Failed to load stats", description: error.message, variant: "destructive" }))
      .finally(() => setIsLoading(false));
  }, [toast]);

  const runCleanup = async (apply = false) => {
    setIsCleaning(true);
    try {
      const data = await api.post(`/api/admin/cleanup/${apply ? "apply" : "dry-run"}`, {});
      setCleanup(data.cleanup);
      toast({ title: apply ? "Cleanup completed" : "Cleanup dry-run completed" });
    } catch (error) {
      toast({ title: "Cleanup failed", description: error.message, variant: "destructive" });
    } finally {
      setIsCleaning(false);
    }
  };

  if (isLoading) {
    return <div className="flex justify-center p-8"><div className="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-primary" /></div>;
  }

  return (
    <div className="space-y-6">
      <div className="grid gap-4 md:grid-cols-4">
        <Card><CardHeader><CardTitle>Total users</CardTitle></CardHeader><CardContent className="text-3xl font-bold">{stats?.totalUsers ?? 0}</CardContent></Card>
        <Card><CardHeader><CardTitle>Verified users</CardTitle></CardHeader><CardContent className="text-3xl font-bold">{stats?.verifiedUsers ?? 0}</CardContent></Card>
        <Card><CardHeader><CardTitle>Total comics</CardTitle></CardHeader><CardContent className="text-3xl font-bold">{stats?.totalComics ?? 0}</CardContent></Card>
        <Card><CardHeader><CardTitle>Storage used</CardTitle></CardHeader><CardContent className="text-3xl font-bold">{formatFileSize(stats?.storageUsed)}</CardContent></Card>
      </div>

      <Card>
        <CardHeader><CardTitle>Recent Sign-ups</CardTitle></CardHeader>
        <CardContent>
          <Table>
            <TableHeader><TableRow><TableHead>User</TableHead><TableHead>Verified</TableHead><TableHead>Created</TableHead></TableRow></TableHeader>
            <TableBody>
              {(stats?.recentSignups || []).map((user) => (
                <TableRow key={user.id}>
                  <TableCell>{user.name || user.email}<div className="text-sm text-muted-foreground">{user.email}</div></TableCell>
                  <TableCell>{user.isEmailVerified ? "Yes" : "No"}</TableCell>
                  <TableCell>{formatDateTime(user.createdAt)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle>Orphan File Cleanup</CardTitle></CardHeader>
        <CardContent className="space-y-4">
          <div className="flex gap-2">
            <Button variant="outline" onClick={() => runCleanup(false)} disabled={isCleaning}>Dry run</Button>
            <Button onClick={() => runCleanup(true)} disabled={isCleaning || !cleanup}>Quarantine files</Button>
          </div>
          {cleanup && (
            <div className="text-sm text-muted-foreground">
              Found {cleanup.totals?.orphanedComics || 0} orphan comics and {cleanup.totals?.orphanedCovers || 0} orphan covers.
              {cleanup.quarantined && ` Quarantined ${cleanup.quarantined.orphanedComics} comics and ${cleanup.quarantined.orphanedCovers} covers.`}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
