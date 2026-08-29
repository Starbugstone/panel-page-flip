import { useMemo, useState } from "react";
import { Brush, Search } from "lucide-react";
import { Table, TableHeader, TableBody, TableHead, TableRow, TableCell } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
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
import { AdminPagination } from "@/components/AdminPagination";
import { useAdminList } from "@/hooks/use-admin-list";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { formatDate } from "@/lib/format";

/** The statuses the backend filters on, in the order an operator wants them. */
const STATUSES = [
  ["", "All"],
  ["active", "Active"],
  ["expired", "Expired"],
  ["exhausted", "Used up"],
  ["withdrawn", "Withdrawn"],
  // Live in every other respect and still unredeemable, because the package
  // lost a comic. The one dead state an owner has no way to notice.
  ["comics_removed", "Comics removed"],
];

const STATUS_LABELS = {
  withdrawn: "Withdrawn",
  expired: "Expired",
  used_up: "Used up",
  comics_removed: "Comics removed",
};

/**
 * The claim codes an instance has issued, and the two things support can do
 * about them.
 *
 * Deliberately not a place a code can be read. Owners can reveal their own
 * encrypted codes, but administrators only need the record id, owner and
 * contents to act on an abuse report.
 *
 * Receiver codes are not here. Their lifecycle is rotation, which lives on the
 * user page beside the account it identifies.
 */
export function AdminSharingCodesList() {
  const { toast } = useToast();
  const [status, setStatus] = useState("");
  const [ownerId, setOwnerId] = useState("");
  const [createdFrom, setCreatedFrom] = useState("");
  const [createdTo, setCreatedTo] = useState("");
  const [codeToRevoke, setCodeToRevoke] = useState(null);
  const [isCleanupOpen, setIsCleanupOpen] = useState(false);
  const [isBusy, setIsBusy] = useState(false);

  const filters = useMemo(() => ({
    ...(status ? { status } : {}),
    ...(ownerId.trim() ? { ownerId: ownerId.trim() } : {}),
    ...(createdFrom ? { createdFrom } : {}),
    ...(createdTo ? { createdTo } : {}),
  }), [status, ownerId, createdFrom, createdTo]);

  const {
    items: codes,
    pagination,
    payload,
    isLoading,
    searchInput,
    setSearch,
    setPage,
    setLimit,
    reload,
  } = useAdminList({
    basePath: "/api/admin/sharing-codes",
    filters,
    urlKey: "sharingCodes",
    itemsKey: "items",
    errorTitle: "Could not load sharing codes",
  });

  const revoke = async () => {
    setIsBusy(true);

    try {
      await api.post(`/api/admin/sharing-codes/${codeToRevoke.id}/revoke`, {});
      toast({
        title: "Sharing code withdrawn",
        description: "Nobody else can use it. Comics already claimed through it are unaffected.",
      });
      await reload();
    } catch (error) {
      logger.error("Revoking a sharing code failed:", error);
      toast({ title: "Could not withdraw the code", description: error.message, variant: "destructive" });
    } finally {
      setIsBusy(false);
      setCodeToRevoke(null);
    }
  };

  const runCleanup = async () => {
    setIsBusy(true);

    try {
      const data = await api.post("/api/admin/sharing-codes/cleanup", {});
      toast({ title: "Cleanup complete", description: data.message });
      await reload();
    } catch (error) {
      logger.error("Running the sharing cleanup failed:", error);
      toast({ title: "Cleanup failed", description: error.message, variant: "destructive" });
    } finally {
      setIsBusy(false);
      setIsCleanupOpen(false);
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold">Sharing codes</h2>
          <p className="text-sm text-muted-foreground">
            Codes users have handed out so others can claim their comics. Administrators can manage
            code records, but cannot see the codes themselves. Owners can show their own codes again
            on the Sharing page.
          </p>
        </div>
        <Button variant="outline" onClick={() => setIsCleanupOpen(true)} disabled={isBusy}>
          <Brush className="mr-2 h-4 w-4" />
          Run cleanup
        </Button>
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <div className="relative min-w-[220px] flex-1">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={searchInput}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search by owner name or email…"
            className="pl-9"
            aria-label="Search sharing codes by owner"
          />
        </div>
        <div className="flex flex-wrap gap-1">
          {STATUSES.map(([value, label]) => (
            <Button
              key={value || "all"}
              size="sm"
              variant={status === value ? "default" : "outline"}
              aria-pressed={status === value}
              onClick={() => { setStatus(value); setPage(1); }}
            >
              {label}
            </Button>
          ))}
        </div>
        <div className="grid gap-1">
          <Label htmlFor="admin-codes-owner" className="text-xs">Owner ID</Label>
          <Input
            id="admin-codes-owner"
            value={ownerId}
            onChange={(event) => { setOwnerId(event.target.value.replace(/[^0-9]/g, "")); setPage(1); }}
            className="w-28"
            placeholder="Any"
          />
        </div>
        <div className="grid gap-1">
          <Label htmlFor="admin-codes-from" className="text-xs">Created from</Label>
          <Input
            id="admin-codes-from"
            type="date"
            value={createdFrom}
            onChange={(event) => { setCreatedFrom(event.target.value); setPage(1); }}
            className="w-40"
          />
        </div>
        <div className="grid gap-1">
          <Label htmlFor="admin-codes-to" className="text-xs">Created to</Label>
          <Input
            id="admin-codes-to"
            type="date"
            value={createdTo}
            onChange={(event) => { setCreatedTo(event.target.value); setPage(1); }}
            className="w-40"
          />
        </div>
      </div>

      <div className="overflow-x-auto rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>ID</TableHead>
              <TableHead>Owner</TableHead>
              <TableHead>Comics</TableHead>
              <TableHead>Uses</TableHead>
              <TableHead>Created</TableHead>
              <TableHead>Expires</TableHead>
              <TableHead>Deleted after</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading && (
              <TableRow><TableCell colSpan={9}>Loading sharing codes…</TableCell></TableRow>
            )}
            {!isLoading && codes.length === 0 && (
              <TableRow><TableCell colSpan={9}>No sharing codes match these filters.</TableCell></TableRow>
            )}
            {!isLoading && codes.map((code) => (
              <TableRow key={code.id}>
                <TableCell className="font-mono text-xs">{code.id}</TableCell>
                <TableCell>
                  <span className="block text-sm">{code.ownerName || "—"}</span>
                  <span className="block text-xs text-muted-foreground">
                    {code.ownerEmail} · #{code.ownerId}
                  </span>
                </TableCell>
                <TableCell>
                  <span className="block text-sm">{code.comicCount}</span>
                  <span className="block max-w-[220px] truncate text-xs text-muted-foreground">
                    {code.comics.map((comic) => `#${comic.id} ${comic.title}`).join(", ")}
                  </span>
                </TableCell>
                <TableCell className="text-sm">
                  {code.timesUsed} / {code.maxUses}
                </TableCell>
                <TableCell className="text-xs">{formatDate(code.createdAt)}</TableCell>
                <TableCell className="text-xs">{formatDate(code.expiresAt)}</TableCell>
                <TableCell className="text-xs">{formatDate(code.deletableAfter)}</TableCell>
                <TableCell>
                  <Badge variant={code.isRedeemable ? "default" : "outline"}>
                    {STATUS_LABELS[code.deadReason] || "Active"}
                  </Badge>
                  {code.revokedAt && (
                    <span className="mt-1 block text-xs text-muted-foreground">
                      {formatDate(code.revokedAt)}
                    </span>
                  )}
                </TableCell>
                <TableCell className="text-right">
                  {code.isRedeemable && (
                    <Button
                      size="sm"
                      variant="ghost"
                      disabled={isBusy}
                      onClick={() => setCodeToRevoke(code)}
                    >
                      Withdraw
                    </Button>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      <AdminPagination
        pagination={pagination}
        itemCount={codes.length}
        isLoading={isLoading}
        onPageChange={setPage}
        onLimitChange={setLimit}
        label="sharing codes"
      />

      <AlertDialog open={codeToRevoke !== null} onOpenChange={() => setCodeToRevoke(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Withdraw sharing code #{codeToRevoke?.id}?</AlertDialogTitle>
            <AlertDialogDescription>
              Nobody will be able to claim anything with it from now on. Comics that have already
              been claimed through it stay with the people who claimed them — withdrawing a code
              closes the way in, it does not take access back.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={(event) => { event.preventDefault(); revoke(); }}
            >
              Withdraw code
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog open={isCleanupOpen} onOpenChange={setIsCleanupOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Run the retention cleanup now?</AlertDialogTitle>
            <AlertDialogDescription>
              This is the same sweep the scheduled job runs, so it removes only invitations that
              expired unanswered and codes that died more than {payload?.retentionAfterExpiry || "30 days"} ago.
              Live codes, recently expired ones, and the comics people claimed through them are all
              left alone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={(event) => { event.preventDefault(); runCleanup(); }}>
              Run cleanup
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
