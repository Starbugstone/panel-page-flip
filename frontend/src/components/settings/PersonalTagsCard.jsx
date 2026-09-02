import { useEffect, useState } from "react";
import { Edit, Plus, Tags, Trash2 } from "lucide-react";

import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { useTags } from "@/hooks/use-tags";
import { useToast } from "@/hooks/use-toast";
import { TagBadge } from "@/components/TagBadge";
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from "@/components/ui/alert-dialog";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";

export function PersonalTagsCard() {
  const { toast } = useToast();
  const { fetchTags } = useTags();
  const [tags, setTags] = useState([]);
  const [loading, setLoading] = useState(true);
  const [dialogMode, setDialogMode] = useState(null);
  const [activeTag, setActiveTag] = useState(null);
  const [tagName, setTagName] = useState("");
  const [tagToDelete, setTagToDelete] = useState(null);
  const [saving, setSaving] = useState(false);

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
      .finally(() => {
        if (!ignore) setLoading(false);
      });

    return () => {
      ignore = true;
    };
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
        setTags((current) => [...current, data.tag].sort((left, right) => left.name.localeCompare(right.name)));
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

  return (
    <>
      <Card>
        <CardHeader className="flex-row items-start justify-between gap-4 space-y-0">
          <div>
            <CardTitle className="flex items-center gap-2"><Tags className="h-5 w-5" /> Personal tags</CardTitle>
            <CardDescription className="mt-2">Global tags are managed by an administrator and are not editable here.</CardDescription>
          </div>
          <Button onClick={() => openDialog("create")}><Plus className="mr-2 h-4 w-4" /> Add tag</Button>
        </CardHeader>
        <CardContent>
          <PersonalTagList
            loading={loading}
            tags={tags}
            onEdit={(tag) => openDialog("edit", tag)}
            onDelete={setTagToDelete}
          />
        </CardContent>
      </Card>

      <TagEditorDialog
        mode={dialogMode}
        name={tagName}
        saving={saving}
        onNameChange={setTagName}
        onClose={closeDialog}
        onSave={saveTag}
      />

      <TagDeleteDialog tag={tagToDelete} onClose={() => setTagToDelete(null)} onDelete={deleteTag} />
    </>
  );
}

function PersonalTagList({ loading, tags, onEdit, onDelete }) {
  if (loading) {
    return <p className="py-8 text-center text-muted-foreground">Loading tags…</p>;
  }
  if (tags.length === 0) {
    return (
      <div className="rounded-md border border-dashed py-10 text-center">
        <p className="font-medium">No personal tags yet</p>
        <p className="mt-1 text-sm text-muted-foreground">Create one here or while editing a comic.</p>
      </div>
    );
  }

  return (
    <div className="overflow-hidden rounded-md border">
      <Table>
        <TableHeader>
          <TableRow><TableHead>Name</TableHead><TableHead>Comics</TableHead><TableHead className="text-right">Actions</TableHead></TableRow>
        </TableHeader>
        <TableBody>
          {tags.map((tag) => (
            <TableRow key={tag.id}>
              <TableCell><TagBadge tag={tag} /></TableCell>
              <TableCell>{tag.comicCount}</TableCell>
              <TableCell className="text-right">
                <Button variant="ghost" size="icon" onClick={() => onEdit(tag)} aria-label={`Edit ${tag.name}`}><Edit className="h-4 w-4" /></Button>
                <Button variant="ghost" size="icon" onClick={() => onDelete(tag)} aria-label={`Delete ${tag.name}`} title="Delete tag"><Trash2 className="h-4 w-4" /></Button>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

function TagEditorDialog({ mode, name, saving, onNameChange, onClose, onSave }) {
  return (
    <Dialog open={mode !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader><DialogTitle>{mode === "create" ? "Create personal tag" : "Rename personal tag"}</DialogTitle></DialogHeader>
        <div className="space-y-2 py-4">
          <Label htmlFor="personal-tag-name">Name</Label>
          <Input
            id="personal-tag-name"
            value={name}
            onChange={(event) => onNameChange(event.target.value)}
            maxLength={50}
            autoFocus
            onKeyDown={(event) => event.key === "Enter" && onSave()}
          />
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button onClick={onSave} disabled={!name.trim() || saving}>{saving ? "Saving…" : "Save"}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function TagDeleteDialog({ tag, onClose, onDelete }) {
  return (
    <AlertDialog open={tag !== null} onOpenChange={(open) => !open && onClose()}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Delete “{tag?.name}”?</AlertDialogTitle>
          <AlertDialogDescription>This permanently removes the personal tag from {tag?.comicCount || 0} comic(s).</AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancel</AlertDialogCancel>
          <AlertDialogAction onClick={onDelete}>Delete</AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
