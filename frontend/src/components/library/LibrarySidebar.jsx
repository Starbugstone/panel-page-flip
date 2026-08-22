import { useState } from "react";
import { BookOpen, Cloud, FolderPlus, HardDrive, Library, Share2, Sparkles } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { LibraryFolderTree } from "@/components/library/LibraryFolderTree";
import { UserStorageUsage } from "@/components/UserStorageUsage";
import { useStorageUsage } from "@/hooks/use-storage-usage";

const QUICK_VIEWS = [
  { value: "all", label: "All comics", icon: Library },
  { value: "mine", label: "Mine", icon: Sparkles },
  { value: "shared", label: "Shared with me", icon: Share2 },
  { value: "reading", label: "Currently reading", icon: BookOpen },
  { value: "unread", label: "Not started", icon: BookOpen },
  { value: "dropbox", label: "Dropbox", icon: Cloud },
];

export function LibrarySidebar({ folders, activeFolderId, activeView, onFolderSelect, onViewSelect, onCreateFolder }) {
  const [creating, setCreating] = useState(false);
  const [name, setName] = useState("");
  const { usage } = useStorageUsage();

  const create = async (event) => {
    event.preventDefault();
    if (!name.trim()) return;
    const created = await onCreateFolder(name.trim(), activeFolderId == null ? null : Number(activeFolderId));
    if (!created) return;
    setName("");
    setCreating(false);
  };

  return (
    <aside className="space-y-5" aria-label="Library navigation">
      <div>
        <h2 className="mb-2 px-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Quick views</h2>
        <div className="space-y-0.5">
          {QUICK_VIEWS.map(({ value, label, icon: Icon }) => (
            <Button key={value} type="button" variant={activeFolderId == null && activeView === value ? "secondary" : "ghost"} className="h-9 w-full justify-start" onClick={() => onViewSelect(value)}>
              <Icon className="mr-2 h-4 w-4" /> {label}
            </Button>
          ))}
        </div>
      </div>
      <div>
        <div className="mb-2 flex items-center justify-between px-2">
          <h2 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Folders</h2>
          <Button type="button" variant="ghost" size="icon" className="h-7 w-7" onClick={() => setCreating((value) => !value)} aria-label="Create folder">
            <FolderPlus className="h-4 w-4" />
          </Button>
        </div>
        {creating && (
          <form onSubmit={create} className="mb-2 flex gap-1">
            <Input autoFocus value={name} onChange={(event) => setName(event.target.value)} placeholder="Folder name" maxLength={100} aria-label="Folder name" />
            <Button type="submit" size="sm" disabled={!name.trim()}>Add</Button>
          </form>
        )}
        <Button type="button" variant={activeFolderId == null && activeView === "folders" ? "secondary" : "ghost"} className="h-9 w-full justify-start" onClick={() => onFolderSelect(null)}>
          <Library className="mr-2 h-4 w-4" /> My Library
        </Button>
        {folders.length > 0 ? (
          <LibraryFolderTree folders={folders} activeFolderId={activeFolderId} onSelect={(folder) => onFolderSelect(folder.id)} />
        ) : (
          <p className="px-2 py-3 text-sm text-muted-foreground">Create a folder to organise your collection.</p>
        )}
      </div>
      {/* Absent rather than a placeholder while it loads or after it fails:
          storage use is ambient here, and a bar with nothing behind it would
          be worse than no bar. */}
      {usage && (
        <div className="border-t pt-4">
          <h2 className="mb-2 flex items-center gap-1.5 px-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            <HardDrive className="h-3.5 w-3.5" /> Storage
          </h2>
          <div className="px-2">
            <UserStorageUsage
              usedBytes={usage.storageUsedBytes}
              quotaBytes={usage.storageQuotaBytes}
              unmeasuredComicCount={usage.unmeasuredComicCount}
            />
          </div>
        </div>
      )}
    </aside>
  );
}
