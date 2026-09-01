import { useEffect, useState } from "react";
import { Download, Edit, FileArchive, Plus, ShieldAlert, Tags, Trash2 } from "lucide-react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { useToast } from "@/hooks/use-toast";
import { useTags } from "@/hooks/use-tags";
import { AccountSettingsCard } from "@/components/AccountSettingsCard";
import { SignInMethodsCard } from "@/components/SignInMethodsCard";
import { TagBadge } from "@/components/TagBadge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { UserMetadataCredentials } from "@/components/UserMetadataCredentials";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from "@/components/ui/alert-dialog";
import { useAuth } from "@/hooks/use-auth";
import { CONVERSION_TOOLS, CONVERSION_TOOLS_VERSION } from "@/lib/conversion-tools";

export default function UserSettings() {
  const { toast } = useToast();
  const { logout, user } = useAuth();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const { fetchTags } = useTags();
  const [tags, setTags] = useState([]);
  const [loading, setLoading] = useState(true);
  const [dialogMode, setDialogMode] = useState(null);
  const [activeTag, setActiveTag] = useState(null);
  const [tagName, setTagName] = useState("");
  const [tagToDelete, setTagToDelete] = useState(null);
  const [saving, setSaving] = useState(false);
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(() => searchParams.has("oauth_reauthenticated"));
  const [deleteConfirmation, setDeleteConfirmation] = useState("");
  const [currentPassword, setCurrentPassword] = useState("");
  const [deletingAccount, setDeletingAccount] = useState(false);
  const [oauthConnections, setOauthConnections] = useState([]);

  const oauthReauthenticated = searchParams.get("oauth_reauthenticated");
  const oauthConnected = searchParams.get("oauth_connected");
  const oauthError = searchParams.get("oauth_error");

  useEffect(() => {
    if (!oauthReauthenticated && !oauthConnected && !oauthError) return;

    if (oauthReauthenticated) {
      toast({ title: "Identity confirmed", description: `Continue to delete the account within five minutes.` });
    } else if (oauthConnected) {
      toast({ title: "Sign-in method connected", description: `${oauthConnected === "google" ? "Google" : oauthConnected} can now sign in to this account.` });
    } else {
      const messages = {
        identity_in_use: "That provider account is already connected to another user.",
        wrong_account: "The provider account did not match this user.",
        sign_in_required: "Your session expired before the provider could be connected.",
        invalid_state: "The provider response could not be verified. Please try again.",
        cancelled: "The provider connection was cancelled or not completed.",
        expired: "The provider connection expired. Please try again.",
      };
      toast({
        title: "Provider connection unsuccessful",
        description: messages[oauthError] || "The provider connection could not be completed.",
        variant: "destructive",
      });
    }

    const next = new URLSearchParams(searchParams);
    next.delete("oauth_reauthenticated");
    next.delete("oauth_connected");
    next.delete("oauth_error");
    next.delete("provider");
    setSearchParams(next, { replace: true });
  }, [oauthConnected, oauthError, oauthReauthenticated, searchParams, setSearchParams, toast]);

  const setAccountDeletionDialogOpen = (open) => {
    setDeleteDialogOpen(open);
    if (!open) {
      setDeleteConfirmation("");
      setCurrentPassword("");
    }
  };

  // Fetched once. Adding, renaming and deleting all update the list from the
  // response they already get back, so there is nothing here to re-run.
  useEffect(() => {
    let ignore = false;
    api.get("/api/tags")
      .then((data) => {
        if (!ignore) setTags((data.tags || []).filter((tag) => !tag.isGlobal));
      })
      .catch((error) => {
        if (ignore) return;
        logger.error("Failed to load personal tags:", error);
        toast({ title: "Could not load tags", description: error.message, variant: "destructive" });
      })
      .finally(() => { if (!ignore) setLoading(false); });

    return () => { ignore = true; };
  }, [toast]);

  const openDialog = (mode, tag = null) => {
    setDialogMode(mode);
    setActiveTag(tag);
    setTagName(tag?.name || "");
  };

  const closeDialog = () => {
    setDialogMode(null);
    setActiveTag(null);
    setTagName("");
  };

  const saveTag = async () => {
    const name = tagName.trim();
    if (!name) return;
    if (tags.some((tag) => tag.id !== activeTag?.id && tag.name.toLowerCase() === name.toLowerCase())) {
      toast({ title: "Tag already exists", description: "Choose a different name.", variant: "destructive" });
      return;
    }

    setSaving(true);
    try {
      if (dialogMode === "create") {
        const data = await api.post("/api/tags", { name });
        setTags((current) => [...current, data.tag].sort((a, b) => a.name.localeCompare(b.name)));
      } else {
        const data = await api.put(`/api/tags/${activeTag.id}`, { name });
        setTags((current) => current.map((tag) => tag.id === activeTag.id ? data.tag : tag));
      }
      await fetchTags(true);
      toast({ title: dialogMode === "create" ? "Tag created" : "Tag updated" });
      closeDialog();
    } catch (error) {
      toast({ title: "Could not save tag", description: error.message, variant: "destructive" });
    } finally {
      setSaving(false);
    }
  };

  const deleteTag = async () => {
    if (!tagToDelete) return;
    try {
      await api.delete(`/api/tags/${tagToDelete.id}`);
      setTags((current) => current.filter((tag) => tag.id !== tagToDelete.id));
      await fetchTags(true);
      toast({ title: "Tag deleted" });
    } catch (error) {
      toast({ title: "Could not delete tag", description: error.message, variant: "destructive" });
    } finally {
      setTagToDelete(null);
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
      // Chromium can consume the object URL after click() returns. Revoking it
      // in the same task intermittently cancels the download.
      window.setTimeout(() => URL.revokeObjectURL(url), 0);
      toast({ title: "Data export downloaded" });
    } catch (error) {
      toast({ title: "Could not export your data", description: error.message, variant: "destructive" });
    }
  };

  const deleteAccount = async () => {
    setDeletingAccount(true);
    try {
      await api.delete("/api/privacy/account", {
        body: {
          confirmation: deleteConfirmation,
          ...(user?.hasPassword === false ? {} : { currentPassword }),
        },
      });
      await logout();
      navigate("/", { replace: true });
    } catch (error) {
      setDeleteConfirmation("");
      setCurrentPassword("");
      toast({ title: "Could not delete your account", description: error.message, variant: "destructive" });
    } finally {
      setDeletingAccount(false);
    }
  };

  const beginAccountDeletion = () => {
    if (user?.hasPassword !== false) {
      setAccountDeletionDialogOpen(true);
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

    window.location.assign(`/api/auth/oauth/${connection.provider}/start?purpose=delete-account&redirect=${encodeURIComponent("/settings")}`);
  };

  return (
    <div className="container mx-auto max-w-5xl px-4 py-8">
      <div className="mb-6">
        <h1 className="text-3xl font-comic">Settings</h1>
        <p className="mt-1 text-muted-foreground">Manage your account, your storage, and the tags that belong only to you.</p>
      </div>

      <div className="mb-6">
        <AccountSettingsCard />
      </div>

      <SignInMethodsCard onConnectionsChange={setOauthConnections} />

      <Card>
        <CardHeader className="flex-row items-start justify-between gap-4 space-y-0">
          <div>
            <CardTitle className="flex items-center gap-2"><Tags className="h-5 w-5" /> Personal tags</CardTitle>
            <CardDescription className="mt-2">Global tags are managed by an administrator and are not editable here.</CardDescription>
          </div>
          <Button onClick={() => openDialog("create")}><Plus className="mr-2 h-4 w-4" /> Add tag</Button>
        </CardHeader>
        <CardContent>
          {loading ? (
            <p className="py-8 text-center text-muted-foreground">Loading tags…</p>
          ) : tags.length === 0 ? (
            <div className="rounded-md border border-dashed py-10 text-center">
              <p className="font-medium">No personal tags yet</p>
              <p className="mt-1 text-sm text-muted-foreground">Create one here or while editing a comic.</p>
            </div>
          ) : (
            <div className="overflow-hidden rounded-md border">
              <Table>
                <TableHeader><TableRow><TableHead>Name</TableHead><TableHead>Comics</TableHead><TableHead className="text-right">Actions</TableHead></TableRow></TableHeader>
                <TableBody>
                  {tags.map((tag) => (
                    <TableRow key={tag.id}>
                      <TableCell><TagBadge tag={tag} /></TableCell>
                      <TableCell>{tag.comicCount}</TableCell>
                      <TableCell className="text-right">
                        <Button variant="ghost" size="icon" onClick={() => openDialog("edit", tag)} aria-label={`Edit ${tag.name}`}><Edit className="h-4 w-4" /></Button>
                        <Button variant="ghost" size="icon" onClick={() => setTagToDelete(tag)} aria-label={`Delete ${tag.name}`} title="Delete tag"><Trash2 className="h-4 w-4" /></Button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Kept above the account-deletion card so a download button never sits
          next to a destructive one. */}
      <Card className="mt-6">
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><FileArchive className="h-5 w-5" /> CBR to CBZ conversion tools</CardTitle>
          <CardDescription>
            Panel Page Flip accepts CBZ archives. These optional scripts convert every CBR file in a
            folder into a CBZ file. They require 7-Zip and run entirely on your own computer — nothing
            is uploaded and no conversion happens on the server.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          <div className="flex flex-col gap-3 sm:flex-row">
            {CONVERSION_TOOLS.map((tool) => (
              <Button key={tool.id} asChild variant="outline">
                <a href={tool.href} download={tool.fileName}>
                  <Download className="mr-2 h-4 w-4" /> {tool.label}
                </a>
              </Button>
            ))}
          </div>

          <div className="space-y-2">
            <h3 className="text-sm font-medium">How to use it</h3>
            <ol className="list-decimal space-y-1 pl-5 text-sm text-muted-foreground">
              <li>Install <a className="underline" href="https://www.7-zip.org/" target="_blank" rel="noreferrer noopener">7-Zip</a>. On Linux, RAR support may be a separate package (<code>p7zip-rar</code>).</li>
              <li>Download and unzip the script for your system.</li>
              <li>Put it in the folder containing the CBR files you want to convert.</li>
              <li>
                Run it. On Windows, right-click the <code>.ps1</code> and choose “Run with PowerShell”;
                on Linux or macOS, run <code>./convert-cbr-to-cbz.sh</code> from a terminal.
              </li>
              <li>Check the generated CBZ files open correctly, then upload them here.</li>
            </ol>
            <p className="text-sm text-muted-foreground">
              Windows blocks scripts downloaded from the internet. Unblock just this one with{" "}
              <code>Unblock-File .\Convert-CbrToCbz.ps1</code> rather than changing your machine’s
              execution policy.
            </p>
          </div>

          <div className="space-y-2 rounded-md border p-3">
            <h3 className="text-sm font-medium">Before you run it</h3>
            <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
              <li>These are convenience tools supplied without warranty. Read them before running them.</li>
              <li>They only create files on your own computer, and never make network requests.</li>
              <li>Your original CBR files are kept. Keep backups anyway, and check the results before deleting anything.</li>
              <li>Password-protected, damaged, multipart or otherwise unusual RAR archives may fail to convert.</li>
              <li>7-Zip is not bundled or redistributed here; you install it yourself.</li>
            </ul>
          </div>

          <div className="space-y-1 text-xs text-muted-foreground">
            <p>Version {CONVERSION_TOOLS_VERSION}. SHA-256 of each download, if you want to verify it:</p>
            {CONVERSION_TOOLS.map((tool) => (
              <p key={tool.id} className="break-all font-mono">
                {tool.fileName}: {tool.sha256}
              </p>
            ))}
          </div>
        </CardContent>
      </Card>

      <div className="mt-6">
        <UserMetadataCredentials />
      </div>

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

      <Dialog open={dialogMode !== null} onOpenChange={(open) => !open && closeDialog()}>
        <DialogContent>
          <DialogHeader><DialogTitle>{dialogMode === "create" ? "Create personal tag" : "Rename personal tag"}</DialogTitle></DialogHeader>
          <div className="space-y-2 py-4">
            <Label htmlFor="personal-tag-name">Name</Label>
            <Input id="personal-tag-name" value={tagName} onChange={(event) => setTagName(event.target.value)} maxLength={50} autoFocus onKeyDown={(event) => event.key === "Enter" && saveTag()} />
          </div>
          <DialogFooter><Button variant="outline" onClick={closeDialog}>Cancel</Button><Button onClick={saveTag} disabled={!tagName.trim() || saving}>{saving ? "Saving…" : "Save"}</Button></DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={tagToDelete !== null} onOpenChange={(open) => !open && setTagToDelete(null)}>
        <AlertDialogContent>
          <AlertDialogHeader><AlertDialogTitle>Delete “{tagToDelete?.name}”?</AlertDialogTitle><AlertDialogDescription>This permanently removes the personal tag from {tagToDelete?.comicCount || 0} comic(s).</AlertDialogDescription></AlertDialogHeader>
          <AlertDialogFooter><AlertDialogCancel>Cancel</AlertDialogCancel><AlertDialogAction onClick={deleteTag}>Delete</AlertDialogAction></AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog open={deleteDialogOpen} onOpenChange={setAccountDeletionDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Permanently delete your account?</AlertDialogTitle>
            <AlertDialogDescription>
              This deletes your comics, reading history, personal tags, sharing relationships,
              codes, and invitations, Dropbox connection, and account. This cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <div className="space-y-4">
            {user?.hasPassword === false ? (
              <p className="rounded-md border p-3 text-sm text-muted-foreground">
                Your connected provider has recently confirmed your identity. This confirmation expires after five minutes.
              </p>
            ) : (
              <div className="space-y-2">
                <Label htmlFor="delete-current-password">Current password</Label>
                <Input
                  id="delete-current-password"
                  type="password"
                  autoComplete="current-password"
                  value={currentPassword}
                  onChange={(event) => setCurrentPassword(event.target.value)}
                />
              </div>
            )}
            <div className="space-y-2">
              <Label htmlFor="delete-confirmation">Type DELETE to confirm</Label>
              <Input
                id="delete-confirmation"
                value={deleteConfirmation}
                onChange={(event) => setDeleteConfirmation(event.target.value)}
              />
            </div>
          </div>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deletingAccount}>Cancel</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              disabled={deleteConfirmation !== "DELETE" || (user?.hasPassword !== false && currentPassword === "") || deletingAccount}
              onClick={(event) => {
                event.preventDefault();
                deleteAccount();
              }}
            >
              {deletingAccount ? "Deleting…" : "Delete account permanently"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
