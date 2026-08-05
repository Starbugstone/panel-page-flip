import { useCallback, useEffect, useState } from "react";
import { Download, Edit, Plus, ShieldAlert, Tags, Trash2 } from "lucide-react";
import { useNavigate } from "react-router-dom";
import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { useToast } from "@/hooks/use-toast";
import { useTags } from "@/hooks/use-tags";
import { TagBadge } from "@/components/TagBadge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from "@/components/ui/alert-dialog";
import { useAuth } from "@/hooks/use-auth";

export default function UserSettings() {
  const { toast } = useToast();
  const { logout } = useAuth();
  const navigate = useNavigate();
  const { fetchTags } = useTags();
  const [tags, setTags] = useState([]);
  const [loading, setLoading] = useState(true);
  const [dialogMode, setDialogMode] = useState(null);
  const [activeTag, setActiveTag] = useState(null);
  const [tagName, setTagName] = useState("");
  const [tagToDelete, setTagToDelete] = useState(null);
  const [saving, setSaving] = useState(false);
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [deleteConfirmation, setDeleteConfirmation] = useState("");
  const [currentPassword, setCurrentPassword] = useState("");
  const [deletingAccount, setDeletingAccount] = useState(false);

  const loadTags = useCallback(async () => {
    setLoading(true);
    try {
      const data = await api.get("/api/tags");
      setTags((data.tags || []).filter((tag) => !tag.isGlobal));
    } catch (error) {
      logger.error("Failed to load personal tags:", error);
      toast({ title: "Could not load tags", description: error.message, variant: "destructive" });
    } finally {
      setLoading(false);
    }
  }, [toast]);

  useEffect(() => { loadTags(); }, [loadTags]);

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
      URL.revokeObjectURL(url);
      toast({ title: "Data export downloaded" });
    } catch (error) {
      toast({ title: "Could not export your data", description: error.message, variant: "destructive" });
    }
  };

  const deleteAccount = async () => {
    setDeletingAccount(true);
    try {
      await api.delete("/api/privacy/account", {
        body: { confirmation: deleteConfirmation, currentPassword },
      });
      await logout();
      navigate("/", { replace: true });
    } catch (error) {
      toast({ title: "Could not delete your account", description: error.message, variant: "destructive" });
    } finally {
      setDeletingAccount(false);
    }
  };

  return (
    <div className="container mx-auto max-w-5xl px-4 py-8">
      <div className="mb-6">
        <h1 className="text-3xl font-comic">Settings</h1>
        <p className="mt-1 text-muted-foreground">Manage the tags that belong only to your account.</p>
      </div>

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

      <Card className="mt-6">
        <CardHeader>
          <CardTitle className="flex items-center gap-2"><ShieldAlert className="h-5 w-5" /> Privacy and account data</CardTitle>
          <CardDescription>Download a machine-readable copy of your account data or permanently delete your account.</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4 sm:flex-row">
          <Button variant="outline" onClick={downloadPersonalData}>
            <Download className="mr-2 h-4 w-4" /> Download my data
          </Button>
          <Button variant="destructive" onClick={() => setDeleteDialogOpen(true)}>
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

      <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Permanently delete your account?</AlertDialogTitle>
            <AlertDialogDescription>
              This deletes your comics, reading history, personal tags, share invitations,
              Dropbox connection, and account. This cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <div className="space-y-4">
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
              disabled={deleteConfirmation !== "DELETE" || currentPassword === "" || deletingAccount}
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
