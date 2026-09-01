import { useState } from "react";
import { Download, ShieldAlert, Trash2 } from "lucide-react";
import { useNavigate } from "react-router-dom";

import { api } from "@/lib/api";
import { accountDeletionReauthenticationUrl } from "@/lib/account-deletion";
import { useAuth } from "@/hooks/use-auth";
import { useToast } from "@/hooks/use-toast";
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from "@/components/ui/alert-dialog";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

export function PrivacyAccountDataCard({ oauthConnections, initiallyOpen = false }) {
  const { toast } = useToast();
  const { logout, user } = useAuth();
  const navigate = useNavigate();
  const [dialogOpen, setDialogOpen] = useState(initiallyOpen);
  const [confirmation, setConfirmation] = useState("");
  const [currentPassword, setCurrentPassword] = useState("");
  const [deleting, setDeleting] = useState(false);

  const setDeletionDialogOpen = (open) => {
    setDialogOpen(open);
    if (!open) {
      setConfirmation("");
      setCurrentPassword("");
    }
  };

  const downloadPersonalData = async () => {
    try {
      const blob = await api.blob("/api/privacy/export");
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = `panel-page-flip-data-${new Date().toISOString().slice(0, 10)}.json`;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      // Chromium consumes the object URL after click() returns; revoking it in
      // the same task can cancel the download.
      window.setTimeout(() => URL.revokeObjectURL(url), 0);
      toast({ title: "Data export downloaded" });
    } catch (error) {
      toast({ title: "Could not export your data", description: error.message, variant: "destructive" });
    }
  };

  const deleteAccount = async () => {
    setDeleting(true);
    try {
      await api.delete("/api/privacy/account", {
        body: {
          confirmation,
          ...(user?.hasPassword === false ? {} : { currentPassword }),
        },
      });
      await logout();
      navigate("/", { replace: true });
    } catch (error) {
      setConfirmation("");
      setCurrentPassword("");
      toast({ title: "Could not delete your account", description: error.message, variant: "destructive" });
    } finally {
      setDeleting(false);
    }
  };

  const beginAccountDeletion = () => {
    if (user?.hasPassword !== false) {
      setDeletionDialogOpen(true);
      return;
    }

    const connection = oauthConnections.find((provider) => provider.connected && provider.enabled);
    if (!connection) {
      toast({
        title: "Provider reauthentication unavailable",
        description: "Ask the site operator to enable your connected provider, or use password reset to add a password first.",
        variant: "destructive",
      });
      return;
    }

    window.location.assign(accountDeletionReauthenticationUrl(connection.provider));
  };

  return (
    <>
      <Card className="mt-6">
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><ShieldAlert className="h-5 w-5" /> Privacy and account data</CardTitle>
          <CardDescription>Download a machine-readable copy of your account data or permanently delete your account.</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4 sm:flex-row">
          <Button variant="outline" onClick={downloadPersonalData}>
            <Download className="mr-2 h-4 w-4" /> Download my data
          </Button>
          <Button variant="destructive" onClick={beginAccountDeletion}>
            <Trash2 className="mr-2 h-4 w-4" /> Delete my account
          </Button>
        </CardContent>
      </Card>

      <AccountDeletionDialog
        open={dialogOpen}
        user={user}
        confirmation={confirmation}
        currentPassword={currentPassword}
        deleting={deleting}
        onOpenChange={setDeletionDialogOpen}
        onConfirmationChange={setConfirmation}
        onPasswordChange={setCurrentPassword}
        onDelete={deleteAccount}
      />
    </>
  );
}

function AccountDeletionDialog({
  open,
  user,
  confirmation,
  currentPassword,
  deleting,
  onOpenChange,
  onConfirmationChange,
  onPasswordChange,
  onDelete,
}) {
  const passwordRequired = user?.hasPassword !== false;

  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Permanently delete your account?</AlertDialogTitle>
          <AlertDialogDescription>
            This deletes your comics, reading history, personal tags, sharing relationships,
            codes, and invitations, Dropbox connection, and account. This cannot be undone.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <div className="space-y-4">
          {passwordRequired ? (
            <div className="space-y-2">
              <Label htmlFor="delete-current-password">Current password</Label>
              <Input
                id="delete-current-password"
                type="password"
                autoComplete="current-password"
                value={currentPassword}
                onChange={(event) => onPasswordChange(event.target.value)}
              />
            </div>
          ) : (
            <p className="rounded-md border p-3 text-sm text-muted-foreground">
              Your connected provider has recently confirmed your identity. This confirmation expires after five minutes.
            </p>
          )}
          <div className="space-y-2">
            <Label htmlFor="delete-confirmation">Type DELETE to confirm</Label>
            <Input
              id="delete-confirmation"
              value={confirmation}
              onChange={(event) => onConfirmationChange(event.target.value)}
            />
          </div>
        </div>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={deleting}>Cancel</AlertDialogCancel>
          <AlertDialogAction
            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            disabled={confirmation !== "DELETE" || (passwordRequired && currentPassword === "") || deleting}
            onClick={(event) => {
              event.preventDefault();
              onDelete();
            }}
          >
            {deleting ? "Deleting…" : "Delete account permanently"}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
