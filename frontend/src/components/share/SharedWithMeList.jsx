import { useState } from "react";
import { BookOpen, Loader2, Search, ShieldCheck, Trash2, Undo2, XCircle } from "lucide-react";
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
import { ShareStatusColumns } from "@/components/share/ShareStatusColumns";
import { ExplicitContentNotice } from "@/components/share/ExplicitContentNotice";
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
  describeReceivedShare,
  EXPLICIT_FLAG_LABEL,
  EXPLICIT_GATE_CONFIRM_LABEL,
  requiresAdultConfirmation,
  SHARE_STATUS,
  SHARE_STATUS_LABELS,
  shareDisplayTitle,
} from "@/lib/sharing";

const STATUS_VARIANTS = {
  [SHARE_STATUS.ACCEPTED]: "default",
  [SHARE_STATUS.PENDING]: "secondary",
  [SHARE_STATUS.DECLINED]: "outline",
  [SHARE_STATUS.REVOKED]: "outline",
};

/** Everything addressed to the signed-in reader, one access grant per row. */
export function SharedWithMeList({
  sharedWithMe,
  pagination,
  listKey,
  isLoading,
  searchInput,
  tableControls,
  actions,
  onSearch,
  onPageChange,
  onLimitChange,
  onRead,
  onCleanupDead,
  deadShareCount = 0,
  reload,
}) {
  const [confirmingDelete, setConfirmingDelete] = useState(false);
  const rows = receivedTableRows(sharedWithMe);
  const selection = useRowSelection({ rows, resetKey: listKey });
  const bulk = useAdminBulkAction({ reload });
  const deletable = selection.selectedRows.filter((share) => share.isDead);
  const hasQuery = searchInput.trim() !== "" || Object.keys(tableControls.columnFilters).length > 0;

  if (!isLoading && sharedWithMe.length === 0 && !hasQuery) {
    return <p className="py-12 text-center text-muted-foreground">Nobody has shared a comic with you yet.</p>;
  }

  const deleteSelected = () => {
    setConfirmingDelete(false);
    bulk.run(
      deletable,
      (share) => api.delete("/api/shares/tombstones", { body: { shareIds: [share.id] } }),
      { noun: "entry", verbPast: "removed", labelOf: receivedTitle },
    );
  };
  const labels = summariseLabels(deletable, receivedTitle);

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <h2 className="text-xl font-bold">Shares received</h2>
          <p className="mt-1 text-sm text-muted-foreground">
            Search, filter and manage the individual access grants shared with you.
          </p>
        </div>
        <div className="flex w-full flex-col gap-2 sm:flex-row lg:w-auto">
          {deadShareCount > 0 && (
            <Button variant="outline" onClick={onCleanupDead}>
              <Trash2 />
              Remove unavailable ({deadShareCount})
            </Button>
          )}
          <div className="relative w-full lg:w-auto">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              type="search"
              aria-label="Search received shares"
              placeholder="Search comic or owner…"
              className="w-full pl-8 lg:w-[320px]"
              value={searchInput}
              onChange={(event) => onSearch(event.target.value)}
            />
          </div>
        </div>
      </div>

      <AdminBulkActionsBar
        selectedCount={selection.selectedCount}
        totalCount={rows.length}
        noun="share"
        actions={[{
          key: "delete",
          label: "Remove records",
          icon: Trash2,
          eligible: deletable,
          ineligibleReason: "only unavailable shares can be removed",
          onClick: () => setConfirmingDelete(true),
        }]}
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
                  label="Select all received shares"
                />
              </TableHead>
              <TableHead>
                <AdminColumnHeader
                  label="Comic"
                  sortField="comicTitle"
                  filterField="filterComic"
                  filterSuggestions={adminFilterSuggestions(
                    rows,
                    (share) => [receivedTitle(share), share.comicAuthor],
                  )}
                  filterValue={tableControls.columnFilters.filterComic}
                  {...tableControls.headerProps}
                />
              </TableHead>
              <TableHead>
                <AdminColumnHeader
                  label="Shared by"
                  sortField="owner"
                  filterField="filterOwner"
                  filterSuggestions={adminFilterSuggestions(
                    rows,
                    (share) => [share.ownerLabel, share.ownerName],
                  )}
                  filterValue={tableControls.columnFilters.filterOwner}
                  {...tableControls.headerProps}
                />
              </TableHead>
              <ShareStatusColumns tableControls={tableControls} />
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading ? (
              <TableRow>
                <TableCell colSpan={6} className="py-10 text-center text-muted-foreground">
                  <Loader2 className="mx-auto h-5 w-5 animate-spin" aria-label="Loading received shares" />
                </TableCell>
              </TableRow>
            ) : rows.length > 0 ? rows.map((share) => (
              <ReceivedShareRow
                key={share.id}
                share={share}
                selected={selection.isChecked(share)}
                busy={actions.busyShareId === share.id}
                onToggle={(checked, options) => selection.toggle(share.id, checked, options)}
                actions={actions}
                onRead={() => onRead(share)}
              />
            )) : (
              <TableRow>
                <TableCell colSpan={6} className="py-10 text-center text-muted-foreground">
                  No received shares match your filters.
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
        <AdminPagination
          pagination={pagination}
          itemCount={sharedWithMe.length}
          isLoading={isLoading}
          onPageChange={onPageChange}
          onLimitChange={onLimitChange}
          label="shares"
        />
      </div>

      <AlertDialog open={confirmingDelete} onOpenChange={setConfirmingDelete}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Remove {pluralize(deletable.length, "unavailable share")}?</AlertDialogTitle>
            <AlertDialogDescription>
              {labels} will be removed from your sharing history. Live access is not affected.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={deleteSelected}>Remove records</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

function ReceivedShareRow({ share, selected, busy, onToggle, actions, onRead }) {
  const title = receivedTitle(share);
  const owner = share.ownerLabel || share.ownerName;

  return (
    <TableRow data-state={selected ? "selected" : undefined}>
      <TableCell>
        <SelectionCheckbox
          checked={selected}
          onToggle={onToggle}
          label={`Select ${title} shared by ${owner}`}
        />
      </TableCell>
      <TableCell>
        <div className="min-w-44">
          <span className="font-medium">{title}</span>
          {share.batchShares
            ? <span className="block text-xs text-muted-foreground">{receivedBatchSize(share)} comics</span>
            : share.comicAuthor && <span className="block text-xs text-muted-foreground">{share.comicAuthor}</span>}
          {share.explicitContent && <Badge variant="outline" className="mt-1">{EXPLICIT_FLAG_LABEL}</Badge>}
        </div>
      </TableCell>
      <TableCell><span className="min-w-36">{owner}</span></TableCell>
      <TableCell><ReceivedStatusCell share={share} /></TableCell>
      <TableCell className="whitespace-nowrap">{formatDate(share.createdAt)}</TableCell>
      <TableCell>
        <ReceivedRowActions share={share} title={title} busy={busy} actions={actions} onRead={onRead} />
      </TableCell>
    </TableRow>
  );
}

function ReceivedStatusCell({ share }) {
  const label = share.isTombstoned ? "Unavailable" : (SHARE_STATUS_LABELS[share.status] || share.status);
  const description = share.batchShares
    ? `${share.ownerLabel || share.ownerName} wants to share ${receivedBatchSize(share)} comics with you. Accept once to add the whole folder snapshot to your collection.`
    : describeReceivedShare(share);

  return (
    <div className="min-w-48">
      <Badge variant={STATUS_VARIANTS[share.status] || "outline"}>{label}</Badge>
      {requiresAdultConfirmation(share) && <ExplicitContentNotice />}
      <span className="mt-1 block text-xs text-muted-foreground">{description}</span>
    </div>
  );
}

function ReceivedRowActions({ share, title, busy, actions, onRead }) {
  return (
    <div className="flex min-w-max justify-end gap-1">
      <AdultConfirmationAction share={share} title={title} busy={busy} onConfirm={actions.confirmAdult} />
      <InvitationActions share={share} title={title} busy={busy} actions={actions} />
      <CollectionActions share={share} title={title} busy={busy} actions={actions} onRead={onRead} />
      <HistoryAction share={share} title={title} busy={busy} onForget={actions.forget} />
    </div>
  );
}

function AdultConfirmationAction({ share, title, busy, onConfirm }) {
  if (!requiresAdultConfirmation(share) || share.isDead) return null;

  return (
    <Button
      size="icon"
      variant="ghost"
      disabled={busy}
      title={EXPLICIT_GATE_CONFIRM_LABEL}
      aria-label={`${EXPLICIT_GATE_CONFIRM_LABEL} for ${title}`}
      onClick={() => onConfirm(share)}
    >
      {busy ? <Loader2 className="animate-spin" /> : <ShieldCheck />}
    </Button>
  );
}

function InvitationActions({ share, title, busy, actions }) {
  if (!share.canAnswer) return null;
  const isBatch = Boolean(share.batchShares);

  return (
    <>
      {!requiresAdultConfirmation(share) && (
        <Button
          size="icon"
          variant="ghost"
          disabled={busy}
          title={isBatch ? "Add all to my collection" : "Add to my collection"}
          aria-label={isBatch ? `Add all to my collection from ${title}` : `Add to my collection: ${title}`}
          onClick={() => actions.accept(share)}
        >
          {busy ? <Loader2 className="animate-spin" /> : <BookOpen />}
        </Button>
      )}
      <Button
        size="icon"
        variant="ghost"
        disabled={busy}
        title={isBatch ? "Decline all" : "Decline invitation"}
        aria-label={isBatch ? `Decline all comics from ${title}` : `Decline ${title}`}
        onClick={() => actions.decline(share)}
      >
        {busy ? <Loader2 className="animate-spin" /> : <XCircle />}
      </Button>
    </>
  );
}

function CollectionActions({ share, title, busy, actions, onRead }) {
  return (
    <>
      {share.canRead && (
        <Button size="icon" variant="ghost" title="Read" aria-label={`Read ${title}`} onClick={onRead}>
          <BookOpen />
        </Button>
      )}
      {share.canRemove && (
        <Button
          size="icon"
          variant="ghost"
          disabled={busy}
          title="Remove from my collection"
          aria-label={`Remove ${title} from my collection`}
          onClick={() => actions.remove(share)}
        >
          {busy ? <Loader2 className="animate-spin" /> : <Trash2 />}
        </Button>
      )}
      {share.canRestore && (
        <Button
          size="icon"
          variant="ghost"
          disabled={busy}
          title="Restore to my collection"
          aria-label={`Restore ${title} to my collection`}
          onClick={() => actions.restore(share)}
        >
          {busy ? <Loader2 className="animate-spin" /> : <Undo2 />}
        </Button>
      )}
    </>
  );
}

function HistoryAction({ share, title, busy, onForget }) {
  if (!share.isDead) return null;

  return (
    <Button
      size="icon"
      variant="ghost"
      disabled={busy}
      title="Remove from sharing history"
      aria-label={`Remove ${title} from sharing history`}
      onClick={() => onForget(share)}
    >
      {busy ? <Loader2 className="animate-spin" /> : <Trash2 />}
    </Button>
  );
}

function receivedTitle(share) {
  return share.batchShares ? (share.invitationBatchName || "Shared folder") : shareDisplayTitle(share);
}

function receivedBatchSize(share) {
  return share.invitationBatchSize || share.batchShares.length;
}

function receivedTableRows(shares) {
  const rows = [];
  const batches = new Map();

  shares.forEach((share) => {
    if (share.status !== SHARE_STATUS.PENDING || !share.invitationBatchId) {
      rows.push(share);
      return;
    }

    const existing = batches.get(share.invitationBatchId);
    if (existing) {
      existing.batchShares.push(share);
      existing.explicitContent = existing.explicitContent || share.explicitContent;
      existing.requiresAdultConfirmation = existing.requiresAdultConfirmation || share.requiresAdultConfirmation;
      return;
    }

    const batch = { ...share, batchShares: [share] };
    batches.set(share.invitationBatchId, batch);
    rows.push(batch);
  });

  return rows;
}
