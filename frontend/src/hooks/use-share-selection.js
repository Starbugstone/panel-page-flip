import { useEffect, useMemo, useState } from "react";

import { api } from "@/lib/api";
import { logger } from "@/lib/logger";
import { SHARE_CODE_TYPES } from "@/lib/sharing";
import { MAX_BULK_COMICS, MIN_GROUP_COMICS, MODES, TARGETS, liveComicIdsForRecipient } from "@/lib/sharing-workflow";

/**
 * Which comics are going, out of the ones this owner may actually share.
 *
 * The selection is held as ids rather than comics because a locked selection
 * can name a comic the picker never fetched — it filters to `canShare` — and
 * counting what the library returned would describe a different share from the
 * one about to be sent.
 */
export function useShareSelection({ isOpen, sharedByMe, initialComicIds, mode, target, recipientEmail, onError }) {
  const [comics, setComics] = useState([]);
  const [recentRecipients, setRecentRecipients] = useState([]);
  const [search, setSearch] = useState("");
  const [isLoading, setIsLoading] = useState(true);
  const [selectedIds, setSelectedIds] = useState(
    () => new Set((initialComicIds || []).map((id) => String(id)))
  );

  useEffect(() => {
    if (!isOpen) return undefined;
    let ignore = false;

    Promise.all([
      api.get("/api/comics?ownership=mine"),
      api.get("/api/shares/recent-recipients"),
    ])
      .then(([library, recipients]) => {
        if (ignore) return;
        // ownership=mine is already scoped server-side; canShare is a second UI
        // guard so a future payload change cannot make a received comic appear
        // selectable here.
        setComics((library.comics || []).filter((comic) => comic.canShare === true));
        setRecentRecipients(recipients.recipients || []);
      })
      .catch((err) => {
        if (ignore) return;
        logger.error("Failed to load sharing picker:", err);
        onError(err.message || "Could not load your shareable comics.");
      })
      .finally(() => {
        if (!ignore) setIsLoading(false);
      });

    return () => { ignore = true; };
  }, [isOpen, onError]);

  // Only meaningful for an email recipient. A username or code names somebody
  // the sender cannot match against their own list, and a content code names
  // nobody at all, so neither can mark a comic as already shared.
  const alreadySharedIds = useMemo(
    () => liveComicIdsForRecipient(sharedByMe, mode === MODES.DIRECT && target === TARGETS.EMAIL ? recipientEmail : ""),
    [sharedByMe, mode, target, recipientEmail]
  );

  const filteredComics = useMemo(() => {
    const query = search.trim().toLowerCase();
    if (!query) return comics;

    return comics.filter((comic) =>
      [comic.title, comic.author].filter(Boolean).some((value) => String(value).toLowerCase().includes(query))
    );
  }, [comics, search]);

  const selectedComicIds = useMemo(
    () => [...selectedIds].filter((id) => !alreadySharedIds.has(id)),
    [selectedIds, alreadySharedIds]
  );
  const selectedComics = useMemo(() => {
    const selected = new Set(selectedComicIds);
    return comics.filter((comic) => selected.has(String(comic.id)));
  }, [comics, selectedComicIds]);

  const visibleSelectable = filteredComics.filter((comic) => !alreadySharedIds.has(String(comic.id)));
  const allVisibleSelected = visibleSelectable.length > 0
    && visibleSelectable.every((comic) => selectedIds.has(String(comic.id)));

  const toggleComic = (comicId) => {
    const id = String(comicId);
    if (alreadySharedIds.has(id)) return;

    setSelectedIds((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else if (selectedComicIds.length < MAX_BULK_COMICS) next.add(id);
      return next;
    });
  };

  const toggleVisible = () => {
    setSelectedIds((current) => {
      const next = new Set(current);
      if (allVisibleSelected) {
        visibleSelectable.forEach((comic) => next.delete(String(comic.id)));
        return next;
      }

      let slots = MAX_BULK_COMICS - [...next].filter((id) => !alreadySharedIds.has(id)).length;
      for (const comic of visibleSelectable) {
        if (slots <= 0) break;
        const id = String(comic.id);
        if (!next.has(id)) {
          next.add(id);
          --slots;
        }
      }
      return next;
    });
  };

  return {
    comics, recentRecipients, isLoading, search, setSearch, selectedIds,
    alreadySharedIds, filteredComics, selectedComicIds, selectedComics,
    visibleSelectable, allVisibleSelected, toggleComic, toggleVisible,
    selectionLimitReached: selectedComicIds.length >= MAX_BULK_COMICS,
    // One comic is a C-, two or more a G-; that is the entire difference, and
    // it is decided here rather than asked of the user.
    codeType: selectedComicIds.length >= MIN_GROUP_COMICS ? SHARE_CODE_TYPES.GROUP : SHARE_CODE_TYPES.COMIC,
    alreadyExplicitCount: selectedComics.filter((comic) => comic.explicitContent).length,
  };
}
