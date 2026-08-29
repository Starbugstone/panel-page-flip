import { ComicEditDialog } from "@/components/ComicEditDialog.jsx";
import { ShareComicsDialog } from "@/components/ShareComicsDialog.jsx";
import { MoveToFolderDialog } from "@/components/library/MoveToFolderDialog";

/**
 * Everything the library opens over itself.
 *
 * Each one is keyed on what it is acting upon and mounted only while open, so
 * a dialog opened from a second comic never inherits the state of the first.
 */
export function LibraryDialogs({ folders, editing, sharing, movingComic, movingFolder, folderActions, onMoveComics, onSaveComic, onShared }) {
  return (
    <>
      {editing.comic && (
        <ComicEditDialog comic={editing.comic} isOpen onClose={editing.onClose} onSave={onSaveComic} />
      )}

      {sharing.comicIds && (
        <ShareComicsDialog
          key={sharing.comicIds.join(",")}
          isOpen
          onClose={sharing.onClose}
          initialComicIds={sharing.comicIds}
          lockSelection
          onShared={onShared}
        />
      )}

      <MoveToFolderDialog
        key={movingComic.comic?.id ?? "no-comic"}
        open={Boolean(movingComic.comic)}
        onOpenChange={(open) => { if (!open) movingComic.onClose(); }}
        folders={folders}
        currentFolderId={movingComic.comic?.libraryFolderId ?? null}
        itemCount={1}
        onMove={(folderId) => onMoveComics([movingComic.comic.id], folderId)}
      />

      <MoveToFolderDialog
        key={`folder-${movingFolder.folderId}`}
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
