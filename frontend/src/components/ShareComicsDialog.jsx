import { useEffect, useMemo, useState } from "react";
import { Check, Copy, Loader2, Search, Share2, UserCheck } from "lucide-react";

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
  SHARING_CODE_COPY,
  buildInvitationRequest,
  formatSharingCode,
  isValidShareEmail,
  isValidSharingCode,
  normaliseSharingCode,
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

/**
 * The three ways to name who a share is for.
 *
 * They differ only in how the recipient is identified; the comics, the review
 * step and the responsibility acknowledgement are the same for all three,
 * because the share they produce is the same share.
 */
const MODES = {
  EMAIL: "email",
  CODE: "code",
  CLAIM: "claim",
};

const MAX_CLAIM_USES = 10;

export function ShareComicsDialog({
  isOpen,
  onClose,
  sharedByMe = [],
  initialRecipient = "",
  initialSharingCode = "",
  initialComicIds = [],
  onShared,
}) {
  const [comics, setComics] = useState([]);
  const [recentRecipients, setRecentRecipients] = useState([]);
  const [search, setSearch] = useState("");
  const [mode, setMode] = useState(initialSharingCode ? MODES.CODE : MODES.EMAIL);
  const [recipientEmail, setRecipientEmail] = useState(initialRecipient);
  const [sharingCode, setSharingCode] = useState(initialSharingCode);
  // The name behind a resolved code. Held separately from the code itself so a
  // typed character invalidates the confirmation rather than leaving a stale
  // name sitting next to a different code.
  const [codeRecipient, setCodeRecipient] = useState(null);
  const [isResolvingCode, setIsResolvingCode] = useState(false);
  // Held as text, so the field can be emptied and retyped. Clamping every
  // keystroke turns clearing "1" and typing "4" into 14.
  const [claimUses, setClaimUses] = useState("1");
  const [issuedClaimCode, setIssuedClaimCode] = useState(null);
  const [claimCodeCopied, setClaimCodeCopied] = useState(false);
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

  // Only meaningful for an email recipient. A code names somebody the sender
  // cannot match against their own list, and a claim code names nobody at all,
  // so neither can mark a comic as already shared.
  const alreadySharedIds = useMemo(
    () => liveComicIdsForRecipient(sharedByMe, mode === MODES.EMAIL ? recipientEmail : ""),
    [sharedByMe, mode, recipientEmail]
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

  /** Check a code names somebody, before anything is offered to them. */
  const resolveSharingCode = async () => {
    setIsResolvingCode(true);
    setError(null);
    setCodeRecipient(null);

    try {
      const data = await api.post("/api/shares/resolve-code", {
        sharingCode: normaliseSharingCode(sharingCode),
      });
      setCodeRecipient(data.recipient);
    } catch (err) {
      logger.error("Resolving a sharing code failed:", err);
      setError(err.message || "That sharing code is not valid.");
    } finally {
      setIsResolvingCode(false);
    }
  };

  const copyClaimCode = async () => {
    try {
      await navigator.clipboard.writeText(issuedClaimCode);
      setClaimCodeCopied(true);
      setTimeout(() => setClaimCodeCopied(false), 2000);
    } catch (err) {
      logger.error("Could not copy the sharing code:", err);
      toast({
        title: "Could not copy the code",
        description: "Select the code and copy it manually.",
        variant: "destructive",
      });
    }
  };

  const handleSubmit = async () => {
    if (selectedComicIds.length === 0) {
      setError("Select at least one comic to share.");
      return;
    }
    if (!responsibilityAccepted) {
      setError("Please confirm that you understand you are responsible for what you share.");
      return;
    }
    if (mode === MODES.EMAIL && !isValidShareEmail(recipientEmail)) {
      setError("Please enter a valid recipient email address.");
      return;
    }
    if (mode === MODES.CODE && !isValidSharingCode(sharingCode)) {
      setError("Please enter a valid sharing code.");
      return;
    }

    const recipient = recipientEmail.trim();

    setIsSending(true);
    setError(null);

    try {
      if (mode === MODES.CLAIM) {
        const data = await api.post("/api/shares/claim-codes", {
          comicIds: selectedComicIds.map(Number),
          maxUses: claimUsesValue,
          senderResponsibilityAccepted: true,
        });

        // Shown rather than auto-closing: this is the only moment the code
        // exists in a readable form, because the server keeps only its hash.
        setIssuedClaimCode(data.code);
        toast({
          title: "Sharing code created",
          description: `Anyone you give it to can claim ${selectedComicIds.length === 1 ? "this comic" : "these comics"}, `
            + `${claimUsesValue === 1 ? "once" : `up to ${claimUsesValue} times`}, within 24 hours.`,
        });

        try {
          await onShared?.();
        } catch (refreshError) {
          logger.error("Sharing data refresh failed:", refreshError);
        }

        return;
      }

      // Exactly one of the two ways to name a recipient goes on the wire. The
      // server picks the code when it is present, so sending both would make
      // the typed address silently irrelevant.
      const data = await api.post("/api/shares/invitations/bulk", mode === MODES.CODE
        ? {
          comicIds: selectedComicIds.map(Number),
          sharingCode: normaliseSharingCode(sharingCode),
          senderResponsibilityAccepted: true,
        }
        : {
          ...buildInvitationRequest({ email: recipient, responsibilityAccepted: true }),
          comicIds: selectedComicIds.map(Number),
        });

      const results = Array.isArray(data.results) ? data.results : [];
      const created = Number(data.created) || results.filter((r) => r.status === "created").length;
      // Everything the server did not create, with the first reason it gave.
      // Reporting these as "skipped" would tell somebody whose comic was
      // refused that their share went through.
      const refused = results.filter((result) => result.status !== "created");
      const reason = refused.find((result) => result.message)?.message;

      if (created === 0) {
        setError(reason || "No new invitations were created.");
        return;
      }

      // The recipient as the sender knows them. Reached by code, that is a
      // name — the address is exactly what they were never given.
      const sentTo = mode === MODES.CODE
        ? (codeRecipient?.name || "them")
        : recipient;

      toast({
        title: created === 1 ? "Comic shared" : `${created} comics shared`,
        // One email, however many comics went into it — so this says what the
        // recipient actually receives rather than implying a message each.
        description: refused.length > 0
          ? `One invitation email was sent to ${sentTo}. `
            + `${refused.length} ${refused.length === 1 ? "comic was" : "comics were"} left out`
            + `${reason ? `: ${reason}` : "."}`
          : `One invitation email was sent to ${sentTo}.`,
      });

      try {
        await onShared?.();
      } catch (refreshError) {
        logger.error("Sharing data refresh failed:", refreshError);
        toast({
          title: "Invitation sent",
          description: "The invitation was sent, but the Sharing list could not refresh. Reload the page to see the latest state.",
          variant: "destructive",
        });
      }

      // The invitations already exist at this point. Always close so a refresh
      // failure cannot encourage the sender to submit the same share again.
      onClose();
    } catch (err) {
      logger.error("Bulk sharing failed:", err);
      setError(err.message || "The comics could not be shared.");
    } finally {
      setIsSending(false);
    }
  };

  const selectionLimitReached = selectedComicIds.length >= MAX_BULK_COMICS;

  /** The typed uses, made legal. The server enforces the same range. */
  const claimUsesValue = Math.min(MAX_CLAIM_USES, Math.max(1, Number(claimUses) || 1));

  // Who this share is for, in the sender's own terms. A code recipient is a
  // name once it has been checked, and the code itself before that — never an
  // address, which is the whole reason a code was used.
  const recipientDescription = mode === MODES.CLAIM
    ? "whoever you give the code to"
    : mode === MODES.CODE
      ? (codeRecipient?.name || (isValidSharingCode(sharingCode) ? sharingCode : "the recipient"))
      : (recipientEmail.trim() || "the recipient");

  const comicCountLabel = `${selectedComics.length} ${selectedComics.length === 1 ? "comic" : "comics"}`;

  const reviewSummary = selectedComics.length === 0
    ? "Select at least one comic above."
    : mode === MODES.CLAIM
      ? `${comicCountLabel} will be put behind a code that ${claimUsesValue === 1 ? "one person" : `up to ${claimUsesValue} people`} `
        + "can claim within 24 hours. You can withdraw the code, or any share it created, at any time."
      : `${comicCountLabel} will be offered to ${recipientDescription} in one invitation email. `
        + "They must accept each one before they can read it, and you can withdraw any of them later.";

  const recipientChosen = mode === MODES.CLAIM
    || (mode === MODES.EMAIL && isValidShareEmail(recipientEmail))
    || (mode === MODES.CODE && isValidSharingCode(sharingCode));

  const canSubmit = selectedComicIds.length > 0 && responsibilityAccepted && recipientChosen;

  const submitLabel = mode === MODES.CLAIM
    ? "Create sharing code"
    : `Send ${selectedComicIds.length > 1 ? `${selectedComicIds.length} invitations` : "invitation"}`;

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
                      className={`flex items-center gap-3 p-3 ${disabled ? "cursor-not-allowed opacity-60" : "cursor-pointer"}`}
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
              <h3 className="font-semibold">2. Share with</h3>

              {/* One line of explanation per mode, swapped with the mode, so the
                  dialog says what the chosen option does without stacking three
                  paragraphs of guidance on top of each other. */}
              <div className="flex flex-wrap gap-1 rounded-md border p-1" role="tablist">
                {[
                  [MODES.EMAIL, "Email address"],
                  [MODES.CODE, "Sharing code"],
                  [MODES.CLAIM, "Create a code"],
                ].map(([value, label]) => (
                  <Button
                    key={value}
                    type="button"
                    role="tab"
                    size="sm"
                    variant={mode === value ? "default" : "ghost"}
                    aria-selected={mode === value}
                    disabled={isSending || issuedClaimCode !== null}
                    onClick={() => { setMode(value); setError(null); }}
                  >
                    {label}
                  </Button>
                ))}
              </div>

              {mode === MODES.EMAIL && (
                <div className="space-y-3">
                  <p className="text-xs text-muted-foreground">
                    Recent recipients are only people you have shared with before. Registered users
                    are never searched or listed.
                  </p>

                  {recentRecipients.length > 0 && (
                    <div className="flex flex-wrap gap-2" aria-label="Recent recipients">
                      {recentRecipients.filter((recipient) => recipient.email).map((recipient) => (
                        <Button
                          key={recipient.email}
                          type="button"
                          size="sm"
                          variant={normaliseEmail(recipientEmail) === normaliseEmail(recipient.email) ? "default" : "outline"}
                          aria-pressed={normaliseEmail(recipientEmail) === normaliseEmail(recipient.email)}
                          disabled={isSending}
                          onClick={() => setRecipientEmail(recipient.email)}
                        >
                          {recipient.label || recipient.email}
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
                </div>
              )}

              {mode === MODES.CODE && (
                <div className="space-y-3">
                  <p className="text-xs text-muted-foreground">{SHARING_CODE_COPY.recipient}</p>

                  {recentRecipients.some((recipient) => recipient.sharingCode) && (
                    <div className="flex flex-wrap gap-2" aria-label="Recent code recipients">
                      {recentRecipients.filter((recipient) => recipient.sharingCode).map((recipient) => (
                        <Button
                          key={recipient.sharingCode}
                          type="button"
                          size="sm"
                          variant={sharingCode === recipient.sharingCode ? "default" : "outline"}
                          aria-pressed={sharingCode === recipient.sharingCode}
                          disabled={isSending}
                          onClick={() => {
                            setSharingCode(recipient.sharingCode);
                            setCodeRecipient({ name: recipient.name });
                          }}
                        >
                          {recipient.label}
                        </Button>
                      ))}
                    </div>
                  )}

                  <div className="grid gap-2">
                    <Label htmlFor="bulk-share-code">Their sharing code</Label>
                    <div className="flex gap-2">
                      <Input
                        id="bulk-share-code"
                        autoComplete="off"
                        value={sharingCode}
                        onChange={(event) => {
                          setSharingCode(formatSharingCode(event.target.value));
                          // A changed code is a different person until checked.
                          setCodeRecipient(null);
                        }}
                        placeholder="XXXX-XXXX-XXXX"
                        className="font-mono tracking-widest"
                        disabled={isSending}
                      />
                      <Button
                        type="button"
                        variant="outline"
                        onClick={resolveSharingCode}
                        disabled={isSending || isResolvingCode || !isValidSharingCode(sharingCode)}
                      >
                        {isResolvingCode && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                        Check
                      </Button>
                    </div>
                    {codeRecipient && (
                      <p className="flex items-center gap-1 text-sm text-muted-foreground">
                        <UserCheck className="h-4 w-4" />
                        Sharing with <span className="font-medium">{codeRecipient.name}</span>
                      </p>
                    )}
                  </div>
                </div>
              )}

              {mode === MODES.CLAIM && (
                <div className="space-y-3">
                  <p className="text-xs text-muted-foreground">{SHARING_CODE_COPY.claim}</p>

                  {issuedClaimCode ? (
                    <div className="space-y-2 rounded-md border p-3">
                      <p className="text-sm font-medium">Your code — copy it now</p>
                      <div className="flex items-center gap-2">
                        <code
                          className="flex-1 rounded bg-muted px-3 py-2 font-mono text-sm tracking-widest"
                          aria-label="Your new sharing code"
                        >
                          {issuedClaimCode}
                        </code>
                        <Button variant="outline" size="sm" onClick={copyClaimCode} aria-label="Copy the sharing code">
                          {claimCodeCopied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                        </Button>
                      </div>
                      <p className="text-xs text-muted-foreground">
                        It is not stored and cannot be shown again. Create another if you lose it.
                      </p>
                    </div>
                  ) : (
                    <div className="grid gap-2">
                      <Label htmlFor="bulk-share-uses">How many people may use it</Label>
                      <div className="flex items-center gap-3">
                        <Input
                          id="bulk-share-uses"
                          type="number"
                          min={1}
                          max={MAX_CLAIM_USES}
                          value={claimUses}
                          onChange={(event) => setClaimUses(
                            event.target.value.replace(/[^0-9]/g, "").slice(0, 2)
                          )}
                          // Corrected when the field is left rather than as it
                          // is typed, so what is sent is always what is shown.
                          onBlur={() => setClaimUses(String(claimUsesValue))}
                          className="w-24"
                          disabled={isSending}
                        />
                        <span className="text-xs text-muted-foreground">
                          1–{MAX_CLAIM_USES} uses · expires after 24 hours
                        </span>
                      </div>
                    </div>
                  )}
                </div>
              )}
            </section>

            <section className="space-y-3">
              <div>
                <h3 className="font-semibold">3. Review</h3>
                <p className="text-sm text-muted-foreground">{reviewSummary}</p>
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
          <Button variant="outline" onClick={onClose} disabled={isSending}>
            {issuedClaimCode ? "Done" : "Cancel"}
          </Button>
          {!issuedClaimCode && (
            <Button onClick={handleSubmit} disabled={isLoading || isSending || !canSubmit}>
              {isSending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              {submitLabel}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
