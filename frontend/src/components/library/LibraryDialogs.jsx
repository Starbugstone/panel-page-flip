import { ComicEditDialog } from "@/components/ComicEditDialog.jsx";
import { ShareComicsDialog } from "@/components/ShareComicsDialog.jsx";
import { MoveToFolderDialog } from "@/components/library/MoveToFolderDialog";
import { CreateFolderDialog } from "@/components/library/CreateFolderDialog";

/**
 * Everything the library opens over itself.
 *
 * Each one is keyed on what it is acting upon and mounted only while open, so
 * a dialog opened from a second comic never inherits the state of the first.
 */
export function LibraryDialogs({ folders, editing, sharing, movingComic, movingFolder, creatingFolder, folderActions, onMoveComics, onSaveComic, onShared }) {
  return (
    <>
      {editing.comic && (
        <ComicEditDialog comic={editing.comic} isOpen onClose={editing.onClose} onSave={onSaveComic} />
      )}

      {/* One dialog for a comic, a table selection and a whole folder. What
          the folder adds is a higher ceiling and a request that names the
          folder rather than the ids — the workflow either side of that is the
          same one, deliberately. */}
      {(sharing.comicIds || sharing.folder) && (
        <ShareComicsDialog
          key={sharing.folder ? `share-folder-${sharing.folder.folderId}` : `share-comics-${sharing.comicIds.join(",")}`}
          isOpen
          onClose={sharing.onClose}
          initialComicIds={sharing.folder ? sharing.folder.comicIds : sharing.comicIds}
          folder={sharing.folder}
          lockSelection
          onShared={onShared}
        />
      )}

      <CreateFolderDialog
        key={`create-${creatingFolder.parent?.id ?? "root"}`}
        open={creatingFolder.open}
        onOpenChange={creatingFolder.onOpenChange}
        parentFolder={creatingFolder.parent}
        onCreate={folderActions.createLibraryFolder}
      />

      <MoveToFolderDialog
        key={`move-comic-${movingComic.comic?.id ?? "none"}`}
        open={Boolean(movingComic.comic)}
        onOpenChange={(open) => { if (!open) movingComic.onClose(); }}
        folders={folders}
        currentFolderId={movingComic.comic?.libraryFolderId ?? null}
        itemCount={1}
        onMove={(folderId) => onMoveComics([movingComic.comic.id], folderId)}
      />

      <MoveToFolderDialog
        key={`move-folder-${movingFolder.folderId}`}
        open={movingFolder.open}
        onOpenChange={movingFolder.onOpenChange}
        folders={folders}
        currentFolderId={folderActions.currentFolder()?.parentId ?? null}
        movingFolderId={movingFolder.folderId}
        itemCount={1}
        itemLabel="folder"
        onMove={folderActions.moveCurrentFolder}
      />
    </>
  );
}
