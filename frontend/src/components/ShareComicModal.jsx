import { useState, useEffect } from "react";
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
import { Loader2 } from "lucide-react";
import { useToast } from "@/hooks/use-toast"; // Using useToast for consistency

export function ShareComicModal({ isOpen, onClose, comicId, comicTitle, apiBaseUrl = "/api" }) {
  const [recipientEmail, setRecipientEmail] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState(null);
  const [successMessage, setSuccessMessage] = useState(null);
  const { toast } = useToast();

  useEffect(() => {
    if (!isOpen) {
      // Reset state when modal is closed
      setTimeout(() => { // Allow animation to finish before reset
        setRecipientEmail("");
        setIsLoading(false);
        setError(null);
        setSuccessMessage(null);
      }, 300);
    } else {
      // Reset messages if comicId changes while modal is open for another share
      setError(null);
      setSuccessMessage(null);
    }
  }, [isOpen, comicId]);

  const isValidEmail = (email) => {
    // More comprehensive email validation
    // This regex checks for most common email format requirements
    return /^[a-zA-Z0-9.!#$%&'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/.test(email) && 
      email.length <= 254; // RFC 5321 SMTP limit
  };

  const handleShare = async () => {
    if (!isValidEmail(recipientEmail)) {
      setError("Please enter a valid email address.");
      return;
    }

    setIsLoading(true);
    setError(null);
    setSuccessMessage(null);

    try {
      const response = await fetch(`${apiBaseUrl}/share/comic/${comicId}`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          // Assuming authentication is handled by cookies or a global fetch wrapper
        },
        body: JSON.stringify({ email: recipientEmail }),
      });

      const data = await response.json();

      if (!response.ok) {
        // Handle specific error cases with user-friendly messages
        if (response.status === 429) {
          throw new Error('You have sent too many share invitations recently. Please try again later.');
        } else if (response.status === 401) {
          throw new Error('You need to be logged in to share comics.');
        } else if (response.status === 403) {
          throw new Error('You do not have permission to share this comic.');
        } else if (response.status === 404) {
          throw new Error('Comic not found.');
        } else if (response.status === 409) {
          throw new Error('This comic has already been shared with this email address.');
        } else {
          // Generic error message that doesn't expose implementation details
          throw new Error(data.error || 'An error occurred while sharing the comic. Please try again.');
        }
      }

      setSuccessMessage(`Invitation sent to ${recipientEmail}!`);
      toast({
        title: "Comic Shared",
        description: `Invitation successfully sent to ${recipientEmail}.`,
      });
      // Optionally close after a delay
      setTimeout(() => {
        onClose();
      }, 2000); 

    } catch (err) {
      const errorMessage = err.message || "An unexpected error occurred.";
      setError(errorMessage);
      toast({
        title: "Error Sharing Comic",
        description: errorMessage,
        variant: "destructive",
      });
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="sm:max-w-[425px]">
        <DialogHeader>
          <DialogTitle>Share '{comicTitle}'</DialogTitle>
          <DialogDescription>
            Enter the email address of the person you want to share this comic with. 
            They will receive an invitation to view it.
          </DialogDescription>
        </DialogHeader>
        <div className="grid gap-4 py-4">
          <div className="grid grid-cols-4 items-center gap-4">
            <Label htmlFor="email" className="text-right">
              Email
            </Label>
            <Input
              id="email"
              type="email"
              value={recipientEmail}
              onChange={(e) => setRecipientEmail(e.target.value)}
              placeholder="recipient@example.com"
              className="col-span-3"
              disabled={isLoading}
            />
          </div>
          {error && (
            <p className="text-sm text-red-500 col-span-4 text-center px-1">{error}</p>
          )}
          {successMessage && (
            <p className="text-sm text-green-500 col-span-4 text-center px-1">{successMessage}</p>
          )}
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={isLoading}>
            Cancel
          </Button>
          <Button 
            onClick={handleShare} 
            disabled={isLoading || !recipientEmail || !isValidEmail(recipientEmail)}
          >
            {isLoading ? (
              <>
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                Sending...
              </>
            ) : (
              "Send Share Invitation"
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
