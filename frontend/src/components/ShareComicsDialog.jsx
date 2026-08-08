import { useEffect, useMemo, useState } from "react";
import { Loader2, Search, Share2 } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import {
  EXPLICIT_FLAG_LABEL,
  SHARE_RESPONSIBILITY_ACK_LABEL,
  SHARE_RESPONSIBILITY_NOTICE,
  SHARE_STATUS,
  isValidShareEmail,
} from "@/lib/sharing";
import { useToast } from "@/hooks/use-toast";

// Mirrors SharingWorkflowService::MAX_BULK_COMICS. The backend remains the
// authority; this only stops the UI inviting somebody to select work that the
// request will reject.
const MAX_BULK_COMICS = 20;

const normaliseEmail = (value) => (typeof value === "string" ? value.trim().toLowerCase() : "");

function liveComicIdsForRecipient(sharedByMe, email) {
  const wanted = normaliseEmail(email);
  const ids = new Set();
  if (!wanted) return ids;

  (sharedByMe || []).forEach((group) => {
    const alreadyLive = (group.recipients || []).some((recipient) => {
      if (normaliseEmail(recipient.recipientEmail) !== wanted) return false;
      if (recipient.status === SHARE_STATUS.ACCEPTED) return true;
      return recipient.status === SHARE_STATUS.PENDING && !recipient.isExpired;
    });

    if (alreadyLive) ids.add(String(group.comicId));
  });

  return ids;
}

export function ShareComicsDialog({
  isOpen,
  onClose,
  sharedByMe = [],
  initialRecipient = "",
  initialComicIds = [],
  onShared,
}) {
  const [comics, setComics] = useState([]);
  const [recentRecipients, setRecentRecipients] = useState([]);
  const [search, setSearch] = useState("");
  const [recipientEmail, setRecipientEmail] = useState(initialRecipient);
  const [selectedIds, setSelectedIds] = useState(
    () => new Set((initialComicIds || []).map((id) => String(id)))
  );
  const [responsibilityAccepted, setResponsibilityAccepted] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [isSending, setIsSending] = useState(false);
  const [error, setError] = useState(null);
  const { toast } = useToast();

  useEffect(() => {
    if (!isOpen) return undefined;

    let ignore = false;
    setIsLoading(true);
    setError(null);

    Promise.all([
      api.get("/api/comics?ownership=mine"),
      api.get("/api/shares/recent-recipients"),
    ])
      .then(([library, recipients]) => {
        if (ignore) return;
        // ownership=mine is already scoped server-side; canShare is a second UI
        // guard so a future payload change cannot make a received comic appear
        // selectable here.
        setComics((library.comics || []).filter((comic) => comic.canShare === true));
        setRecentRecipients(recipients.recipients || []);
      })
      .catch((err) => {
        if (ignore) return;
        logger.error("Failed to load sharing picker:", err);
        setError(err.message || "Could not load your shareable comics.");
      })
      .finally(() => {
        if (!ignore) setIsLoading(false);
      });

    return () => { ignore = true; };
  }, [isOpen]);

  const alreadySharedIds = useMemo(
    () => liveComicIdsForRecipient(sharedByMe, recipientEmail),
    [sharedByMe, recipientEmail]
  );

  const filteredComics = useMemo(() => {
    const query = search.trim().toLowerCase();
    if (!query) return comics;

    return comics.filter((comic) =>
      [comic.title, comic.author]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(query))
    );
  }, [comics, search]);

  const selectedComicIds = useMemo(
    () => [...selectedIds].filter((id) => !alreadySharedIds.has(id)),
    [selectedIds, alreadySharedIds]
  );

  const selectedComics = useMemo(() => {
    const selected = new Set(selectedComicIds);
    return comics.filter((comic) => selected.has(String(comic.id)));
  }, [comics, selectedComicIds]);

  const visibleSelectable = filteredComics.filter(
    (comic) => !alreadySharedIds.has(String(comic.id))
  );
  const allVisibleSelected = visibleSelectable.length > 0
    && visibleSelectable.every((comic) => selectedIds.has(String(comic.id)));

  const toggleComic = (comicId) => {
    const id = String(comicId);
    if (alreadySharedIds.has(id)) return;

    setSelectedIds((current) => {
      const next = new Set(current);
      if (next.has(id)) {
        next.delete(id);
      } else if (selectedComicIds.length < MAX_BULK_COMICS) {
        next.add(id);
      }
      return next;
    });
  };

  const toggleVisible = () => {
    setSelectedIds((current) => {
      const next = new Set(current);
      if (allVisibleSelected) {
        visibleSelectable.forEach((comic) => next.delete(String(comic.id)));
        return next;
      }

      let slots = MAX_BULK_COMICS - [...next].filter((id) => !alreadySharedIds.has(id)).length;
      for (const comic of visibleSelectable) {
        if (slots <= 0) break;
        const id = String(comic.id);
        if (!next.has(id)) {
          next.add(id);
          --slots;
        }
      }
      return next;
    });
  };

  const handleSubmit = async () => {
    if (!isValidShareEmail(recipientEmail)) {
      setError("Please enter a valid recipient email address.");
      return;
    }
    if (selectedComicIds.length === 0) {
      setError("Select at least one comic to share.");
      return;
    }
    if (!responsibilityAccepted) {
      setError("Please confirm that you understand you are responsible for what you share.");
      return;
    }

    setIsSending(true);
    setError(null);

    try {
      const data = await api.post("/api/shares/invitations/bulk", {
        comicIds: selectedComicIds.map(Number),
        email: recipientEmail.trim(),
        senderResponsibilityAccepted: true,
      });

      const created = Number(data.created || 0);
      const total = Number(data.total || selectedComicIds.length);
      const skipped = Math.max(0, total - created);

      if (created === 0) {
        const message = data.results?.find((result) => result.message)?.message
          || "No new invitations were created.";
        setError(message);
        return;
      }

      toast({
        title: created === 1 ? "Comic shared" : `${created} comics shared`,
        description: skipped > 0
          ? `${skipped} ${skipped === 1 ? "comic was" : "comics were"} skipped. Check the Sharing list for current access.`
          : `Invitation${created === 1 ? "" : "s"} sent to ${recipientEmail.trim()}.`,
      });

      await onShared?.();
      onClose();
    } catch (err) {
      logger.error("Bulk sharing failed:", err);
      setError(err.message || "The comics could not be shared.");
    } finally {
      setIsSending(false);
    }
  };

  const selectionLimitReached = selectedComicIds.length >= MAX_BULK_COMICS;

  return (
    <Dialog
      open={isOpen}
      onOpenChange={(open) => {
        if (!open && !isSending) onClose();
      }}
    >
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-[720px]">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Share2 className="h-5 w-5" />
            Share comics
          </DialogTitle>
          <DialogDescription>
            Choose comics you own, then send a private invitation to an exact email address or
            somebody you have shared with before. Registered users are never searched or listed.
          </DialogDescription>
        </DialogHeader>

        {isLoading ? (
          <div className="flex items-center gap-2 py-12 text-muted-foreground">
            <Loader2 className="h-5 w-5 animate-spin" />
            Loading your comics…
          </div>
        ) : (
          <div className="space-y-6 py-2">
            <section className="space-y-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <h3 className="font-semibold">1. Choose comics</h3>
                  <p className="text-xs text-muted-foreground">
                    {selectedComicIds.length}/{MAX_BULK_COMICS} selected
                  </p>
                </div>
                {visibleSelectable.length > 0 && (
                  <Button type="button" variant="outline" size="sm" onClick={toggleVisible}>
                    {allVisibleSelected ? "Clear shown" : "Select shown"}
                  </Button>
                )}
              </div>

              <div className="relative">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  placeholder="Search your library…"
                  className="pl-9"
                />
              </div>

              <div className="max-h-64 divide-y overflow-y-auto rounded-md border">
                {filteredComics.length === 0 ? (
                  <p className="p-4 text-sm text-muted-foreground">No owned comics match this search.</p>
                ) : filteredComics.map((comic) => {
                  const id = String(comic.id);
                  const alreadyShared = alreadySharedIds.has(id);
                  const checked = selectedIds.has(id) && !alreadyShared;
                  const disabled = alreadyShared || (selectionLimitReached && !checked);

                  return (
                    <label
                      key={comic.id}
                      className="flex cursor-pointer items-center gap-3 p-3 has-[[data-disabled]]:cursor-not-allowed has-[[data-disabled]]:opacity-60"
                    >
                      <Checkbox
                        checked={checked}
                        disabled={disabled}
                        onCheckedChange={() => toggleComic(comic.id)}
                        aria-label={`Select ${comic.title}`}
                      />
                      {comic.coverImagePath ? (
                        <img
                          src={comic.coverImagePath}
                          alt=""
                          loading="lazy"
                          className="h-14 w-10 flex-none rounded object-cover"
                        />
                      ) : (
                        <div className="h-14 w-10 flex-none rounded bg-muted" />
                      )}
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium">{comic.title}</p>
                        {comic.author && (
                          <p className="truncate text-xs text-muted-foreground">{comic.author}</p>
                        )}
                        {comic.explicitContent && (
                          <Badge variant="outline" className="mt-1">{EXPLICIT_FLAG_LABEL}</Badge>
                        )}
                      </div>
                      {alreadyShared && (
                        <Badge variant="secondary">Already shared</Badge>
                      )}
                    </label>
                  );
                })}
              </div>
            </section>

            <section className="space-y-3">
              <div>
                <h3 className="font-semibold">2. Share with</h3>
                <p className="text-xs text-muted-foreground">
                  Recent recipients are only addresses you previously entered yourself.
                </p>
              </div>

              {recentRecipients.length > 0 && (
                <div className="flex flex-wrap gap-2" aria-label="Recent recipients">
                  {recentRecipients.map((recipient) => (
                    <Button
                      key={recipient.email}
                      type="button"
                      size="sm"
                      variant={normaliseEmail(recipientEmail) === normaliseEmail(recipient.email) ? "default" : "outline"}
                      onClick={() => setRecipientEmail(recipient.email)}
                    >
                      {recipient.email}
                    </Button>
                  ))}
                </div>
              )}

              <div className="grid gap-2">
                <Label htmlFor="bulk-share-email">Recipient email</Label>
                <Input
                  id="bulk-share-email"
                  type="email"
                  autoComplete="off"
                  value={recipientEmail}
                  onChange={(event) => setRecipientEmail(event.target.value)}
                  placeholder="recipient@example.com"
                  disabled={isSending}
                />
              </div>
            </section>

            <section className="space-y-3">
              <div>
                <h3 className="font-semibold">3. Review</h3>
                <p className="text-sm text-muted-foreground">
                  {selectedComics.length === 0
                    ? "Select at least one comic above."
                    : `${selectedComics.length} ${selectedComics.length === 1 ? "comic" : "comics"} will be offered to ${recipientEmail.trim() || "the recipient"}.`}
                </p>
              </div>

              {selectedComics.length > 0 && (
                <ul className="max-h-28 list-disc overflow-y-auto pl-5 text-sm">
                  {selectedComics.map((comic) => <li key={comic.id}>{comic.title}</li>)}
                </ul>
              )}

              <div className="space-y-3 rounded-md border p-3">
                <p className="text-sm text-muted-foreground">{SHARE_RESPONSIBILITY_NOTICE}</p>
                <div className="flex items-center gap-2">
                  <Checkbox
                    id="bulk-share-responsibility"
                    checked={responsibilityAccepted}
                    onCheckedChange={(checked) => setResponsibilityAccepted(checked === true)}
                    disabled={isSending}
                  />
                  <Label htmlFor="bulk-share-responsibility" className="cursor-pointer text-sm font-medium">
                    {SHARE_RESPONSIBILITY_ACK_LABEL}
                  </Label>
                </div>
              </div>
            </section>

            {error && <p className="text-sm text-destructive">{error}</p>}
          </div>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={isSending}>Cancel</Button>
          <Button
            onClick={handleSubmit}
            disabled={
              isLoading
              || isSending
              || selectedComicIds.length === 0
              || !isValidShareEmail(recipientEmail)
              || !responsibilityAccepted
            }
          >
            {isSending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            Send {selectedComicIds.length > 1 ? `${selectedComicIds.length} invitations` : "invitation"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
