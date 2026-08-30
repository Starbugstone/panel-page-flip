import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

export function CreateFolderDialog({ open, onOpenChange, parentFolder = null, onCreate }) {
  const [name, setName] = useState("");
  const [creating, setCreating] = useState(false);
  const isSubfolder = parentFolder != null;

  const submit = async (event) => {
    event.preventDefault();
    const nextName = name.trim();
    if (!nextName || creating) return;

    setCreating(true);
    try {
      const created = await onCreate(nextName, parentFolder?.id ?? null);
      if (!created) return;
      setName("");
      onOpenChange(false);
    } finally {
      setCreating(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={(next) => { if (!creating) onOpenChange(next); }}>
      <DialogContent className="max-w-md">
        <form onSubmit={submit}>
          <DialogHeader>
            <DialogTitle>{isSubfolder ? "New subfolder" : "New folder"}</DialogTitle>
            <DialogDescription>
              {isSubfolder
                ? <>Create a folder inside <strong>“{parentFolder.name}”</strong>.</>
                : "Create a folder at the top level of My Library."}
            </DialogDescription>
          </DialogHeader>
          <div className="my-5 space-y-2">
            <Label htmlFor="new-library-folder-name">Folder name</Label>
            <Input
              id="new-library-folder-name"
              autoFocus
              value={name}
              onChange={(event) => setName(event.target.value)}
              maxLength={100}
              placeholder={isSubfolder ? "Subfolder name" : "Folder name"}
              disabled={creating}
            />
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={creating}>Cancel</Button>
            <Button type="submit" disabled={!name.trim() || creating}>
              {creating ? "Creating…" : isSubfolder ? "Create subfolder" : "Create folder"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
