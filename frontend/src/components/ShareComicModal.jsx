import { useState } from "react";
import { Link } from "react-router-dom";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";
import { Check, Copy, Loader2 } from "lucide-react";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import {
  SHARE_RESPONSIBILITY_ACK_LABEL,
  SHARE_RESPONSIBILITY_NOTICE,
  buildInvitationRequest,
  canSendInvitation,
} from "@/lib/sharing";

/**
 * Every opening gets a new form.
 *
 * The contents used to be wiped by an effect 300ms after closing, so the dialog
 * could animate out with them still on screen, and that timer was cancelled if
 * it reopened first. The responsibility acknowledgement was therefore one
 * cancelled timer away from surviving into the next share — and it is an
 * agreement about this comic going to this person, so it has to be unticked for
 * every share, including a second one opened straight after the first.
 *
 * Remounting per opening makes that structural rather than something a timer
 * has to get right. The cost is that the dialog fades out already empty.
 */
export function ShareComicModal({ isOpen, comicId, ...props }) {
  return (
    <ShareComicModalForm
      key={isOpen ? `open-${comicId}` : "closed"}
      isOpen={isOpen}
      comicId={comicId}
      {...props}
    />
  );
}

function ShareComicModalForm({ isOpen, onClose, comicId, comicTitle, onShared }) {
  const [recipientEmail, setRecipientEmail] = useState("");
  const [responsibilityAccepted, setResponsibilityAccepted] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState(null);
  const [invitationUrl, setInvitationUrl] = useState(null);
  const [copied, setCopied] = useState(false);
  const { toast } = useToast();

  const handleShare = async () => {
    if (!canSendInvitation({ email: recipientEmail, responsibilityAccepted })) {
      setError(
        responsibilityAccepted
          ? "Please enter a valid email address."
          : "Please confirm that you understand you are responsible for what you share."
      );
      return;
    }

    setIsLoading(true);
    setError(null);

    try {
      const data = await api.post(
        `/api/shares/comics/${comicId}/invitations`,
        buildInvitationRequest({ email: recipientEmail, responsibilityAccepted })
      );

      // Shown rather than auto-closing: this is the only moment the link
      // exists in a readable form, because the server keeps only its hash.
      setInvitationUrl(data.invitationUrl || null);
      toast({
        title: "Invitation sent",
        description: `${recipientEmail.trim()} has been invited to read “${comicTitle}”.`,
      });

      // In its own try, and awaited rather than left running: the invitation
      // exists once the POST resolves, so a refresh that fails is a stale list
      // to warn about — never a reason to tell the sender their share failed,
      // and never an unhandled rejection nobody sees.
      try {
        await onShared?.();
      } catch (refreshError) {
        logger.error("Sharing data refresh failed:", refreshError);
        toast({
          title: "Invitation sent",
          description: "The invitation was sent, but the Sharing list could not refresh. "
            + "Reload the page to see the latest state.",
          variant: "destructive",
        });
      }
    } catch (err) {
      const message = err.message || "The invitation could not be sent.";
      setError(message);
      toast({ title: "Could not share comic", description: message, variant: "destructive" });
    } finally {
      setIsLoading(false);
    }
  };

  const copyLink = async () => {
    try {
      await navigator.clipboard.writeText(invitationUrl);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch (err) {
      // Clipboard access can be refused; the link is on screen and selectable,
      // so say so rather than pretending the copy worked.
      logger.error("Could not copy the invitation link:", err);
      toast({
        title: "Could not copy the link",
        description: "Select the link and copy it manually.",
        variant: "destructive",
      });
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="sm:max-w-[480px]">
        <DialogHeader>
          <DialogTitle>Share “{comicTitle}”</DialogTitle>
          <DialogDescription>
            You keep the comic and its file. The recipient is given permission to read it, and you
            can withdraw that at any time from the Sharing page. We email them an invitation on your
            behalf, using your address as the reply-to.{" "}
            <Link className="underline" to="/privacy">Privacy information</Link>
          </DialogDescription>
        </DialogHeader>

        {invitationUrl ? (
          <div className="space-y-3 py-4">
            <p className="text-sm">
              The invitation is on its way. You can also pass this link on yourself:
            </p>
            <div className="flex gap-2">
              <Input readOnly value={invitationUrl} aria-label="Invitation link" />
              <Button variant="outline" size="icon" onClick={copyLink} aria-label="Copy invitation link">
                {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
              </Button>
            </div>
            <p className="text-xs text-muted-foreground">
              This link is shown once. If it is lost, resend the invitation from the Sharing page to
              get a new one — that also invalidates this link.
            </p>
          </div>
        ) : (
          <div className="grid gap-4 py-4">
            <div className="grid gap-2">
              <Label htmlFor="share-email">Recipient email</Label>
              <Input
                id="share-email"
                type="email"
                value={recipientEmail}
                onChange={(e) => setRecipientEmail(e.target.value)}
                placeholder="recipient@example.com"
                disabled={isLoading}
              />
            </div>

            {/* Next to the address and the Send button rather than in the
                dialog's preamble: this has to be read at the moment of sending,
                not skimmed on the way in. */}
            <div className="space-y-3 rounded-md border p-3">
              <p className="text-sm text-muted-foreground">{SHARE_RESPONSIBILITY_NOTICE}</p>
              <div className="flex items-center gap-2">
                <Checkbox
                  id="share-responsibility"
                  checked={responsibilityAccepted}
                  onCheckedChange={(checked) => setResponsibilityAccepted(checked === true)}
                  disabled={isLoading}
                />
                <Label htmlFor="share-responsibility" className="cursor-pointer text-sm font-medium">
                  {SHARE_RESPONSIBILITY_ACK_LABEL}
                </Label>
              </div>
            </div>

            {error && <p className="text-sm text-destructive">{error}</p>}
          </div>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={isLoading}>
            {invitationUrl ? "Done" : "Cancel"}
          </Button>
          {!invitationUrl && (
            <Button
              onClick={handleShare}
              disabled={
                isLoading || !canSendInvitation({ email: recipientEmail, responsibilityAccepted })
              }
            >
              {isLoading ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Sending…
                </>
              ) : (
                "Send invitation"
              )}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
