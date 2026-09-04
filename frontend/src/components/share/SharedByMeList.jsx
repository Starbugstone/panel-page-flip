import { useState } from "react";
import { Ban, Loader2, RotateCcw, Search, ShieldAlert, Trash2, UserPlus, XCircle } from "lucide-react";
import { Alert, AlertDescription } from "@/components/ui/alert";
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
import { AdminBulkActionsBar } from "@/components/admin/AdminBulkActionsBar";
import { AdminColumnHeader } from "@/components/admin/AdminColumnHeader";
import { SelectAllCheckbox, SelectionCheckbox } from "@/components/SelectionCheckbox";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { useAdminBulkAction } from "@/hooks/use-admin-bulk-action";
import { useRowSelection } from "@/hooks/use-row-selection";
import { adminFilterSuggestions } from "@/lib/admin-table-filters";
import { api } from "@/lib/api";
import { pluralize, summariseLabels } from "@/lib/bulk-actions";
import { formatDate } from "@/lib/format";
import {
  describeNotification,
  EXPLICIT_FLAG_LABEL,
  recipientLabel,
  recipientTarget,
  SHARE_STATUS,
  SHARE_STATUS_LABELS,
  SHARING_PAGE_RESPONSIBILITY_REMINDER,
} from "@/lib/sharing";

const EMPTY_TARGET = { email: "", username: "", userCode: "", comicIds: [] };
const STATUS_VARIANTS = {
  [SHARE_STATUS.ACCEPTED]: "default",
  [SHARE_STATUS.PENDING]: "secondary",
  [SHARE_STATUS.DECLINED]: "outline",
  [SHARE_STATUS.REVOKED]: "outline",
};

/**
 * Everything the signed-in owner has handed out.
 *
 * The reminder is informational only — the acknowledgement that goes on the
 * record is the tick box in the share dialog, once per share. It is here so
 * the expectation stays visible while somebody reviews what they have already
 * handed out, not only as they hand out the next one.
 */
export function SharedByMeList({
  sharedByMe,
  byMePagination,
  byMeListKey,
  byMeIsLoading,
  searchInput,
  tableControls,
  busyShareId,
  onSearch,
  onPageChange,
  onLimitChange,
  onShare,
  onStopSharing,
  onResend,
  onRevoke,
  onDelete,
  reload,
}) {
  const [bulkAction, setBulkAction] = useState(null);
  const selection = useRowSelection({ rows: sharedByMe, resetKey: byMeListKey });
  const bulk = useAdminBulkAction({ reload });
  const selected = selection.selectedRows;
  const revocable = selected.filter((share) => share.canRevoke);
  const deletable = selected.filter((share) => share.canDelete);
  const activeBulk = bulkAction === "revoke"
    ? bulkConfig("revoke", revocable)
    : bulkAction === "delete" ? bulkConfig("delete", deletable) : null;
  const hasQuery = searchInput.trim() !== "" || Object.keys(tableControls.columnFilters).length > 0;
  const reminder = (
    <Alert className="mb-4">
      <ShieldAlert className="h-5 w-5" />
      <AlertDescription>{SHARING_PAGE_RESPONSIBILITY_REMINDER}</AlertDescription>
    </Alert>
  );

  if (!byMeIsLoading && sharedByMe.length === 0 && !hasQuery) {
    return (
      <>
        {reminder}
        <div className="py-12 text-center text-muted-foreground">
          <p>You have not shared any comics yet.</p>
          <p className="mt-1 text-sm">
            Share comics privately with someone you know. Recipients must claim a code or accept
            an invitation before reading.
          </p>
          <Button className="mt-4" onClick={() => onShare(EMPTY_TARGET)}>
            <UserPlus className="mr-2 h-4 w-4" />
            Share comics
          </Button>
        </div>
      </>
    );
  }

  return (
    <div className="space-y-4">
      {reminder}
      <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <h2 className="text-xl font-bold">Your shares</h2>
          <p className="mt-1 text-sm text-muted-foreground">
            Search, filter and select the individual access grants you have handed out.
          </p>
        </div>
        <div className="relative w-full lg:w-auto">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            type="search"
            aria-label="Search your shares"
            placeholder="Search comic or recipient…"
            className="w-full pl-8 lg:w-[320px]"
            value={searchInput}
            onChange={(event) => onSearch(event.target.value)}
          />
        </div>
      </div>

      <AdminBulkActionsBar
        selectedCount={selection.selectedCount}
        totalCount={sharedByMe.length}
        noun="share"
        actions={[
          {
            key: "revoke",
            label: "Revoke selected",
            icon: XCircle,
            variant: "destructive",
            eligible: revocable,
            ineligibleReason: "only pending or accepted shares can be revoked",
            onClick: () => setBulkAction("revoke"),
          },
          {
            key: "delete",
            label: "Delete records",
            icon: Trash2,
            eligible: deletable,
            ineligibleReason: "only finished shares can be deleted",
            onClick: () => setBulkAction("delete"),
          },
        ]}
        progress={bulk.progress}
        onClear={selection.clear}
      />

      <div className="rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-12">
                <SelectAllCheckbox
                  state={selection.headerState}
                  onToggleAll={selection.toggleAll}
                  label="Select all shares"
                />
              </TableHead>
              <TableHead>
                <AdminColumnHeader
                  label="Comic"
                  sortField="comicTitle"
                  filterField="filterComic"
                  filterSuggestions={adminFilterSuggestions(
                    sharedByMe,
                    (share) => [share.comicTitle, share.comicAuthor]
                  )}
                  filterValue={tableControls.columnFilters.filterComic}
                  {...tableControls.headerProps}
                />
              </TableHead>
              <TableHead>
                <AdminColumnHeader
                  label="Recipient"
                  sortField="recipient"
                  filterField="filterRecipient"
                  filterSuggestions={adminFilterSuggestions(
                    sharedByMe,
                    (share) => [recipientLabel(share), share.recipientUserCode]
                  )}
                  filterValue={tableControls.columnFilters.filterRecipient}
                  {...tableControls.headerProps}
                />
              </TableHead>
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
            </TableRow>
          </TableHeader>
          <TableBody>
            {byMeIsLoading ? (
              <TableRow>
                <TableCell colSpan={6} className="py-10 text-center text-muted-foreground">
                  <Loader2 className="mx-auto h-5 w-5 animate-spin" aria-label="Loading shares" />
                </TableCell>
              </TableRow>
            ) : sharedByMe.length > 0 ? sharedByMe.map((share) => (
              <OwnerShareRow
                key={share.id}
                share={share}
                selected={selection.isChecked(share)}
                busy={busyShareId === share.id}
                onToggle={(checked, options) => selection.toggle(share.id, checked, options)}
                onShare={onShare}
                onStopSharing={() => onStopSharing({ comicId: share.comicId, title: share.comicTitle })}
                onResend={() => onResend(share)}
                onRevoke={() => onRevoke(share)}
                onDelete={() => onDelete(share)}
              />
            )) : (
              <TableRow>
                <TableCell colSpan={6} className="py-10 text-center text-muted-foreground">
                  No shares match your filters.
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
        <AdminPagination
          pagination={byMePagination}
          itemCount={sharedByMe.length}
          isLoading={byMeIsLoading}
          onPageChange={onPageChange}
          onLimitChange={onLimitChange}
          label="shares"
        />
      </div>

      <AlertDialog open={activeBulk !== null} onOpenChange={(open) => !open && setBulkAction(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{activeBulk?.title}</AlertDialogTitle>
            <AlertDialogDescription>{activeBulk?.description}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={() => {
              const action = activeBulk;
              setBulkAction(null);
              if (action) bulk.run(action.items, action.perform, action.phrasing);
            }}>
              {activeBulk?.confirmLabel}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

function OwnerShareRow({ share, selected, busy, onToggle, onShare, onStopSharing, onResend, onRevoke, onDelete }) {
  const label = recipientLabel(share);

  return (
    <TableRow data-state={selected ? "selected" : undefined}>
      <TableCell>
        <SelectionCheckbox
          checked={selected}
          onToggle={onToggle}
          label={`Select the share of ${share.comicTitle} with ${label}`}
        />
      </TableCell>
      <TableCell><ShareComicCell share={share} /></TableCell>
      <TableCell><ShareRecipientCell share={share} label={label} /></TableCell>
      <TableCell><ShareStatusCell share={share} /></TableCell>
      <TableCell className="whitespace-nowrap">{formatDate(share.createdAt)}</TableCell>
      <TableCell>
        <ShareRowActions
          share={share}
          label={label}
          busy={busy}
          onShare={onShare}
          onStopSharing={onStopSharing}
          onResend={onResend}
          onRevoke={onRevoke}
          onDelete={onDelete}
        />
      </TableCell>
    </TableRow>
  );
}

function ShareComicCell({ share }) {
  return (
    <div className="min-w-44">
      <span className="font-medium">{share.comicTitle}</span>
      {share.comicAuthor && <span className="block text-xs text-muted-foreground">{share.comicAuthor}</span>}
      {share.explicitContent && (
        <Badge variant="outline" className="mt-1">{EXPLICIT_FLAG_LABEL}</Badge>
      )}
    </div>
  );
}

function ShareRecipientCell({ share, label }) {
  return (
    <div className="min-w-40">
      <span>{label}</span>
      {share.recipientUserCode && (
        <span className="block text-xs text-muted-foreground">User code {share.recipientUserCode}</span>
      )}
    </div>
  );
}

function ShareStatusCell({ share }) {
  const notification = describeNotification(share);

  return (
    <div className="min-w-24">
      <Badge variant={STATUS_VARIANTS[share.status] || "outline"}>
        {SHARE_STATUS_LABELS[share.status] || share.status}
      </Badge>
      {share.status === SHARE_STATUS.PENDING && share.isExpired && (
        <span className="mt-1 block text-xs text-muted-foreground">Invitation expired</span>
      )}
      {notification && <span className="mt-1 block text-xs text-destructive">{notification}</span>}
    </div>
  );
}

function ShareRowActions({ share, label, busy, onShare, onStopSharing, onResend, onRevoke, onDelete }) {
  const busyIcon = <Loader2 className="animate-spin" />;

  return (
    <div className="flex min-w-max justify-end gap-1">
      <Button
        size="icon"
        variant="ghost"
        disabled={busy}
        title="Share another comic"
        aria-label={`Share another comic with ${label}`}
        onClick={() => onShare(shareAgainTarget(share))}
      >
        <UserPlus />
      </Button>
      {share.canResend && (
        <Button
          size="icon"
          variant="ghost"
          disabled={busy}
          title="Resend invitation"
          aria-label={`Resend invitation to ${label}`}
          onClick={onResend}
        >
          {busy ? busyIcon : <RotateCcw />}
        </Button>
      )}
      {share.canRevoke && (
        <>
          <Button
            size="icon"
            variant="ghost"
            disabled={busy}
            title="Revoke access"
            aria-label={`Revoke access for ${label}`}
            onClick={onRevoke}
          >
            {busy ? busyIcon : <XCircle />}
          </Button>
          <Button
            size="icon"
            variant="ghost"
            disabled={busy}
            title="Stop sharing this comic with everyone"
            aria-label={`Stop sharing ${share.comicTitle} with everyone`}
            onClick={onStopSharing}
          >
            <Ban />
          </Button>
        </>
      )}
      {share.canDelete && (
        <Button
          size="icon"
          variant="ghost"
          disabled={busy}
          title="Delete share record"
          aria-label={`Delete the share record for ${label}`}
          onClick={onDelete}
        >
          {busy ? busyIcon : <Trash2 />}
        </Button>
      )}
    </div>
  );
}

function shareAgainTarget(share) {
  return {
    ...recipientTarget(share),
    resolved: share.recipientUsername
      ? {
        username: share.recipientUsername,
        name: share.recipientName || "",
        label: recipientLabel(share),
      }
      : null,
    comicIds: [],
  };
}

function bulkConfig(action, items) {
  const labels = summariseLabels(
    items,
    (share) => `${share.comicTitle} — ${recipientLabel(share)}`
  );

  if (action === "revoke") {
    return {
      title: `Revoke ${pluralize(items.length, "share")}?`,
      description: `${labels} will no longer be readable by the selected recipients. The comics stay in your library.`,
      confirmLabel: "Revoke access",
      items,
      perform: (share) => api.post(`/api/shares/${share.id}/revoke`, {}),
      phrasing: {
        noun: "share",
        verbPast: "revoked",
        labelOf: (share) => `${share.comicTitle} — ${recipientLabel(share)}`,
      },
    };
  }

  return {
    title: `Delete ${pluralize(items.length, "share record")}?`,
    description: `${labels} will be removed from your history. These shares are already finished; no live access is affected.`,
    confirmLabel: "Delete records",
    items,
    perform: (share) => api.delete(`/api/shares/${share.id}`),
    phrasing: {
      noun: "record",
      verbPast: "deleted",
      labelOf: (share) => `${share.comicTitle} — ${recipientLabel(share)}`,
    },
  };
}
