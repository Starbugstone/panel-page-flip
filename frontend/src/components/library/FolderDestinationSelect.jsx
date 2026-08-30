import { useState } from "react";
import { FolderPlus } from "lucide-react";
import { CreateFolderDialog } from "@/components/library/CreateFolderDialog";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { useToast } from "@/hooks/use-toast";
import { folderAncestors } from "@/lib/library-folders";

export function FolderDestinationSelect({ folders, value, onChange, onCreateFolder, disabled = false, id = "folder-destination" }) {
  const { toast } = useToast();
  const [createOpen, setCreateOpen] = useState(false);
  const ordered = [...folders].sort((a, b) => {
    const aPath = folderAncestors(folders, a.id).map((folder) => folder.name).join(" / ");
    const bPath = folderAncestors(folders, b.id).map((folder) => folder.name).join(" / ");
    return aPath.localeCompare(bPath, undefined, { sensitivity: "base" });
  });
  const parentFolder = value == null
    ? null
    : folders.find((folder) => Number(folder.id) === Number(value)) || null;

  const createAndSelectFolder = async (name, parentId) => {
    try {
      const folder = await onCreateFolder(name, parentId);
      if (!folder) return false;
      onChange(Number(folder.id));
      toast({
        title: "Folder created",
        description: `Uploads will go to ${folder.name}.`,
      });
      return folder;
    } catch (error) {
      toast({
        title: "Could not create folder",
        description: error?.message || "Please try again.",
        variant: "destructive",
      });
      return false;
    }
  };

  return (
    <>
      <div className="space-y-2">
        <Label htmlFor={id}>Destination folder</Label>
        <div className="flex flex-col gap-2 sm:flex-row">
          <select id={id} className="h-10 w-full rounded-md border bg-background px-3 text-sm sm:min-w-0 sm:flex-1" value={value == null ? "root" : String(value)} onChange={(event) => onChange(event.target.value === "root" ? null : Number(event.target.value))} disabled={disabled}>
            <option value="root">My Library / root</option>
            {ordered.map((folder) => <option key={folder.id} value={folder.id}>{folderAncestors(folders, folder.id).map((item) => item.name).join(" / ")}</option>)}
          </select>
          {onCreateFolder && (
            <Button type="button" variant="outline" onClick={() => setCreateOpen(true)} disabled={disabled}>
              <FolderPlus className="h-4 w-4" />
              {parentFolder ? "New subfolder" : "New folder"}
            </Button>
          )}
        </div>
      </div>

      {createOpen && (
        <CreateFolderDialog
          open
          onOpenChange={setCreateOpen}
          parentFolder={parentFolder}
          onCreate={createAndSelectFolder}
        />
      )}
    </>
  );
}
