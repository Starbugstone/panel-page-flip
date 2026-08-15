import { Label } from "@/components/ui/label";
import { folderAncestors } from "@/lib/library-folders";

export function FolderDestinationSelect({ folders, value, onChange, disabled = false, id = "folder-destination" }) {
  const ordered = [...folders].sort((a, b) => {
    const aPath = folderAncestors(folders, a.id).map((folder) => folder.name).join(" / ");
    const bPath = folderAncestors(folders, b.id).map((folder) => folder.name).join(" / ");
    return aPath.localeCompare(bPath, undefined, { sensitivity: "base" });
  });
  return (
    <div className="space-y-2">
      <Label htmlFor={id}>Destination folder</Label>
      <select id={id} className="h-10 w-full rounded-md border bg-background px-3 text-sm" value={value == null ? "root" : String(value)} onChange={(event) => onChange(event.target.value === "root" ? null : Number(event.target.value))} disabled={disabled}>
        <option value="root">My Library / root</option>
        {ordered.map((folder) => <option key={folder.id} value={folder.id}>{folderAncestors(folders, folder.id).map((item) => item.name).join(" / ")}</option>)}
      </select>
    </div>
  );
}
