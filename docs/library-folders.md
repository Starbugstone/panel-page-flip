# Library folders

Folders organise what a person sees in their library. They are a private view,
not a place on disk: creating, renaming, moving and deleting them never touches
an archive, a cover, or a path in `Comic.filePath`. Reorganising a library of
ten thousand comics moves no bytes.

That separation is the whole design. Comic sources live under
`backend/public/uploads/comics/{user_id}/` and are named for storage safety, not
for browsing; folders are how a library is browsed. Neither has to compromise
for the other.

## What a folder is

`LibraryFolder` is one node in one account's tree. `LibraryFolderItem` is one
viewer's placement of one comic — a row joining a user, a comic and a folder,
unique per user and comic. **Absence of a row means the comic is at the root.**
Nothing has to be written for a comic to be unfiled, which is what keeps an
untouched library free of placement rows.

Because the placement belongs to the *viewer* rather than to the comic, a comic
shared with several people is filed independently by each of them. A recipient
can file a shared comic wherever they like; the owner never sees that, and
neither sees the other's tree. Revoking a share removes the recipient's access,
not their folder.

## Rules

| Rule | Value | Why |
|---|---|---|
| Maximum nesting depth | 10 | A tree deeper than this is a tagging problem wearing a folder costume, and unbounded depth makes every subtree walk a denial-of-service vector |
| Maximum comics moved per request | 500 | The move is one transaction; an unbounded batch is an unbounded lock |
| Folder name length | 1–100 characters | |
| Forbidden in a name | `/`, `\`, control characters | Names are displayed and sorted, never used to build a path — the ban is defence in depth, not a path requirement |
| Sibling names | Unique within a parent | Enforced by a database unique index, not only by a check |

Moving a folder is checked twice: a folder can never be moved inside itself or
one of its own descendants, and the resulting tree must still fit within the
depth limit — measured from the height of the whole subtree being moved, not
just the folder itself.

### Uniqueness and NULL parents

MySQL treats `NULL`s in a unique index as distinct, so a plain index over
`(owner, parent_id, name)` would let two root folders share a name while
correctly rejecting two nested ones. `LibraryFolder` therefore mirrors the
validated parent id into a non-null `parent_scope` column — zero for root — and
the unique index uses that. Root and nested siblings get the same race-proof
uniqueness, and two simultaneous requests cannot both create "Manga".

## Deleting a folder

Deletion is a two-step confirmation whenever it would affect anything: if the
folder has subfolders or holds any comic, the first request is refused with a
`FolderDeletionConfirmationRequired` carrying a summary — how many folders, how
many comics, and where they would go. The client shows that summary and repeats
the request with confirmation.

**Deleting a folder never deletes a comic.** Its contents move to the deleted
folder's parent, or to the root if it had none. The comic count in the summary
counts only comics the viewer can currently see, so a tombstoned or revoked
share is not reported as something they are about to lose.

## Sharing a folder

A folder can be handed over in one act: **Share folder** in the folder bar
resolves the folder to comics and opens the ordinary share workflow with them
already chosen.

It is a **snapshot of what is in the folder now**, expanded into one
`ComicShare` per comic. The folder itself is not shared and no grant survives on
it, so a comic filed in tomorrow is not shared by yesterday's act — and the
recipient gets comics, never a copy of the sender's tree, which they then file
wherever they like.

The subtree goes, not just the folder: sharing "DragonBall" shares
"DragonBall/Z" too, because that is what a person pointing at a folder means.

Because a folder is a view rather than a container, it can hold comics somebody
else shared with this viewer. Those are filtered out by the same `COMIC_SHARE`
check every other route asks — a comic is not passed on by being filed next to
one that can be — and are reported to the sender as a count rather than a list.

| Rule | Value | Why |
|---|---|---|
| Comics per folder share | 200 | A folder is not a selection; asking somebody to pick twenty of forty-two volumes is the work they wanted one click for |
| Comics per hand-picked share | 20 | Unchanged. The larger ceiling is only offered to a request the server resolves itself |

The share names the folder and the server walks it again, so what is sent is the
folder as it is rather than as the dialog previewed it. A folder holding nothing
the sender may share is refused with one sentence whether it is empty or full of
borrowed comics, because the answer they need does not depend on which.

See [One dialog, three entry points](../DEV_README.md#one-dialog-three-entry-points)
for the workflow this joins, and the notice it sends.

## Uploading into a folder

An upload may name a destination folder. If that folder has been deleted by the
time the upload completes — a long bulk upload makes this ordinary rather than
exotic — the comic lands at the root instead. A destination that stopped
existing is not a reason to fail an upload that otherwise succeeded.

## API

All routes require an authenticated session and act only on the caller's own
tree.

| Route | Method | Purpose |
|---|---|---|
| `/api/library/folders` | `GET` | The caller's folder tree |
| `/api/library/folders` | `POST` | Create a folder, optionally under `parentId` |
| `/api/library/folders/{id}` | `PATCH` | Rename and/or reparent |
| `/api/library/folders/{id}` | `DELETE` | Delete, with the confirmation above |
| `/api/library/folders/move-comics` | `POST` | Place up to 500 comics in a folder, or `null` for the root |
| `/api/shares/folders/{id}/comics` | `GET` | What sharing this folder would offer: the ids, the subfolder count, and how many were left out |

A folder id that does not belong to the caller is reported as not found rather
than as forbidden: confirming that an id exists would say something about
another account's library.

Placing comics — both `move-comics` and an upload's destination — re-resolves
the target folder under a pessimistic read lock inside the transaction that
writes, so a folder deleted between validation and write cannot receive comics.
`move-comics` fails the whole batch in that case; an upload falls back to the
root, because refusing a comic that uploaded successfully would be the worse
answer.

## Frontend

- `components/library/LibrarySidebar.jsx` — the tree beside the collection
- `components/library/LibraryFolderTree.jsx` — the tree itself
- `components/library/LibraryFolderCard.jsx` — a folder shown among comics
- `components/library/LibraryBreadcrumbs.jsx` — where you are
- `components/library/LibraryFolderBar.jsx` — where you are and what can be done to it, including **Share folder**
- `components/library/CreateFolderDialog.jsx` — create a root folder or a subfolder in the current location
- `components/library/MoveToFolderDialog.jsx` — the move, including the bulk one
- `components/library/FolderDestinationSelect.jsx` — the upload destination
- `hooks/use-library-folders.jsx` — loading and mutating the tree
- `hooks/use-library-folder-actions.js` — acting on the folder currently open,
  including reading what sharing it would offer before the dialog opens
- `lib/last-read-jump.js` — the folder bar's "Last read" button, grid view
  only: scrolls to and briefly highlights the comic in the current view with
  the newest `readingProgress.lastReadAt`, so a long folder reopens where
  reading stopped

## Related

- [Who may reach a comic](comic-access.md) — folders are a view over what the
  voter already permits, and never widen it; sharing a folder is no exception
- [Storage accounting and the per-user quota](storage-quota.md) — folders hold
  no bytes and count towards nothing
