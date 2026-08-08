import { useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import {
  BookOpen,
  Loader2,
  RotateCcw,
  Share2Icon,
  ShieldAlert,
  Trash2,
  Undo2,
  UserPlus,
  XCircle,
} from "lucide-react";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { ShareComicModal } from "@/components/ShareComicModal";
import { ShareComicsDialog } from "@/components/ShareComicsDialog";
import { useSharingLists } from "@/hooks/use-sharing";
import { useComicLibrary } from "@/hooks/use-comic-library";
import {
  EXPLICIT_FLAG_LABEL,
  EXPLICIT_GATE_CONFIRM_LABEL,
  EXPLICIT_GATE_TITLE,
  SHARE_STATUS,
  SHARE_STATUS_LABELS,
  SHARING_PAGE_RESPONSIBILITY_REMINDER,
  describeDeadShareCleanup,
  describeReceivedShare,
  groupReceivedShares,
  requiresAdultConfirmation,
  shareDisplayTitle,
  summariseRecipients,
} from "@/lib/sharing";

const STATUS_VARIANTS = {
  [SHARE_STATUS.ACCEPTED]: "default",
  [SHARE_STATUS.PENDING]: "secondary",
  [SHARE_STATUS.DECLINED]: "outline",
  [SHARE_STATUS.REVOKED]: "outline",
};

function ShareCover({ src, title, gated = false }) {
  if (!src) {
    return (
      <div className="flex h-24 w-16 flex-none items-center justify-center rounded bg-muted">
        {gated
          ? <ShieldAlert className="h-6 w-6 text-muted-foreground" />
          : <BookOpen className="h-6 w-6 text-muted-foreground" />}
      </div>
    );
  }

  return (
    <img
      src={src}
      alt={`Cover of ${title}`}
      loading="lazy"
      decoding="async"
      className="h-24 w-16 flex-none rounded object-cover"
    />
  );
}

export default function Sharing() {
  const { sharedByMe, sharedWithMe, isLoading, error, reload } = useSharingLists();
  const { loadLibrary } = useComicLibrary();
  const { toast } = useToast();
  const navigate = useNavigate();

  const [busyShareId, setBusyShareId] = useState(null);
  const [isDialogBusy, setIsDialogBusy] = useState(false);
  const [shareDialog, setShareDialog] = useState(null);
  const [inviteTarget, setInviteTarget] = useState(null);
  const [confirmingCleanup, setConfirmingCleanup] = useState(false);
  const [stopSharingTarget, setStopSharingTarget] = useState(null);

  const { invitations, collection, dead } = useMemo(
    () => groupReceivedShares(sharedWithMe),
    [sharedWithMe]
  );

  const runAction = async (shareId, action, successMessage) => {
    setBusyShareId(shareId);
    try {
      await action();
      await reload();
      await loadLibrary();
      if (successMessage) {
        toast({ title: successMessage });
      }
    } catch (err) {
      logger.error("Sharing action failed:", err);
      toast({
        title: "Something went wrong",
        description: err.message || "The action could not be completed.",
        variant: "destructive",
      });
    } finally {
      setBusyShareId(null);
    }
  };

  const refreshAfterShare = async () => {
    await reload();
    await loadLibrary();
  };

  const cleanupCopy = describeDeadShareCleanup(dead.length);

  const responsibilityReminder = (
    <Alert className="mb-4">
      <ShieldAlert className="h-5 w-5" />
      <AlertDescription>{SHARING_PAGE_RESPONSIBILITY_REMINDER}</AlertDescription>
    </Alert>
  );

  const renderSharedByMe = () => {
    if (sharedByMe.length === 0) {
      return (
        <>
          {responsibilityReminder}
          <div className="py-12 text-center text-muted-foreground">
            <p>You have not shared any comics yet.</p>
            <p className="mt-1 text-sm">
              Share comics privately with someone you know. They must accept the invitation before
              they can read anything.
            </p>
            <Button
              className="mt-4"
              onClick={() => setShareDialog({ recipient: "", comicIds: [] })}
            >
              <UserPlus className="mr-2 h-4 w-4" />
              Share comics
            </Button>
          </div>
        </>
      );
    }

    return (
      <div className="space-y-4">
        {responsibilityReminder}
        {sharedByMe.map((group) => {
          const counts = summariseRecipients(group.recipients);

          return (
            <Card key={group.comicId}>
              <CardContent className="space-y-4 p-4">
                <div className="flex gap-4">
                  <ShareCover src={group.coverImagePath} title={group.title} />
                  <div className="min-w-0 flex-1">
                    <h3 className="truncate font-bold">{group.title}</h3>
                    <p className="truncate text-sm text-muted-foreground">{group.author}</p>
                    {group.explicitContent && (
                      <Badge variant="outline" className="mt-1">{EXPLICIT_FLAG_LABEL}</Badge>
                    )}
                    <p className="mt-1 text-sm text-muted-foreground">
                      {counts.total} {counts.total === 1 ? "recipient" : "recipients"}
                      {" · "}
                      {counts.accepted} accepted, {counts.pending} pending, {counts.declined} declined,{" "}
                      {counts.revoked} revoked
                    </p>
                  </div>
                  <div className="flex flex-none flex-col gap-2">
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => setInviteTarget({ id: group.comicId, title: group.title })}
                    >
                      <UserPlus className="mr-2 h-4 w-4" />
                      Invite someone
                    </Button>
                    {counts.accepted + counts.pending > 0 && (
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setStopSharingTarget(group)}
                      >
                        Stop sharing
                      </Button>
                    )}
                  </div>
                </div>

                <ul className="divide-y rounded border">
                  {group.recipients.map((recipient) => (
                    <li
                      key={recipient.id}
                      className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                    >
                      <div className="min-w-0">
                        <span className="block truncate text-sm">{recipient.recipientEmail}</span>
                        {recipient.status === SHARE_STATUS.PENDING && recipient.isExpired && (
                          <span className="text-xs text-muted-foreground">Invitation expired</span>
                        )}
                      </div>
                      <div className="flex flex-wrap items-center justify-end gap-2">
                        <Badge variant={STATUS_VARIANTS[recipient.status] || "outline"}>
                          {SHARE_STATUS_LABELS[recipient.status] || recipient.status}
                        </Badge>
                        <Button
                          size="sm"
                          variant="ghost"
                          aria-label={`Share another comic with ${recipient.recipientEmail}`}
                          onClick={() => setShareDialog({
                            recipient: recipient.recipientEmail,
                            comicIds: [],
                          })}
                        >
                          <UserPlus className="h-4 w-4" />
                          <span className="ml-2 hidden md:inline">Share another comic</span>
                        </Button>
                        {recipient.canResend && (
                          <Button
                            size="sm"
                            variant="ghost"
                            disabled={busyShareId === recipient.id}
                            onClick={() => runAction(
                              recipient.id,
                              () => api.post(`/api/shares/${recipient.id}/resend`, {}),
                              `Invitation resent to ${recipient.recipientEmail}.`
                            )}
                          >
                            {busyShareId === recipient.id
                              ? <Loader2 className="h-4 w-4 animate-spin" />
                              : <RotateCcw className="h-4 w-4" />}
                            <span className="ml-2 hidden sm:inline">Resend</span>
                          </Button>
                        )}
                        {recipient.canRevoke && (
                          <Button
                            size="sm"
                            variant="ghost"
                            disabled={busyShareId === recipient.id}
                            aria-label={`Revoke access for ${recipient.recipientEmail}`}
                            onClick={() => runAction(
                              recipient.id,
                              () => api.post(`/api/shares/${recipient.id}/revoke`, {}),
                              `Access revoked for ${recipient.recipientEmail}.`
                            )}
                          >
                            <XCircle className="h-4 w-4" />
                            <span className="ml-2 hidden sm:inline">Revoke</span>
                          </Button>
                        )}
                      </div>
                    </li>
                  ))}
                </ul>
              </CardContent>
            </Card>
          );
        })}
      </div>
    );
  };

  const confirmAdult = (share) => runAction(
    share.id,
    () => api.post(`/api/shares/${share.id}/confirm-adult`, { adultConfirmed: true }),
    "Age confirmed."
  );

  const renderReceivedList = (shares, { showActions }) => (
    <ul className="space-y-3">
      {shares.map((share) => {
        const gated = requiresAdultConfirmation(share);

        return (
          <li key={share.id}>
            <Card>
              <CardContent className="flex gap-4 p-4">
                <ShareCover src={share.coverImagePath} title={shareDisplayTitle(share)} gated={gated} />
                <div className="min-w-0 flex-1">
                  <h3 className="truncate font-bold">{shareDisplayTitle(share)}</h3>
                  <p className="truncate text-sm text-muted-foreground">
                    Shared by {share.ownerName}
                    {share.comicAuthor ? ` · ${share.comicAuthor}` : ""}
                  </p>
                  {gated && (
                    <p className="mt-1 flex items-center gap-1 text-sm font-medium text-destructive">
                      <ShieldAlert className="h-4 w-4" />
                      {EXPLICIT_GATE_TITLE}
                    </p>
                  )}
                  <p className="mt-1 text-sm text-muted-foreground">{describeReceivedShare(share)}</p>
                </div>
                {showActions && (
                  <div className="flex flex-none flex-col gap-2">
                    {gated && !share.isDead && (
                      <Button
                        size="sm"
                        disabled={busyShareId === share.id}
                        onClick={() => confirmAdult(share)}
                      >
                        {busyShareId === share.id && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                        {EXPLICIT_GATE_CONFIRM_LABEL}
                      </Button>
                    )}
                    {share.canRead && (
                      <Button size="sm" onClick={() => navigate(`/read/${share.comicId}`)}>
                        <BookOpen className="mr-2 h-4 w-4" />
                        Read
                      </Button>
                    )}
                    {share.canRemove && (
                      <Button
                        size="sm"
                        variant="outline"
                        disabled={busyShareId === share.id}
                        onClick={() => runAction(
                          share.id,
                          () => api.post(`/api/shares/${share.id}/remove`, {}),
                          "Removed from your collection."
                        )}
                      >
                        Remove from my collection
                      </Button>
                    )}
                    {share.canRestore && (
                      <Button
                        size="sm"
                        variant="outline"
                        disabled={busyShareId === share.id}
                        onClick={() => runAction(
                          share.id,
                          () => api.post(`/api/shares/${share.id}/restore`, {}),
                          "Restored to your collection."
                        )}
                      >
                        <Undo2 className="mr-2 h-4 w-4" />
                        Restore
                      </Button>
                    )}
                    {share.isDead && (
                      <Button
                        size="sm"
                        variant="ghost"
                        disabled={busyShareId === share.id}
                        onClick={() => runAction(
                          share.id,
                          () => api.delete("/api/shares/tombstones", { body: { shareIds: [share.id] } }),
                          "Entry removed."
                        )}
                      >
                        <Trash2 className="mr-2 h-4 w-4" />
                        Remove
                      </Button>
                    )}
                  </div>
                )}
              </CardContent>
            </Card>
          </li>
        );
      })}
    </ul>
  );

  const renderSharedWithMe = () => {
    if (sharedWithMe.length === 0) {
      return (
        <p className="py-12 text-center text-muted-foreground">
          Nobody has shared a comic with you yet.
        </p>
      );
    }

    return (
      <div className="space-y-8">
        {invitations.length > 0 && (
          <section>
            <h2 className="mb-3 text-lg font-semibold">Pending invitations</h2>
            <ul className="space-y-3">
              {invitations.map((share) => {
                const gated = requiresAdultConfirmation(share);

                return (
                  <li key={share.id}>
                    <Card>
                      <CardContent className="flex gap-4 p-4">
                        <ShareCover
                          src={share.coverImagePath}
                          title={shareDisplayTitle(share)}
                          gated={gated}
                        />
                        <div className="min-w-0 flex-1">
                          <h3 className="truncate font-bold">{shareDisplayTitle(share)}</h3>
                          <p className="truncate text-sm text-muted-foreground">
                            {share.ownerName} wants to share this with you.
                          </p>
                          {gated && (
                            <p className="mt-1 flex items-center gap-1 text-sm font-medium text-destructive">
                              <ShieldAlert className="h-4 w-4" />
                              {EXPLICIT_GATE_TITLE}
                            </p>
                          )}
                          <p className="mt-1 text-sm text-muted-foreground">
                            {describeReceivedShare(share)}
                          </p>
                        </div>
                        <div className="flex flex-none flex-col gap-2">
                          {gated ? (
                            <Button
                              size="sm"
                              disabled={!share.canAnswer || busyShareId === share.id}
                              onClick={() => confirmAdult(share)}
                            >
                              {EXPLICIT_GATE_CONFIRM_LABEL}
                            </Button>
                          ) : (
                            <Button
                              size="sm"
                              disabled={!share.canAnswer || busyShareId === share.id}
                              onClick={() => runAction(
                                share.id,
                                () => api.post(`/api/shares/${share.id}/accept`, {}),
                                "Comic added to your collection."
                              )}
                            >
                              Add to my collection
                            </Button>
                          )}
                          <Button
                            size="sm"
                            variant="outline"
                            disabled={!share.canAnswer || busyShareId === share.id}
                            onClick={() => runAction(
                              share.id,
                              () => api.post(`/api/shares/${share.id}/decline`, {}),
                              "Invitation declined."
                            )}
                          >
                            Decline
                          </Button>
                        </div>
                      </CardContent>
                    </Card>
                  </li>
                );
              })}
            </ul>
          </section>
        )}

        {collection.length > 0 && (
          <section>
            <h2 className="mb-3 text-lg font-semibold">Shared comics</h2>
            {renderReceivedList(collection, { showActions: true })}
          </section>
        )}

        {dead.length > 0 && (
          <section>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <h2 className="text-lg font-semibold">No longer available</h2>
              <Button variant="outline" size="sm" onClick={() => setConfirmingCleanup(true)}>
                <Trash2 className="mr-2 h-4 w-4" />
                Remove all dead shares ({dead.length})
              </Button>
            </div>
            {renderReceivedList(dead, { showActions: true })}
          </section>
        )}
      </div>
    );
  };

  return (
    <div className="container mx-auto px-4 py-8">
      <div className="mb-8 flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <Share2Icon className="h-6 w-6 text-comic-purple" />
          <h1 className="font-comic text-3xl">Sharing</h1>
        </div>
        <Button onClick={() => setShareDialog({ recipient: "", comicIds: [] })}>
          <UserPlus className="mr-2 h-4 w-4" />
          Share comics
        </Button>
      </div>

      <p className="mb-6 max-w-3xl text-sm text-muted-foreground">
        Sharing gives someone permission to read your comic. The file stays yours — nothing is
        copied — and you can withdraw access at any time.
      </p>

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
        <Tabs defaultValue="with-me" className="space-y-6">
          <TabsList>
            <TabsTrigger value="with-me">
              Shared with me ({sharedWithMe.length})
            </TabsTrigger>
            <TabsTrigger value="by-me">
              Shared by me ({sharedByMe.length})
            </TabsTrigger>
          </TabsList>
          <TabsContent value="with-me">{renderSharedWithMe()}</TabsContent>
          <TabsContent value="by-me">{renderSharedByMe()}</TabsContent>
        </Tabs>
      )}

      {shareDialog && (
        <ShareComicsDialog
          key={`${shareDialog.recipient}:${shareDialog.comicIds.join(",")}`}
          isOpen
          onClose={() => setShareDialog(null)}
          sharedByMe={sharedByMe}
          initialRecipient={shareDialog.recipient}
          initialComicIds={shareDialog.comicIds}
          onShared={refreshAfterShare}
        />
      )}

      {inviteTarget && (
        <ShareComicModal
          key={inviteTarget.id}
          isOpen
          onClose={() => setInviteTarget(null)}
          comicId={inviteTarget.id}
          comicTitle={inviteTarget.title}
          onShared={refreshAfterShare}
        />
      )}

      <Dialog open={confirmingCleanup} onOpenChange={setConfirmingCleanup}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{cleanupCopy.title}</DialogTitle>
            <DialogDescription>{cleanupCopy.body}</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setConfirmingCleanup(false)}>Cancel</Button>
            <Button
              variant="destructive"
              disabled={isDialogBusy}
              onClick={async () => {
                setIsDialogBusy(true);
                await runAction(
                  null,
                  () => api.delete("/api/shares/tombstones"),
                  "Unavailable shared comics removed."
                );
                setIsDialogBusy(false);
                setConfirmingCleanup(false);
              }}
            >
              Remove all dead shares
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={stopSharingTarget !== null} onOpenChange={() => setStopSharingTarget(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Stop sharing “{stopSharingTarget?.title}”?</DialogTitle>
            <DialogDescription>
              Everyone who currently has access, and everyone with a pending invitation, will lose
              it immediately. Your comic and its file are not affected.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setStopSharingTarget(null)}>Cancel</Button>
            <Button
              variant="destructive"
              disabled={isDialogBusy}
              onClick={async () => {
                const comicId = stopSharingTarget?.comicId;
                setIsDialogBusy(true);
                await runAction(
                  null,
                  () => api.delete(`/api/shares/comics/${comicId}`),
                  "Sharing stopped."
                );
                setIsDialogBusy(false);
                setStopSharingTarget(null);
              }}
            >
              Stop sharing with everyone
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
