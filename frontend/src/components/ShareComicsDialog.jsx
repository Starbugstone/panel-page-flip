import { useEffect, useMemo, useState } from "react";
import { AlertTriangle, Check, Copy, Loader2, Search, Share2, UserCheck } from "lucide-react";

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
import { copyText } from "@/lib/clipboard";
import { logger } from "@/lib/logger";
import {
  EXPLICIT_FLAG_LABEL,
  SHARE_CODE_TYPES,
  SHARE_RESPONSIBILITY_ACK_LABEL,
  SHARE_RESPONSIBILITY_NOTICE,
  SHARE_STATUS,
  SHARING_CODE_COPY,
  formatShareCode,
  isValidShareEmail,
  isValidShareCode,
  isValidUsername,
  parseShareCode,
  shareCodeMisuse,
  stripUsernamePrefix,
  usernameHandle,
} from "@/lib/sharing";
import { useToast } from "@/hooks/use-toast";

// Mirrors SharingWorkflowService::MAX_BULK_COMICS and ShareClaimCode's group
// ceiling, which are the same number. The backend remains the authority; this
// only stops the UI inviting somebody to select work the request will reject.
const MAX_BULK_COMICS = 20;
const MIN_GROUP_COMICS = 2;
const MAX_CODE_USES = 10;

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
 * The two things a share can be.
 *
 * **Direct** names a person — by username, by `U-` code, by address, or by
 * picking somebody the owner has shared with before. **Code** names nobody and
 * produces a `C-` or a `G-` depending on how many comics are selected, because
 * that is the whole difference between the two prefixes.
 */
const MODES = {
  DIRECT: "direct",
  CODE: "code",
};

/** How the recipient of a direct share is being named. */
const TARGETS = {
  USERNAME: "username",
  CODE: "code",
  EMAIL: "email",
};

/**
 * The one share workflow, wherever it is opened from.
 *
 * A grid card, a table selection and the Sharing page all arrive here, because
 * three dialogs that each grew their own idea of what a share is are three
 * places for the rules to drift apart. What differs between the entry points is
 * only what is already chosen when it opens.
 */
export function ShareComicsDialog({
  isOpen,
  onClose,
  sharedByMe = [],
  initialRecipient = "",
  initialUsername = "",
  initialUserCode = "",
  /**
   * The identity behind `initialUsername`/`initialUserCode`, when the caller
   * already has it.
   *
   * Sharing again with an existing recipient opens from a button that names
   * them, and the server produced that name from a relationship this owner
   * already holds — so there is nothing for a Check to establish. Without this
   * the confirmation gate would make somebody re-confirm a person they are
   * already sharing with, which is friction with no question behind it.
   */
  initialResolved = null,
  initialComicIds = [],
  /** Hide the picker: the caller has already chosen, and re-picking is a step. */
  lockSelection = false,
  initialMode = MODES.DIRECT,
  onShared,
}) {
  const [comics, setComics] = useState([]);
  const [recentRecipients, setRecentRecipients] = useState([]);
  const [search, setSearch] = useState("");
  const [mode, setMode] = useState(initialMode);
  const [target, setTarget] = useState(() => {
    if (initialUserCode) return TARGETS.CODE;
    if (initialRecipient) return TARGETS.EMAIL;
    return TARGETS.USERNAME;
  });
  const [username, setUsername] = useState(initialUsername);
  const [recipientEmail, setRecipientEmail] = useState(initialRecipient);
  const [userCode, setUserCode] = useState(initialUserCode);
  // Who the typed identifier resolves to. Held separately so a typed character
  // invalidates the confirmation rather than leaving a stale name sitting next
  // to a different handle.
  const [resolved, setResolved] = useState(initialResolved);
  const [isResolving, setIsResolving] = useState(false);
  // Held as text, so the field can be emptied and retyped. Clamping every
  // keystroke turns clearing "1" and typing "4" into 14.
  const [maxUses, setMaxUses] = useState("1");
  const [issuedCode, setIssuedCode] = useState(null);
  const [issuedExpiry, setIssuedExpiry] = useState(null);
  const [codeCopied, setCodeCopied] = useState(false);
  const [selectedIds, setSelectedIds] = useState(
    () => new Set((initialComicIds || []).map((id) => String(id)))
  );
  const [markExplicit, setMarkExplicit] = useState(false);
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

  // Only meaningful for an email recipient. A username or code names somebody
  // the sender cannot match against their own list, and a content code names
  // nobody at all, so neither can mark a comic as already shared.
  const alreadySharedIds = useMemo(
    () => liveComicIdsForRecipient(
      sharedByMe,
      mode === MODES.DIRECT && target === TARGETS.EMAIL ? recipientEmail : ""
    ),
    [sharedByMe, mode, target, recipientEmail]
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

  // What a code made from this selection would be. One comic is a C-, two or
  // more a G-; that is the entire difference, and it is decided here rather
  // than asked of the user.
  const codeType = selectedComicIds.length >= MIN_GROUP_COMICS
    ? SHARE_CODE_TYPES.GROUP
    : SHARE_CODE_TYPES.COMIC;

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

  /** Check the identifier names somebody, before anything is offered to them. */
  const resolveRecipient = async () => {
    setIsResolving(true);
    setError(null);
    setResolved(null);

    try {
      const data = target === TARGETS.CODE
        ? await api.post("/api/shares/user-code/resolve", { userCode: parseShareCode(userCode)?.code })
        : await api.post("/api/users/resolve-username", { username: stripUsernamePrefix(username) });

      setResolved(data.recipient);
    } catch (err) {
      logger.error("Resolving a recipient failed:", err);
      setError(err.message || "That recipient could not be found.");
    } finally {
      setIsResolving(false);
    }
  };

  const copyIssuedCode = async () => {
    if (await copyText(issuedCode)) {
      setCodeCopied(true);
      setTimeout(() => setCodeCopied(false), 2000);
      return;
    }

    logger.error("Could not copy the sharing code.");
    toast({
      title: "Could not copy the code",
      description: "Select the code and copy it manually.",
      variant: "destructive",
    });
  };

  /** The typed uses, made legal. The server enforces the same range. */
  const usesValue = Math.min(MAX_CODE_USES, Math.max(1, Number(maxUses) || 1));

  // A real code of the wrong kind, pasted where a recipient goes. Worth saying
  // out loud rather than answering with "not found": the code is genuine and
  // its holder simply has it in the wrong box.
  const targetMisuse = target === TARGETS.CODE
    ? shareCodeMisuse(userCode, SHARE_CODE_TYPES.USER)
    : null;

  const handleSubmit = async () => {
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
      if (mode === MODES.CODE) {
        const route = codeType === SHARE_CODE_TYPES.GROUP
          ? "/api/shares/group-codes"
          : "/api/shares/comic-codes";

        const data = await api.post(route, {
          comicIds: selectedComicIds.map(Number),
          maxUses: usesValue,
          senderResponsibilityAccepted: true,
          markExplicit,
        });

        // Shown rather than auto-closing so it can be handed over immediately;
        // the owner can also reveal it later from the Sharing page.
        setIssuedCode(data.code);
        // The server's own expiry, never seven-days-from-now arithmetic of our
        // own — the lifetime is an operator setting and this must follow it.
        setIssuedExpiry(data.contentCode?.expiresAt || null);
        toast({
          title: codeType === SHARE_CODE_TYPES.GROUP ? "Group code created" : "Comic code created",
          description: `Anyone you give it to can claim ${selectedComicIds.length === 1 ? "this comic" : "these comics"}, `
            + `${usesValue === 1 ? "once" : `up to ${usesValue} times`}.`,
        });

        try {
          await onShared?.();
        } catch (refreshError) {
          logger.error("Sharing data refresh failed:", refreshError);
        }

        return;
      }

      // Exactly one of the three ways to name a recipient goes on the wire.
      const recipient = target === TARGETS.USERNAME
        ? { username: stripUsernamePrefix(username) }
        : target === TARGETS.CODE
          ? { userCode: parseShareCode(userCode)?.code }
          : { email: recipientEmail.trim() };

      const data = await api.post("/api/shares/invitations/bulk", {
        ...recipient,
        comicIds: selectedComicIds.map(Number),
        senderResponsibilityAccepted: true,
        markExplicit,
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

      // The recipient as the sender knows them.
      const sentTo = target === TARGETS.EMAIL
        ? recipientEmail.trim()
        : (resolved?.label || usernameHandle(stripUsernamePrefix(username)) || "them");

      // The shares exist; the email is queued behind them and a worker sends
      // it. Saying "sent" would claim something this response cannot know, and
      // the notification state on the Sharing page is where delivery is
      // actually reported.
      const notificationFailed = results.some(
        (result) => result.notificationState === "failed"
      );
      const deliveryDescription = created === 1
        ? `An invitation for ${sentTo} is on its way.`
        : `${created} invitations for ${sentTo} are on their way.`;

      toast({
        title: created === 1 ? "Comic shared" : `${created} comics shared`,
        description: notificationFailed
          ? `${sentTo} could not be notified. The ${created === 1 ? "share exists" : "shares exist"} — `
            + `resend the ${created === 1 ? "invitation" : "invitations"} from the Sharing page.`
          : refused.length > 0
            ? `${deliveryDescription} `
              + `${refused.length} ${refused.length === 1 ? "comic was" : "comics were"} left out`
              + `${reason ? `: ${reason}` : "."}`
            : deliveryDescription,
      });

      try {
        await onShared?.();
      } catch (refreshError) {
        logger.error("Sharing data refresh failed:", refreshError);
        toast({
          title: "Comics shared",
          description: "The share was created, but the Sharing list could not refresh. Reload the page to see the latest state.",
          variant: "destructive",
        });
      }

      // The invitations already exist at this point. Always close so a refresh
      // failure cannot encourage the sender to submit the same share again.
      onClose();
    } catch (err) {
      logger.error("Sharing failed:", err);
      setError(err.message || "The comics could not be shared.");
    } finally {
      setIsSending(false);
    }
  };

  const selectionLimitReached = selectedComicIds.length >= MAX_BULK_COMICS;

  const alreadyExplicitCount = selectedComics.filter((comic) => comic.explicitContent).length;

  // Counted from the ids that will be sent, not from the ones the library
  // happened to return. A locked selection can name a comic the picker never
  // fetched — it filters to `canShare` — and a review line reading "2 comics"
  // in front of a request carrying 3 describes somebody else's share.
  const comicCountLabel = `${selectedComicIds.length} ${selectedComicIds.length === 1 ? "comic" : "comics"}`;

  const recipientDescription = mode === MODES.CODE
    ? "whoever you give the code to"
    : target === TARGETS.EMAIL
      ? (recipientEmail.trim() || "the recipient")
      : (resolved?.label || usernameHandle(stripUsernamePrefix(username)) || userCode || "the recipient");

  // Gated on the ids being sent, not on the ones the library returned. A locked
  // selection can name a comic the picker never fetched, and "Select at least
  // one comic above" printed over a request carrying three is the review step
  // describing a different share from the one about to happen.
  const reviewSummary = selectedComicIds.length === 0
    ? "Select at least one comic above."
    : mode === MODES.CODE
      ? `${comicCountLabel} will be put behind ${codeType === SHARE_CODE_TYPES.GROUP ? "one group code" : "a comic code"} `
        + `that ${usesValue === 1 ? "one person" : `up to ${usesValue} people`} can claim. `
        + "You can withdraw the code, or any share it created, at any time."
      : `${comicCountLabel} will be offered to ${recipientDescription} as separate invitations. `
        + "Each must be accepted before it can be read, and you can withdraw any of them later.";

  // An address is its own confirmation: the sender typed the thing the comic is
  // going to, and there is no second identity behind it to check against.
  //
  // A username or a `U-` code is not. Both name an account the sender cannot
  // see, and a code names one they cannot even read — so a typo reaches a real
  // stranger rather than failing. `resolved` is cleared the moment either field
  // changes, so requiring it here means the identity on screen is the identity
  // being shared with, and the sender has seen whose it is.
  const recipientConfirmed = resolved !== null;

  const recipientChosen = mode === MODES.CODE
    || (target === TARGETS.EMAIL && isValidShareEmail(recipientEmail))
    || (target === TARGETS.USERNAME && isValidUsername(username) && recipientConfirmed)
    || (target === TARGETS.CODE && isValidShareCode(userCode, SHARE_CODE_TYPES.USER) && recipientConfirmed);

  const canSubmit = selectedComicIds.length > 0 && responsibilityAccepted && recipientChosen;

  // Said out loud, because an inert Send button with no reason beside it reads
  // as a broken dialog rather than as a step not yet taken.
  const confirmationPending = mode === MODES.DIRECT
    && target !== TARGETS.EMAIL
    && !recipientConfirmed
    && (target === TARGETS.USERNAME
      ? isValidUsername(username)
      : isValidShareCode(userCode, SHARE_CODE_TYPES.USER));

  const submitLabel = mode === MODES.CODE
    ? `Create ${codeType === SHARE_CODE_TYPES.GROUP ? "group" : "comic"} code`
    : selectedComicIds.length === 1 ? "Send invitation" : "Send invitations";

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
            Choose comics you own, then share them with somebody by username, by their U- code or
            by email — or put them behind a code anyone you give it to can redeem. Registered
            users are never searched or listed.
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
                {!lockSelection && visibleSelectable.length > 0 && (
                  <Button type="button" variant="outline" size="sm" onClick={toggleVisible}>
                    {allVisibleSelected ? "Clear shown" : "Select shown"}
                  </Button>
                )}
              </div>

              {/* A caller that has already chosen does not ask again. Reselecting
                  a table selection in a second list is a step that can only go
                  wrong. */}
              {lockSelection ? (
                <ul className="max-h-40 divide-y overflow-y-auto rounded-md border text-sm">
                  {selectedComics.map((comic) => (
                    <li key={comic.id} className="flex items-center gap-2 p-3">
                      <span className="truncate">{comic.title}</span>
                      {comic.explicitContent && (
                        <Badge variant="outline">{EXPLICIT_FLAG_LABEL}</Badge>
                      )}
                    </li>
                  ))}
                </ul>
              ) : (
                <>
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
                </>
              )}
            </section>

            <section className="space-y-3">
              <h3 className="font-semibold">2. Share with</h3>

              <div className="flex flex-wrap gap-1 rounded-md border p-1" role="tablist">
                {[
                  [MODES.DIRECT, "Someone I know"],
                  [MODES.CODE, "Create a code"],
                ].map(([value, label]) => (
                  <Button
                    key={value}
                    type="button"
                    role="tab"
                    size="sm"
                    variant={mode === value ? "default" : "ghost"}
                    aria-selected={mode === value}
                    disabled={isSending || issuedCode !== null}
                    onClick={() => { setMode(value); setError(null); }}
                  >
                    {label}
                  </Button>
                ))}
              </div>

              {mode === MODES.DIRECT && (
                <div className="space-y-3">
                  <p className="text-xs text-muted-foreground">{SHARING_CODE_COPY.recipient}</p>

                  <div className="flex flex-wrap gap-1" role="tablist" aria-label="How to name the recipient">
                    {[
                      [TARGETS.USERNAME, "Username"],
                      [TARGETS.CODE, "U- code"],
                      [TARGETS.EMAIL, "Email address"],
                    ].map(([value, label]) => (
                      <Button
                        key={value}
                        type="button"
                        role="tab"
                        size="sm"
                        variant={target === value ? "secondary" : "ghost"}
                        aria-selected={target === value}
                        disabled={isSending}
                        onClick={() => { setTarget(value); setResolved(null); setError(null); }}
                      >
                        {label}
                      </Button>
                    ))}
                  </div>

                  {recentRecipients.length > 0 && (
                    <div className="space-y-1">
                      <p className="text-xs text-muted-foreground">
                        Recent recipients are only people you have shared with before.
                      </p>
                      <div className="flex flex-wrap gap-2" aria-label="Recent recipients">
                        {recentRecipients.map((recipient) => (
                          <Button
                            key={recipient.username || recipient.email}
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={isSending}
                            onClick={() => {
                              if (recipient.username) {
                                setTarget(TARGETS.USERNAME);
                                setUsername(recipient.username);
                                // Already confirmed, and by the server: this
                                // list is people the owner has shared with
                                // before, and the label beside the button is
                                // the identity a Check would go and fetch.
                                setResolved({
                                  username: recipient.username,
                                  name: recipient.name || "",
                                  label: recipient.label,
                                });
                              } else {
                                setResolved(null);
                                setTarget(TARGETS.EMAIL);
                                setRecipientEmail(recipient.email);
                              }
                            }}
                          >
                            {recipient.label}
                          </Button>
                        ))}
                      </div>
                    </div>
                  )}

                  {target === TARGETS.USERNAME && (
                    <div className="grid gap-2">
                      <Label htmlFor="share-username">Their username</Label>
                      <div className="flex gap-2">
                        <Input
                          id="share-username"
                          autoComplete="off"
                          value={username}
                          onChange={(event) => { setUsername(event.target.value); setResolved(null); }}
                          placeholder="@SilverOtter4821"
                          disabled={isSending}
                        />
                        <Button
                          type="button"
                          variant="outline"
                          onClick={resolveRecipient}
                          disabled={isSending || isResolving || !isValidUsername(username)}
                        >
                          {isResolving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                          Check
                        </Button>
                      </div>
                    </div>
                  )}

                  {target === TARGETS.CODE && (
                    <div className="grid gap-2">
                      <Label htmlFor="share-user-code">Their U- code</Label>
                      <div className="flex gap-2">
                        <Input
                          id="share-user-code"
                          autoComplete="off"
                          value={userCode}
                          onChange={(event) => {
                            setUserCode(formatShareCode(event.target.value));
                            // A changed code is a different person until checked.
                            setResolved(null);
                          }}
                          placeholder="U-XXXX-XXXX-XXXX"
                          className="font-mono tracking-widest"
                          disabled={isSending}
                        />
                        <Button
                          type="button"
                          variant="outline"
                          onClick={resolveRecipient}
                          disabled={
                            isSending || isResolving || !isValidShareCode(userCode, SHARE_CODE_TYPES.USER)
                          }
                        >
                          {isResolving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                          Check
                        </Button>
                      </div>
                      {targetMisuse && (
                        <p className="flex items-center gap-1 text-sm text-destructive">
                          <AlertTriangle className="h-4 w-4" />
                          {targetMisuse}
                        </p>
                      )}
                    </div>
                  )}

                  {target === TARGETS.EMAIL && (
                    <div className="grid gap-2">
                      <Label htmlFor="share-email">Recipient email</Label>
                      <Input
                        id="share-email"
                        type="email"
                        autoComplete="off"
                        value={recipientEmail}
                        onChange={(event) => setRecipientEmail(event.target.value)}
                        placeholder="recipient@example.com"
                        disabled={isSending}
                      />
                      <p className="text-xs text-muted-foreground">
                        Use an address for somebody who has no account here yet.
                      </p>
                    </div>
                  )}

                  {resolved && (
                    <p className="flex items-center gap-1 text-sm text-muted-foreground">
                      <UserCheck className="h-4 w-4" />
                      Sharing with <span className="font-medium">{resolved.label}</span>
                    </p>
                  )}
                </div>
              )}

              {mode === MODES.CODE && (
                <div className="space-y-3">
                  <p className="text-xs text-muted-foreground">
                    {codeType === SHARE_CODE_TYPES.GROUP
                      ? SHARING_CODE_COPY.groupCode
                      : SHARING_CODE_COPY.comicCode}
                  </p>

                  {issuedCode ? (
                    <div className="space-y-2 rounded-md border p-3">
                      <p className="text-sm font-medium">Your code is ready</p>
                      <div className="flex items-center gap-2">
                        <code
                          className="flex-1 rounded bg-muted px-3 py-2 font-mono text-sm tracking-widest"
                          aria-label="Your new sharing code"
                        >
                          {issuedCode}
                        </code>
                        <Button variant="outline" size="sm" onClick={copyIssuedCode} aria-label="Copy the sharing code">
                          {codeCopied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                        </Button>
                      </div>
                      <p className="text-xs text-muted-foreground">
                        Copy it now, or show it again later under Codes you have handed out on the Sharing page.
                        {issuedExpiry && ` It expires ${new Date(issuedExpiry).toLocaleString()}.`}
                      </p>
                    </div>
                  ) : (
                    <div className="grid gap-2">
                      <Label htmlFor="share-code-uses">How many people may use it</Label>
                      <div className="flex items-center gap-3">
                        <Input
                          id="share-code-uses"
                          type="number"
                          min={1}
                          max={MAX_CODE_USES}
                          value={maxUses}
                          onChange={(event) => setMaxUses(
                            event.target.value.replace(/[^0-9]/g, "").slice(0, 2)
                          )}
                          // Corrected when the field is left rather than as it
                          // is typed, so what is sent is always what is shown.
                          onBlur={() => setMaxUses(String(usesValue))}
                          className="w-24"
                          disabled={isSending}
                        />
                        <span className="text-xs text-muted-foreground">
                          1–{MAX_CODE_USES} different people
                        </span>
                      </div>
                      <p className="text-xs text-muted-foreground">
                        {codeType === SHARE_CODE_TYPES.GROUP
                          ? `A ${SHARE_CODE_TYPES.GROUP}- code for ${selectedComicIds.length} comics. Redeeming it costs one use.`
                          : `A ${SHARE_CODE_TYPES.COMIC}- code for exactly one comic.`}
                      </p>
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

              {confirmationPending && (
                <p className="flex items-center gap-1 text-sm text-amber-600 dark:text-amber-500">
                  <AlertTriangle className="h-4 w-4 shrink-0" />
                  Check who this is before sending, so you can see whose library it goes to.
                </p>
              )}

              {selectedComics.length > 0 && !lockSelection && (
                <ul className="max-h-28 list-disc overflow-y-auto pl-5 text-sm">
                  {selectedComics.map((comic) => <li key={comic.id}>{comic.title}</li>)}
                </ul>
              )}

              {/* Reachable from here because the moment somebody decides a comic
                  is adult is the moment they are about to hand it over. It only
                  ever promotes: unticking it does not clear a classification
                  somebody made deliberately. */}
              <div className="space-y-2 rounded-md border p-3">
                <div className="flex items-center gap-2">
                  {/* Disabled on the ids being sent, not on the ones the picker
                      fetched. Otherwise the entry point this control exists for
                      — a table selection somebody is about to hand over — is the
                      one that cannot reach it. */}
                  <Checkbox
                    id="share-mark-explicit"
                    checked={markExplicit}
                    onCheckedChange={(checked) => setMarkExplicit(checked === true)}
                    disabled={isSending || selectedComicIds.length === 0}
                  />
                  <Label htmlFor="share-mark-explicit" className="cursor-pointer text-sm font-medium">
                    These comic(s) contain 18+ / explicit content
                  </Label>
                </div>
                <p className="text-xs text-muted-foreground">
                  {alreadyExplicitCount > 0
                    ? `${alreadyExplicitCount} of these ${alreadyExplicitCount === 1 ? "is" : "are"} already marked 18+. `
                      + "Leaving this unticked never clears an existing mark."
                    : "Ticking this marks the selected comics 18+ on your own library, and recipients "
                      + "must confirm their age and accept before they can read them."}
                </p>
              </div>

              <div className="space-y-3 rounded-md border p-3">
                <p className="text-sm text-muted-foreground">{SHARE_RESPONSIBILITY_NOTICE}</p>
                <div className="flex items-center gap-2">
                  <Checkbox
                    id="share-responsibility"
                    checked={responsibilityAccepted}
                    onCheckedChange={(checked) => setResponsibilityAccepted(checked === true)}
                    disabled={isSending}
                  />
                  <Label htmlFor="share-responsibility" className="cursor-pointer text-sm font-medium">
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
            {issuedCode ? "Done" : "Cancel"}
          </Button>
          {!issuedCode && (
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
