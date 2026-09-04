import { PageLayout, PageHeader, PageLoading } from "@/components/layout/PageLayout";
import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { UserPlus } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { ShareComicsDialog } from "@/components/ShareComicsDialog";
import { SharingCodesCard } from "@/components/SharingCodesCard";
import { SharedByMeList } from "@/components/share/SharedByMeList";
import { SharedWithMeList } from "@/components/share/SharedWithMeList";
import { SharingConfirmDialogs } from "@/components/share/SharingConfirmDialogs";
import { useComicLibrary } from "@/hooks/use-comic-library";
import { useSharingActions } from "@/hooks/use-sharing-actions";
import { useAdminTableControls } from "@/hooks/use-admin-table-controls";
import { useSharing, useSharingLists } from "@/hooks/use-sharing";
import { useSharingPageFocus } from "@/hooks/use-sharing-page-focus";
import { describeDeadShareCleanup } from "@/lib/sharing";

const EMPTY_TARGET = { email: "", username: "", userCode: "", comicIds: [] };

/**
 * Both halves of sharing: what this account has handed out, and what has been
 * handed to it.
 *
 * The page composes and holds only what is open. Which transitions a share can
 * be put through lives in `useSharingActions`.
 */
export default function Sharing() {
  const byMeTableControls = useAdminTableControls({ defaultSort: "createdAt" });
  const withMeTableControls = useAdminTableControls({ defaultSort: "createdAt" });
  const {
    sharedByMe, sharedWithMe, byMePagination, byMeListKey, byMeIsLoading,
    withMePagination, withMeListKey, withMeIsLoading,
    byMeSearchInput, withMeSearchInput, isLoading, error, reload,
    setByMeSearchInput, setByMePage, setByMeLimit,
    setWithMeSearchInput, setWithMePage, setWithMeLimit,
  } = useSharingLists(byMeTableControls.query, withMeTableControls.query);
  const { summary } = useSharing();
  const { loadLibrary } = useComicLibrary();
  const navigate = useNavigate();
  const actions = useSharingActions({ reload, loadLibrary });
  const focus = useSharingPageFocus({ reload, loadLibrary });

  // The two confirmation dialogs act on a comic or on the whole history rather
  // than on one share, so they get their own flag instead of borrowing a share id.
  const [isDialogBusy, setIsDialogBusy] = useState(false);
  // The multi-comic flow, or null when it is closed. Holds the recipient and
  // comics it should open with, so "Share another comic" can arrive with the
  // recipient already chosen.
  const [shareDialog, setShareDialog] = useState(null);
  const [confirmingCleanup, setConfirmingCleanup] = useState(false);
  const [stopSharingTarget, setStopSharingTarget] = useState(null);

  const confirmThen = (action, close) => async () => {
    setIsDialogBusy(true);
    await action();
    setIsDialogBusy(false);
    close();
  };

  return (
    <PageLayout>
      <PageHeader
        title="Sharing"
        description="Sharing gives someone permission to read your comic. The file stays yours — nothing is copied — and you can withdraw access at any time."
        actions={<Button onClick={() => setShareDialog(EMPTY_TARGET)}><UserPlus aria-hidden="true" className="mr-2 h-4 w-4" />Share comics</Button>}
      />

      {/* Above the tabs, because neither half of the page owns it: your own
          code is how people reach you, and redeeming one is how a comic arrives
          without anybody knowing your address. */}
      <SharingCodesCard onRedeemed={focus.afterReceiving} reloadKey={focus.codesReloadKey} />

      {isLoading ? (
        <PageLoading label="Loading your shared comics…" />
      ) : error ? (
        <Alert variant="destructive">
          <AlertTitle>Could not load sharing</AlertTitle>
          <AlertDescription className="flex flex-col items-start gap-3">
            {error}
            <Button variant="outline" size="sm" onClick={reload}>Try again</Button>
          </AlertDescription>
        </Alert>
      ) : (
        <Tabs value={focus.activeTab} onValueChange={focus.setActiveTab} className="space-y-6">
          <TabsList className="flex h-auto w-full flex-wrap justify-start sm:w-fit">
            <TabsTrigger value="with-me">Shared with me ({withMePagination.totalItems})</TabsTrigger>
            <TabsTrigger value="by-me">Shared by me ({byMePagination.totalItems})</TabsTrigger>
          </TabsList>
          <TabsContent value="with-me">
            <SharedWithMeList
              sharedWithMe={sharedWithMe}
              pagination={withMePagination}
              listKey={withMeListKey}
              isLoading={withMeIsLoading}
              searchInput={withMeSearchInput}
              tableControls={withMeTableControls}
              actions={actions}
              onSearch={setWithMeSearchInput}
              onPageChange={setWithMePage}
              onLimitChange={setWithMeLimit}
              onRead={(share) => navigate(`/read/${share.comicId}`)}
              onCleanupDead={() => setConfirmingCleanup(true)}
              deadShareCount={summary.deadShares}
              reload={reload}
            />
          </TabsContent>
          <TabsContent value="by-me">
            <SharedByMeList
              sharedByMe={sharedByMe}
              byMePagination={byMePagination}
              byMeListKey={byMeListKey}
              byMeIsLoading={byMeIsLoading}
              searchInput={byMeSearchInput}
              tableControls={byMeTableControls}
              busyShareId={actions.busyShareId}
              onSearch={setByMeSearchInput}
              onPageChange={setByMePage}
              onLimitChange={setByMeLimit}
              onShare={setShareDialog}
              onStopSharing={setStopSharingTarget}
              onResend={actions.resend}
              onRevoke={actions.revoke}
              onDelete={actions.deleteRecord}
              reload={reload}
            />
          </TabsContent>
        </Tabs>
      )}

      {/* Mounted only while open, and keyed on what it opens with, so a dialog
          started from a different recipient never inherits the previous
          selection or a stale "already shared" marking. */}
      {shareDialog && (
        <ShareComicsDialog
          key={`${shareDialog.email}:${shareDialog.username}:${shareDialog.userCode}:${shareDialog.comicIds.join(",")}`}
          isOpen
          onClose={() => setShareDialog(null)}
          sharedByMe={sharedByMe}
          initialRecipient={shareDialog.email}
          initialUsername={shareDialog.username}
          initialUserCode={shareDialog.userCode}
          initialResolved={shareDialog.resolved ?? null}
          initialComicIds={shareDialog.comicIds}
          onShared={focus.afterShare}
        />
      )}

      <SharingConfirmDialogs
        busy={isDialogBusy}
        cleanup={{
          open: confirmingCleanup,
          onOpenChange: setConfirmingCleanup,
          copy: describeDeadShareCleanup(summary.deadShares),
          onConfirm: confirmThen(actions.removeAllDead, () => setConfirmingCleanup(false)),
        }}
        stopSharing={{
          target: stopSharingTarget,
          onCancel: () => setStopSharingTarget(null),
          onConfirm: confirmThen(
            () => actions.stopSharing(stopSharingTarget?.comicId),
            () => setStopSharingTarget(null)
          ),
        }}
      />
    </PageLayout>
  );
}
