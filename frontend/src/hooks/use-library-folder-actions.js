import { useToast } from "@/hooks/use-toast.js";

/**
 * Creating, renaming, moving and deleting the folder currently open.
 *
 * Deleting asks twice on purpose. The first refusal carries a count of what
 * would be swept up with it, and the second confirmation quotes those numbers
 * back — a folder tree is not something to lose to one misread click. No comic
 * file is ever deleted by any of this; the contents move up a level.
 */
export function useLibraryFolderActions({ folders, activeFolderId, navigateFolder, createFolder, updateFolder, deleteFolder }) {
  const { toast } = useToast();
  const currentFolder = () => folders.find((folder) => Number(folder.id) === activeFolderId);

  const report = (title) => (requestError) => {
    toast({ title, description: requestError.message, variant: "destructive" });
  };

  const createLibraryFolder = async (name, parentId) => {
    try {
      await createFolder(name, parentId);
      toast({ title: "Folder created", description: `Created “${name}”.` });
      return true;
    } catch (requestError) {
      report("Could not create folder")(requestError);
      return false;
    }
  };

  const renameCurrentFolder = async () => {
    const folder = currentFolder();
    if (!folder) return;
    const name = window.prompt("Rename folder", folder.name)?.trim();
    if (!name || name === folder.name) return;
    try {
      await updateFolder(folder.id, { name });
    } catch (requestError) {
      report("Could not rename folder")(requestError);
    }
  };

  const deleteCurrentFolder = async () => {
    const folder = currentFolder();
    if (!folder || !window.confirm(`Delete “${folder.name}”? No comic files will be deleted.`)) return;
    try {
      await deleteFolder(folder.id, false);
      navigateFolder(folder.parentId);
      return;
    } catch (requestError) {
      if (requestError.data?.code !== "folder_deletion_confirmation_required") {
        report("Could not delete folder")(requestError);
        return;
      }
      const { summary } = requestError.data;
      const destination = folder.parentId == null ? "My Library" : "the parent folder";
      if (!window.confirm(`This removes ${summary.folderCount} folder(s). ${summary.comicCount} comic(s) will move to ${destination}. No comic files will be deleted.`)) return;
    }
    try {
      await deleteFolder(folder.id, true);
      navigateFolder(folder.parentId);
    } catch (deleteError) {
      report("Could not delete folder")(deleteError);
    }
  };

  const moveCurrentFolder = async (parentId) => {
    try {
      await updateFolder(activeFolderId, { parentId });
      toast({ title: "Folder moved" });
    } catch (moveError) {
      report("Could not move folder")(moveError);
      throw moveError;
    }
  };

  return { currentFolder, createLibraryFolder, renameCurrentFolder, deleteCurrentFolder, moveCurrentFolder };
}
