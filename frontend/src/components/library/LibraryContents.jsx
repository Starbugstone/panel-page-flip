import { ComicCard } from "@/components/ComicCard.jsx";
import { ComicTableView } from "@/components/ComicTableView.jsx";
import { LibraryFolderCard } from "@/components/library/LibraryFolderCard";

// Above the fold on a wide screen. These covers are worth fetching eagerly;
// the rest are not, and loading every one of them would compete with them.
const EAGER_COVER_COUNT = 8;

/**
 * The folders and comics at the current location, as cards or as a table.
 *
 * A comic shows where it lives only when that is not already obvious — during
 * a search, or in the flat library view. Inside a folder, every row would say
 * the same thing.
 */
export function LibraryContents({
  viewMode,
  comics,
  childFolders,
  folders,
  folderNames,
  showLocation,
  onOpenFolder,
  onEditComic,
  comicActions,
  onShareComics,
}) {
  const folderCards = childFolders.map((folder) => (
    <LibraryFolderCard key={folder.id} folder={folder} onOpen={onOpenFolder} />
  ));

  const locationName = (comic) => {
    if (!showLocation) return null;
    return comic.libraryFolderId == null ? "My Library" : folderNames.get(Number(comic.libraryFolderId));
  };

  if (viewMode === "table") {
    return (
      <div className="overflow-x-auto">
        {childFolders.length > 0 && (
          <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">{folderCards}</div>
        )}
        <ComicTableView
          comics={comics}
          folders={folders}
          onEditComic={onEditComic}
          onBulkAddTag={comicActions.addTagToComics}
          onBulkDelete={comicActions.deleteComics}
          onBulkMove={comicActions.moveComicsToFolder}
          onShareSelected={onShareComics}
        />
      </div>
    );
  }

  return (
    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-4">
      {folderCards}
      {comics.map((comic, index) => (
        <ComicCard
          key={comic.id}
          comic={comic}
          coverPriority={index < EAGER_COVER_COUNT}
          onResetProgress={comicActions.resetReadingProgress}
          onEditComic={onEditComic}
          onDeleteComic={comicActions.deleteComic}
          onShareClick={(id) => onShareComics([id])}
          onRemoveSharedComic={comicActions.removeSharedComic}
          onMoveComic={comicActions.onMoveComic}
          locationName={locationName(comic)}
        />
      ))}
    </div>
  );
}
