import { useState } from "react";

import { api } from "@/lib/api";
import { copyText } from "@/lib/clipboard";
import { logger } from "@/lib/logger";
import { SHARE_CODE_TYPES } from "@/lib/sharing";
import { MAX_CODE_USES, MODES } from "@/lib/sharing-workflow";

/** What the server did, said in the sender's terms rather than the API's. */
function describeInvitations({ created, refused, reason, sentTo, notificationFailed }) {
  const delivery = created === 1
    ? `An invitation for ${sentTo} is on its way.`
    : `${created} invitations for ${sentTo} are on their way.`;

  if (notificationFailed) {
    return `${sentTo} could not be notified. The ${created === 1 ? "share exists" : "shares exist"} — `
      + `resend the ${created === 1 ? "invitation" : "invitations"} from the Sharing page.`;
  }
  if (refused.length > 0) {
    return `${delivery} ${refused.length} ${refused.length === 1 ? "comic was" : "comics were"} left out`
      + `${reason ? `: ${reason}` : "."}`;
  }
  return delivery;
}

/**
 * Turning a chosen selection and a chosen recipient into shares.
 *
 * A code is shown rather than auto-closing, so it can be handed over
 * immediately. Invitations always close: they already exist by the time this
 * returns, and leaving the dialog open after a refresh failure would invite
 * somebody to send the same share twice.
 */
export function useShareSubmission({
  mode, codeType, selectedComicIds, folder, recipientPayload, recipientLabel, isDirectEmail,
  responsibilityAccepted, markExplicit, toast, onShared, onClose, onError,
}) {
  // Held as text, so the field can be emptied and retyped. Clamping every
  // keystroke turns clearing "1" and typing "4" into 14.
  const [maxUses, setMaxUses] = useState("1");
  const [issuedCode, setIssuedCode] = useState(null);
  const [issuedExpiry, setIssuedExpiry] = useState(null);
  const [codeCopied, setCodeCopied] = useState(false);
  const [isSending, setIsSending] = useState(false);

  /** The typed uses, made legal. The server enforces the same range. */
  const usesValue = Math.min(MAX_CODE_USES, Math.max(1, Number(maxUses) || 1));

  const copyIssuedCode = async () => {
    if (await copyText(issuedCode)) {
      setCodeCopied(true);
      setTimeout(() => setCodeCopied(false), 2000);
      return;
    }

    logger.error("Could not copy the sharing code.");
    toast({ title: "Could not copy the code", description: "Select the code and copy it manually.", variant: "destructive" });
  };

  const refresh = async (failureToast) => {
    try {
      await onShared?.();
    } catch (refreshError) {
      logger.error("Sharing data refresh failed:", refreshError);
      if (failureToast) toast(failureToast);
    }
  };

  const createCode = async () => {
    const route = codeType === SHARE_CODE_TYPES.GROUP ? "/api/shares/group-codes" : "/api/shares/comic-codes";
    const data = await api.post(route, {
      comicIds: selectedComicIds.map(Number),
      maxUses: usesValue,
      senderResponsibilityAccepted: true,
      markExplicit,
    });

    setIssuedCode(data.code);
    // The server's own expiry, never seven-days-from-now arithmetic of our own —
    // the lifetime is an operator setting and this must follow it.
    setIssuedExpiry(data.contentCode?.expiresAt || null);
    toast({
      title: codeType === SHARE_CODE_TYPES.GROUP ? "Group code created" : "Comic code created",
      description: `Anyone you give it to can claim ${selectedComicIds.length === 1 ? "this comic" : "these comics"}, `
        + `${usesValue === 1 ? "once" : `up to ${usesValue} times`}.`,
    });
    await refresh();
  };

  const sendInvitations = async () => {
    const data = await api.post("/api/shares/invitations/bulk", {
      ...recipientPayload(),
      // A folder share names the folder and lets the server walk it again. The
      // ids this dialog is showing came from a preview taken when it opened,
      // and sending those instead would share a folder as it was rather than as
      // it is — including a comic filed out of it a moment ago.
      ...(folder ? { folderId: folder.folderId } : { comicIds: selectedComicIds.map(Number) }),
      senderResponsibilityAccepted: true,
      markExplicit,
    });

    const results = Array.isArray(data.results) ? data.results : [];
    const created = Number(data.created) || results.filter((r) => r.status === "created").length;
    // Everything the server did not create, with the first reason it gave.
    // Reporting these as "skipped" would tell somebody whose comic was refused
    // that their share went through.
    const refused = results.filter((result) => result.status !== "created");
    const reason = refused.find((result) => result.message)?.message;

    if (created === 0) {
      onError(reason || "No new invitations were created.");
      return;
    }

    // The shares exist; the email is queued behind them and a worker sends it.
    // Saying "sent" would claim something this response cannot know.
    toast({
      title: created === 1 ? "Comic shared" : `${created} comics shared`,
      description: describeInvitations({
        created, refused, reason,
        sentTo: isDirectEmail ? recipientLabel : (recipientLabel || "them"),
        notificationFailed: results.some((result) => result.notificationState === "failed"),
      }),
    });

    await refresh({
      title: "Comics shared",
      description: "The share was created, but the Sharing list could not refresh. Reload the page to see the latest state.",
      variant: "destructive",
    });
    onClose();
  };

  const submit = async () => {
    if (selectedComicIds.length === 0) return onError("Select at least one comic to share.");
    if (!responsibilityAccepted) {
      return onError("Please confirm that you understand you are responsible for what you share.");
    }

    setIsSending(true);
    onError(null);

    try {
      await (mode === MODES.CODE ? createCode() : sendInvitations());
    } catch (err) {
      logger.error("Sharing failed:", err);
      onError(err.message || "The comics could not be shared.");
    } finally {
      setIsSending(false);
    }
  };

  return { maxUses, setMaxUses, usesValue, issuedCode, issuedExpiry, codeCopied, copyIssuedCode, isSending, submit };
}
