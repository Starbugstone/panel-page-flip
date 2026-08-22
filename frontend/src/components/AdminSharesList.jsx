import { useMemo, useState } from "react";
import { AlertTriangle, Search, ShieldAlert, XCircle } from "lucide-react";

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
import { AdminWarnDialog } from "@/components/AdminWarnDialog";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { useAdminList } from "@/hooks/use-admin-list";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { formatDate } from "@/lib/format";
import { logger } from "@/lib/logger";
import { SHARE_STATUS_LABELS } from "@/lib/sharing";

/** The statuses the backend filters on, in the order an operator wants them. */
const STATUSES = [
  ["", "All"],
  ["accepted", "Accepted"],
  ["pending", "Pending"],
  ["declined", "Declined"],
  ["revoked", "Revoked"],
];

/**
 * Who has been given access to what, and the two things support can do about it.
 *
 * The sharing-codes table answers "what offers are outstanding?"; this answers
 * "who is holding somebody else's comic right now?", which is the question an
 * abuse report actually asks. It sees shares made by emailed invitation too —
 * those have no code behind them and are invisible on the other tab.
 *
 * Revoking here is the same operation the owner performs from their own Sharing
 * page: the recipient loses access, the owner keeps the comic, and nothing is
 * deleted. Removing a comic is a heavier decision and lives on the
 * content-report screen.
 */
export function AdminSharesList() {
  const { toast } = useToast();
  const [status, setStatus] = useState("");
  const [explicitOnly, setExplicitOnly] = useState(false);
  const [shareToRevoke, setShareToRevoke] = useState(null);
  const [warningTarget, setWarningTarget] = useState(null);
  const [isBusy, setIsBusy] = useState(false);

  const filters = useMemo(() => ({
    ...(status ? { status } : {}),
    ...(explicitOnly ? { explicitOnly: "true" } : {}),
  }), [explicitOnly, status]);

  const {
    items: shares,
    pagination,
    isLoading,
    searchInput,
    setSearch,
    setPage,
    setLimit,
    reload,
  } = useAdminList({
    basePath: "/api/admin/shares",
    filters,
    urlKey: "shares",
    itemsKey: "items",
    errorTitle: "Could not load shares",
  });

  const revoke = async () => {
    setIsBusy(true);

    try {
      const data = await api.post(`/api/admin/shares/${shareToRevoke.id}/revoke`, {});
      toast({ title: "Share revoked", description: data.message });
      await reload();
    } catch (error) {
      logger.error("Revoking a share failed:", error);
      toast({ title: "Could not revoke the share", description: error.message, variant: "destructive" });
    } finally {
      setIsBusy(false);
      setShareToRevoke(null);
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <h2 className="text-xl font-bold">Shares</h2>
          <p className="mt-1 text-sm text-muted-foreground">
            Access one account has granted another. Revoking removes the recipient&rsquo;s access and
            leaves the comic with its owner.
          </p>
        </div>
        <div className="relative w-full lg:w-auto">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            type="search"
            placeholder="Search comic, owner or recipient…"
            className="w-full pl-8 lg:w-[320px]"
            value={searchInput}
            onChange={(event) => setSearch(event.target.value)}
          />
        </div>
      </div>

      <div className="flex flex-wrap items-end gap-4">
        <div className="space-y-1">
          <Label htmlFor="admin-shares-status">Status</Label>
          <select
            id="admin-shares-status"
            className="h-10 rounded-md border border-input bg-background px-3 text-sm"
            value={status}
            onChange={(event) => setStatus(event.target.value)}
          >
            {STATUSES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
        </div>
        <div className="flex items-center gap-2 pb-2">
          <Checkbox
            id="admin-shares-explicit"
            checked={explicitOnly}
            onCheckedChange={(checked) => setExplicitOnly(checked === true)}
          />
          <Label htmlFor="admin-shares-explicit" className="font-normal">
            Only comics marked 18+
          </Label>
        </div>
      </div>

      {isLoading && shares.length === 0 ? (
        <div className="flex justify-center p-8">
          <div className="h-8 w-8 animate-spin rounded-full border-b-2 border-t-2 border-primary" />
        </div>
      ) : (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Comic</TableHead>
                <TableHead>Shared by</TableHead>
                <TableHead>Shared with</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Created</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {shares.length > 0 ? shares.map((share) => (
                <TableRow key={share.id}>
                  <TableCell>
                    <div className="flex flex-col">
                      <span className="flex items-center gap-2 font-medium">
                        {share.comic?.title || "Comic removed"}
                        {share.comic?.explicitContent && (
                          <Badge variant="destructive" className="gap-1">
                            <ShieldAlert className="h-3 w-3" aria-hidden="true" /> 18+
                          </Badge>
                        )}
                      </span>
                      {(share.comic?.sharingRestricted || share.comic?.quarantined) && (
                        <span className="text-xs text-amber-600">
                          {share.comic.quarantined ? "Quarantined" : "Sharing restricted"}
                        </span>
                      )}
                    </div>
                  </TableCell>
                  <TableCell>
                    <div className="flex flex-col">
                      <span>{share.owner?.name || "Unknown"}</span>
                      <span className="text-xs text-muted-foreground">{share.owner?.email}</span>
                    </div>
                  </TableCell>
                  <TableCell>
                    <div className="flex flex-col">
                      {/* The account where there is one, and the address it was
                          sent to otherwise — a share to somebody with no account
                          yet is only identifiable by that address. */}
                      <span>{share.recipient?.name || share.recipientEmail}</span>
                      {share.recipient?.username && (
                        <span className="text-xs text-muted-foreground">@{share.recipient.username}</span>
                      )}
                    </div>
                  </TableCell>
                  <TableCell>
                    <Badge variant={share.status === "accepted" ? "default" : "outline"}>
                      {SHARE_STATUS_LABELS[share.status] || share.status}
                    </Badge>
                  </TableCell>
                  <TableCell>{formatDate(share.createdAt)}</TableCell>
                  <TableCell className="text-right">
                    <div className="flex justify-end gap-2">
                      <Button
                        variant="ghost"
                        size="sm"
                        title="Warn the sharer about this comic"
                        aria-label={`Warn the sharer of ${share.comic?.title || "this comic"}`}
                        onClick={() => setWarningTarget(share)}
                      >
                        <AlertTriangle className="h-4 w-4" />
                      </Button>
                      {share.canRevoke && (
                        <Button
                          variant="ghost"
                          size="sm"
                          title="Revoke this share"
                          aria-label={`Revoke the share of ${share.comic?.title || "this comic"}`}
                          onClick={() => setShareToRevoke(share)}
                        >
                          <XCircle className="h-4 w-4" />
                        </Button>
                      )}
                    </div>
                  </TableCell>
                </TableRow>
              )) : (
                <TableRow>
                  <TableCell colSpan={6} className="py-8 text-center">No shares found.</TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
          <AdminPagination
            pagination={pagination}
            itemCount={shares.length}
            isLoading={isLoading}
            onPageChange={setPage}
            onLimitChange={setLimit}
            label="shares"
          />
        </div>
      )}

      <AlertDialog open={shareToRevoke !== null} onOpenChange={(open) => !open && setShareToRevoke(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Revoke this share?</AlertDialogTitle>
            <AlertDialogDescription>
              {revocationSummary(shareToRevoke)} They lose access immediately. The comic stays with its
              owner and nothing is deleted.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={isBusy}>Cancel</AlertDialogCancel>
            <AlertDialogAction
              disabled={isBusy}
              onClick={(event) => {
                event.preventDefault();
                revoke();
              }}
            >
              Revoke access
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AdminWarnDialog
        target={warningTarget ? { shareId: warningTarget.id } : null}
        subjectLabel={warningTarget?.comic?.title}
        recipientLabel={warningTarget
          ? (warningTarget.owner?.name || warningTarget.owner?.email || "The owner")
          : undefined}
        onClose={() => setWarningTarget(null)}
      />
    </div>
  );
}

function revocationSummary(share) {
  if (!share) return "";

  const who = share.recipient?.name || share.recipientEmail || "the recipient";

  return `${share.comic?.title || "This comic"} will no longer be readable by ${who}.`;
}
