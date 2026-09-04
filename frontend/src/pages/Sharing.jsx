import { useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Loader2, Share2Icon, UserPlus } from "lucide-react";
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
import { useSharingLists } from "@/hooks/use-sharing";
import { useSharingPageFocus } from "@/hooks/use-sharing-page-focus";
import { describeDeadShareCleanup, groupReceivedShares } from "@/lib/sharing";

const EMPTY_TARGET = { email: "", username: "", userCode: "", comicIds: [] };

/**
 * Both halves of sharing: what this account has handed out, and what has been
 * handed to it.
 *
 * The page composes and holds only what is open. Which transitions a share can
 * be put through lives in `useSharingActions`.
 */
export default function Sharing() {
  const tableControls = useAdminTableControls({ defaultSort: "createdAt" });
  const {
    sharedByMe, sharedWithMe, byMePagination, byMeListKey, byMeIsLoading,
    byMeSearchInput, isLoading, error, reload, setByMeSearchInput, setByMePage, setByMeLimit,
  } = useSharingLists(tableControls.query);
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
  const groups = useMemo(() => groupReceivedShares(sharedWithMe), [sharedWithMe]);

  const confirmThen = (action, close) => async () => {
    setIsDialogBusy(true);
    await action();
    setIsDialogBusy(false);
    close();
  };

  return (
    <div className="container mx-auto px-4 py-8">
      <div className="mb-8 flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <Share2Icon className="h-6 w-6 text-comic-purple" />
          <h1 className="font-comic text-3xl">Sharing</h1>
        </div>
        <Button onClick={() => setShareDialog(EMPTY_TARGET)}>
          <UserPlus className="mr-2 h-4 w-4" />
          Share comics
        </Button>
      </div>

      <p className="mb-6 max-w-3xl text-sm text-muted-foreground">
        Sharing gives someone permission to read your comic. The file stays yours — nothing is
        copied — and you can withdraw access at any time.
      </p>

      {/* Above the tabs, because neither half of the page owns it: your own
          code is how people reach you, and redeeming one is how a comic arrives
          without anybody knowing your address. */}
      <SharingCodesCard onRedeemed={focus.afterReceiving} reloadKey={focus.codesReloadKey} />

      {isLoading ? (
        <div className="flex items-center gap-2 py-12 text-muted-foreground">
          <Loader2 className="h-5 w-5 animate-spin" />
          Loading your shared comics…
        </div>
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
          <TabsList>
            <TabsTrigger value="with-me">Shared with me ({sharedWithMe.length})</TabsTrigger>
            <TabsTrigger value="by-me">Shared by me ({byMePagination.totalItems})</TabsTrigger>
          </TabsList>
          <TabsContent value="with-me">
            <SharedWithMeList
              sharedWithMe={sharedWithMe}
              groups={groups}
              actions={actions}
              onRead={(share) => navigate(`/read/${share.comicId}`)}
              onCleanupDead={() => setConfirmingCleanup(true)}
            />
          </TabsContent>
          <TabsContent value="by-me">
            <SharedByMeList
              sharedByMe={sharedByMe}
              byMePagination={byMePagination}
              byMeListKey={byMeListKey}
              byMeIsLoading={byMeIsLoading}
              searchInput={byMeSearchInput}
              tableControls={tableControls}
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
          copy: describeDeadShareCleanup(groups.dead.length),
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
    </div>
  );
}
