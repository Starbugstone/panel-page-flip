import { useState, useEffect } from "react";
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
import { Check, Copy, Loader2 } from "lucide-react";
import { useToast } from "@/hooks/use-toast";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { isValidShareEmail } from "@/lib/sharing";

export function ShareComicModal({ isOpen, onClose, comicId, comicTitle, onShared }) {
  const [recipientEmail, setRecipientEmail] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState(null);
  const [invitationUrl, setInvitationUrl] = useState(null);
  const [copied, setCopied] = useState(false);
  const { toast } = useToast();

  useEffect(() => {
    if (!isOpen) {
      // Allow the close animation to finish before wiping the contents.
      const timeout = setTimeout(() => {
        setRecipientEmail("");
        setIsLoading(false);
        setError(null);
        setInvitationUrl(null);
        setCopied(false);
      }, 300);

      return () => clearTimeout(timeout);
    }

    setError(null);
    setInvitationUrl(null);
    setCopied(false);
    return undefined;
  }, [isOpen, comicId]);

  const handleShare = async () => {
    if (!isValidShareEmail(recipientEmail)) {
      setError("Please enter a valid email address.");
      return;
    }

    setIsLoading(true);
    setError(null);

    try {
      const data = await api.post(`/api/shares/comics/${comicId}/invitations`, {
        email: recipientEmail.trim(),
      });

      // Shown rather than auto-closing: this is the only moment the link
      // exists in a readable form, because the server keeps only its hash.
      setInvitationUrl(data.invitationUrl || null);
      toast({
        title: "Invitation sent",
        description: `${recipientEmail.trim()} has been invited to read “${comicTitle}”.`,
      });
      onShared?.();
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
              disabled={isLoading || !isValidShareEmail(recipientEmail)}
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
