import { useState } from "react";
import { Loader2, Share2 } from "lucide-react";

import { ShareComicPicker } from "@/components/share/ShareComicPicker";
import { ShareRecipientPicker } from "@/components/share/ShareRecipientPicker";
import { ShareReviewStep } from "@/components/share/ShareReviewStep";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { useShareRecipient } from "@/hooks/use-share-recipient";
import { useShareSelection } from "@/hooks/use-share-selection";
import { useShareSubmission } from "@/hooks/use-share-submission";
import { useToast } from "@/hooks/use-toast";
import { SHARE_CODE_TYPES } from "@/lib/sharing";
import { MAX_BULK_COMICS, MODES, TARGETS } from "@/lib/sharing-workflow";

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
  /**
   * The folder these comics came from, as
   * `{ folderId, folderName, comicIds, unshareableCount }`, when the sender
   * pointed at one instead of picking comics.
   *
   * It changes three things and nothing else: the ceiling rises, the request
   * names the folder so the server resolves it fresh, and the wording says what
   * the sender actually did.
   */
  folder = null,
  /** Hide the picker: the caller has already chosen, and re-picking is a step. */
  lockSelection = false,
  initialMode = MODES.DIRECT,
  onShared,
}) {
  const { toast } = useToast();
  // A group code carries comic ids rather than a folder, so it tops out at the
  // ordinary bulk ceiling however the comics were chosen. A folder share past
  // that ceiling therefore has only one way to go, and the fork is withdrawn
  // rather than offered as a button whose only outcome is a refusal.
  const codeAvailable = initialComicIds.length <= MAX_BULK_COMICS;
  const [requestedMode, setMode] = useState(initialMode);
  const mode = codeAvailable ? requestedMode : MODES.DIRECT;
  const [markExplicit, setMarkExplicit] = useState(false);
  const [responsibilityAccepted, setResponsibilityAccepted] = useState(false);
  const [error, setError] = useState(null);

  const recipient = useShareRecipient({
    initialRecipient, initialUsername, initialUserCode, initialResolved, mode, onError: setError,
  });
  const selection = useShareSelection({
    isOpen, sharedByMe, initialComicIds, folder, mode,
    target: recipient.target, recipientEmail: recipient.recipientEmail, onError: setError,
  });
  const submission = useShareSubmission({
    mode, codeType: selection.codeType, selectedComicIds: selection.selectedComicIds, folder,
    recipientPayload: recipient.payload, recipientLabel: recipient.label,
    isDirectEmail: recipient.target === TARGETS.EMAIL,
    responsibilityAccepted, markExplicit, toast, onShared, onClose, onError: setError,
  });

  const summary = shareSummary({ mode, selection, recipient, folder, usesValue: submission.usesValue });
  const canSubmit = selection.selectedComicIds.length > 0 && responsibilityAccepted && recipient.recipientChosen;

  return (
    <Dialog open={isOpen} onOpenChange={(open) => { if (!open && !submission.isSending) onClose(); }}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-[720px]">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Share2 className="h-5 w-5" />
            {folder ? `Share “${folder.folderName}”` : "Share comics"}
          </DialogTitle>
          <DialogDescription>
            {folder
              ? "Everything you own in this folder and its subfolders, offered as one act. "
                + "Each comic is still accepted and withdrawn on its own. Share it with somebody by "
                + "username, by their U- code or by email; registered users are never searched or listed."
              : "Choose comics you own, then share them with somebody by username, by their U- code or "
                + "by email — or put them behind a code anyone you give it to can redeem. Registered "
                + "users are never searched or listed."}
          </DialogDescription>
        </DialogHeader>

        {selection.isLoading ? (
          <div className="flex items-center gap-2 py-12 text-muted-foreground">
            <Loader2 className="h-5 w-5 animate-spin" />
            Loading your comics…
          </div>
        ) : (
          <div className="space-y-6 py-2">
            <ShareComicPicker {...selection} lockSelection={lockSelection} folder={folder} />
            <ShareRecipientPicker
              {...recipient}
              {...submission}
              mode={mode}
              codeAvailable={codeAvailable}
              setMode={(value) => { setMode(value); setError(null); }}
              recentRecipients={selection.recentRecipients}
              selectedComicIds={selection.selectedComicIds}
              codeType={selection.codeType}
              setError={setError}
            />
            <ShareReviewStep
              reviewSummary={summary}
              confirmationPending={recipient.confirmationPending}
              selectedComics={selection.selectedComics}
              selectedComicIds={selection.selectedComicIds}
              lockSelection={lockSelection}
              markExplicit={markExplicit}
              setMarkExplicit={setMarkExplicit}
              alreadyExplicitCount={selection.alreadyExplicitCount}
              responsibilityAccepted={responsibilityAccepted}
              setResponsibilityAccepted={setResponsibilityAccepted}
              isSending={submission.isSending}
            />

            {error && <p className="text-sm text-destructive">{error}</p>}
          </div>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={submission.isSending}>
            {submission.issuedCode ? "Done" : "Cancel"}
          </Button>
          {!submission.issuedCode && (
            <Button onClick={submission.submit} disabled={selection.isLoading || submission.isSending || !canSubmit}>
              {submission.isSending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              {submitLabel({ mode, codeType: selection.codeType, count: selection.selectedComicIds.length })}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

const submitLabel = ({ mode, codeType, count }) => mode === MODES.CODE
  ? `Create ${codeType === SHARE_CODE_TYPES.GROUP ? "group" : "comic"} code`
  : count === 1 ? "Send invitation" : "Send invitations";

/**
 * What is about to happen, in one sentence.
 *
 * Counted from the ids that will be sent, not from the ones the library
 * happened to return: a locked selection can name a comic the picker never
 * fetched, and "Select at least one comic above" printed over a request
 * carrying three describes somebody else's share.
 */
function shareSummary({ mode, selection, recipient, folder, usesValue }) {
  const { selectedComicIds, codeType } = selection;
  if (selectedComicIds.length === 0) {
    return folder ? "There is nothing here you can share." : "Select at least one comic above.";
  }

  const comics = `${selectedComicIds.length} ${selectedComicIds.length === 1 ? "comic" : "comics"}`
    + (folder ? ` from “${folder.folderName}”` : "");
  if (mode === MODES.CODE) {
    return `${comics} will be put behind ${codeType === SHARE_CODE_TYPES.GROUP ? "one group code" : "a comic code"} `
      + `that ${usesValue === 1 ? "one person" : `up to ${usesValue} people`} can claim. `
      + "You can withdraw the code, or any share it created, at any time.";
  }

  const to = mode === MODES.CODE ? "whoever you give the code to" : recipient.label;
  return `${comics} will be offered to ${to} as separate invitations. `
    + "Each must be accepted before it can be read, and you can withdraw any of them later.";
}
