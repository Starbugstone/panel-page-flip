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
import { ShareComicsDialog } from "@/components/ShareComicsDialog";
import { SharingCodesCard } from "@/components/SharingCodesCard";
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
  describeNotification,
  describeReceivedShare,
  groupReceivedShares,
  recipientLabel,
  recipientTarget,
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
        {/* No cover URL and none coming: for a gated share the server withheld
            it, so this placeholder stands in rather than a blurred real one. */}
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

  // The share currently being acted on, not the action being taken. Accept and
  // Decline are alternatives, as are Resend and Revoke, so keying this by
  // action would leave the opposite button live and let two conflicting
  // transitions be requested for one share at once.
  const [busyShareId, setBusyShareId] = useState(null);
  // The two confirmation dialogs act on a comic or on the whole history rather
  // than on one share, so they get their own flag instead of borrowing a share
  // id.
  const [isDialogBusy, setIsDialogBusy] = useState(false);
  // The multi-comic flow, or null when it is closed. Holds the recipient and
  // comics it should open with, so "Share another comic" can arrive with the
  // recipient already chosen.
  const [shareDialog, setShareDialog] = useState(null);
  const [confirmingCleanup, setConfirmingCleanup] = useState(false);
  const [stopSharingTarget, setStopSharingTarget] = useState(null);
  // Controlled so a completed share can move the page to the half that now has
  // something new on it. Answering an invitation is what most visits are for,
  // so that stays the tab the page opens on.
  const [activeTab, setActiveTab] = useState("with-me");
  // Bumped whenever a sharing action completes, so the codes card refetches
  // what has been handed out. A code created from the share dialog belongs in
  // that list straight away.
  const [codesReloadKey, setCodesReloadKey] = useState(0);

  const { invitations, collection, dead } = useMemo(
    () => groupReceivedShares(sharedWithMe),
    [sharedWithMe]
  );

  /**
   * Every action here changes what the collection contains, so the library is
   * reloaded alongside the sharing lists. Without that, accepting an invitation
   * would leave the dashboard showing a collection that predates it.
   */
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

  /**
   * What both sharing flows do once invitations exist: the new relationships
   * belong on **Shared by me**, and a comic that was not shared before now is.
   * Deliberately not wrapped in a try — the dialog reports a refresh failure
   * itself, and must not mistake one for a share that did not happen.
   */
  const refreshAfterShare = async () => {
    // Before the reload rather than after it, so a sender who started from the
    // header is not left looking at "Shared with me" wondering whether anything
    // happened — even if the refresh itself fails.
    setActiveTab("by-me");
    setCodesReloadKey((key) => key + 1);
    await reload();
    await loadLibrary();
  };

  /**
   * The mirror of the above, for comics arriving rather than leaving. Redeeming
   * a code puts them under **Shared with me**, which is already the tab the page
   * opens on, so this only reloads.
   */
  const refreshAfterReceiving = async () => {
    setActiveTab("with-me");
    await reload();
    await loadLibrary();
  };

  const cleanupCopy = describeDeadShareCleanup(dead.length);

  /**
   * The sender-side reminder.
   *
   * Informational only — the acknowledgement that actually goes on the record
   * is the tick box in the share dialog, once per share. This is here so the
   * expectation is visible while somebody is looking at what they have already
   * handed out, not only at the moment they hand out the next one.
   */
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
              onClick={() => setShareDialog({ email: "", username: "", userCode: "", comicIds: [] })}
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
                      onClick={() => setShareDialog({
                        email: "",
                        username: "",
                        userCode: "",
                        comicIds: [group.comicId],
                      })}
                    >
                      <UserPlus className="mr-2 h-4 w-4" />
                      Share this comic
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
                      {/* Every share here belongs to a comic the owner still
                          has — a deleted one leaves this list entirely — so the
                          only state worth calling out is a lapsed invitation.
                          Somebody reached by their sharing code is named rather
                          than addressed: the point of the code was that the
                          sender never learned the address. */}
                      <div className="min-w-0">
                        <span className="block truncate text-sm">{recipientLabel(recipient)}</span>
                        {recipient.recipientUserCode && (
                          <span className="block text-xs text-muted-foreground">
                            User code {recipient.recipientUserCode}
                          </span>
                        )}
                        {recipient.status === SHARE_STATUS.PENDING && recipient.isExpired && (
                          <span className="block text-xs text-muted-foreground">Invitation expired</span>
                        )}
                        {/* The share is real whatever this says; it is here so
                            an owner whose mail server was having a bad
                            afternoon knows why nobody has answered. */}
                        {describeNotification(recipient) && (
                          <span className="block text-xs text-destructive">
                            {describeNotification(recipient)}
                          </span>
                        )}
                      </div>
                      <div className="flex flex-wrap items-center justify-end gap-2">
                        <Badge variant={STATUS_VARIANTS[recipient.status] || "outline"}>
                          {SHARE_STATUS_LABELS[recipient.status] || recipient.status}
                        </Badge>
                        <Button
                          size="sm"
                          variant="ghost"
                          aria-label={`Share another comic with ${recipientLabel(recipient)}`}
                          onClick={() => setShareDialog({
                            ...recipientTarget(recipient),
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
                              `Invitation resent to ${recipientLabel(recipient)}.`
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
                            aria-label={`Revoke access for ${recipientLabel(recipient)}`}
                            onClick={() => runAction(
                              recipient.id,
                              () => api.post(`/api/shares/${recipient.id}/revoke`, {}),
                              `Access revoked for ${recipientLabel(recipient)}.`
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

  /**
   * Record the age declaration for one share and reload.
   *
   * The same endpoint the invitation page uses, because it is the same
   * declaration: the gate follows the share, not the screen it is met on.
   */
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
                    Shared by {share.ownerLabel || share.ownerName}
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
                    {/* A tombstone offers no way to read anything — there is
                        nothing left behind it — only a way to clear the entry. */}
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
                            {share.ownerLabel || share.ownerName} wants to share this with you.
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
                        {/* The emailed link is not the only way in: somebody
                            signed in and looking at their own invitation has
                            already identified themselves. That still is not an
                            age declaration, so an explicit invitation offers the
                            gate here instead of Accept. */}
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
        <Button onClick={() => setShareDialog({ email: "", username: "", userCode: "", comicIds: [] })}>
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
      <SharingCodesCard onRedeemed={refreshAfterReceiving} reloadKey={codesReloadKey} />

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
        <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
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
          initialComicIds={shareDialog.comicIds}
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
