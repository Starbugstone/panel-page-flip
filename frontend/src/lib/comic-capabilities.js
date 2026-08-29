/**
 * What this viewer may do with a comic.
 *
 * The server decides and the card only reflects it. A recipient sees no owner
 * actions at all — the comic is not theirs to edit, delete or pass on — so
 * `isShared` overrides the individual flags rather than being one more of them.
 */
export function describeComicCapabilities(comic) {
  const isSharedWithMe = Boolean(comic.isShared);

  return {
    isSharedWithMe,
    canEdit: comic.canEdit !== false && !isSharedWithMe,
    canDelete: comic.canDelete !== false && !isSharedWithMe,
    canShare: comic.canShare !== false && !isSharedWithMe,
  };
}
