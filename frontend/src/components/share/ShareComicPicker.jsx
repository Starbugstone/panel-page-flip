import { Search } from "lucide-react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { EXPLICIT_FLAG_LABEL } from "@/lib/sharing";

/**
 * Step one: which of the owner's comics are going.
 *
 * A caller that has already chosen shows its list read-only — reselecting a
 * table selection in a second list is a step that can only go wrong.
 */
export function ShareComicPicker({
  lockSelection, folder, search, setSearch, filteredComics, selectedComics, selectedIds,
  selectedComicIds, alreadySharedIds, visibleSelectable, allVisibleSelected,
  selectionLimitReached, limit, toggleComic, toggleVisible,
}) {
  return (
    <section className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 className="font-semibold">{folder ? "1. What is in this folder" : "1. Choose comics"}</h3>
          <p className="text-xs text-muted-foreground">
            {Number.isFinite(limit) ? `${selectedComicIds.length}/${limit}` : selectedComicIds.length} selected
            {/* Named as a count and never as a list. Which comic cannot be
                passed on is a fact about the library, and answering it here
                would put a comic in a share dialog that is not going. */}
            {folder?.unshareableCount > 0 && (
              <> · {folder.unshareableCount} not yours to share {folder.unshareableCount === 1 ? "was" : "were"} left out</>
            )}
          </p>
        </div>
        {!lockSelection && visibleSelectable.length > 0 && (
          <Button type="button" variant="outline" size="sm" onClick={toggleVisible}>
            {allVisibleSelected ? "Clear shown" : "Select shown"}
          </Button>
        )}
      </div>

      {/* A caller that has already chosen does not ask again. Reselecting
          a table selection in a second list is a step that can only go
          wrong. */}
      {lockSelection ? (
        <ul className="max-h-40 divide-y overflow-y-auto rounded-md border text-sm">
          {selectedComics.map((comic) => (
            <li key={comic.id} className="flex items-center gap-2 p-3">
              <span className="truncate">{comic.title}</span>
              {comic.explicitContent && (
                <Badge variant="outline">{EXPLICIT_FLAG_LABEL}</Badge>
              )}
            </li>
          ))}
        </ul>
      ) : (
        <>
          <div className="relative">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Search your library…"
              className="pl-9"
            />
          </div>

          <div className="max-h-64 divide-y overflow-y-auto rounded-md border">
            {filteredComics.length === 0 ? (
              <p className="p-4 text-sm text-muted-foreground">No owned comics match this search.</p>
            ) : filteredComics.map((comic) => {
              const id = String(comic.id);
              const alreadyShared = alreadySharedIds.has(id);
              const checked = selectedIds.has(id) && !alreadyShared;
              const disabled = alreadyShared || (selectionLimitReached && !checked);

              return (
                <label
                  key={comic.id}
                  className={`flex items-center gap-3 p-3 ${disabled ? "cursor-not-allowed opacity-60" : "cursor-pointer"}`}
                >
                  <Checkbox
                    checked={checked}
                    disabled={disabled}
                    onCheckedChange={() => toggleComic(comic.id)}
                    aria-label={`Select ${comic.title}`}
                  />
                  {comic.coverImagePath ? (
                    <img
                      src={comic.coverImagePath}
                      alt=""
                      loading="lazy"
                      className="h-14 w-10 flex-none rounded object-cover"
                    />
                  ) : (
                    <div className="h-14 w-10 flex-none rounded bg-muted" />
                  )}
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{comic.title}</p>
                    {comic.author && (
                      <p className="truncate text-xs text-muted-foreground">{comic.author}</p>
                    )}
                    {comic.explicitContent && (
                      <Badge variant="outline" className="mt-1">{EXPLICIT_FLAG_LABEL}</Badge>
                    )}
                  </div>
                  {alreadyShared && (
                    <Badge variant="secondary">Already shared</Badge>
                  )}
                </label>
              );
            })}
          </div>
        </>
      )}
    </section>
  );
}
