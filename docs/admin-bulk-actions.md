# Bulk actions in the admin panel

Six admin tables carry a tick-box column and a bar above them that acts on
whatever is ticked: **Pending**, **Users**, **Comics**, **Tags**, **Shares** and
**Dropbox**.

The point is moderation work that arrives in batches. A spam sign-up wave is
forty pending accounts, not one; a user who bulk-uploaded somebody else's series
is thirty comics. Doing that a row at a time is not a UI inconvenience, it is
the reason the work does not get done.

## What each tab offers

| Tab | Actions | Rows it will skip |
| --- | --- | --- |
| Pending | Warn, Verify, Resend verification, Delete | your own account, for Warn and Delete |
| Users | Warn, Delete | the same |
| Comics | Warn owners, Delete | — |
| Tags | Delete | — |
| Shares | Warn sharers, Revoke | shares that are no longer live |
| Dropbox | Force import, Disconnect | — |

Tags get deletion and nothing else. A name belongs to one tag, and
"hide from library" is a setting only global tags have, so neither question has
an answer a mixed selection could give.

## Selecting

Click a tick box for one row; shift-click a second to take everything between
them, the way a file manager does. The range takes the *anchor's* state rather
than the clicked box's own, so shift-clicking back inside a range you just
selected shortens it instead of inverting the half you clicked through, and the
anchor stays put so successive shift-clicks resize one range rather than walking
it along the table. The header box takes or releases the whole page.

Selection covers the page on screen and no further. Changing page, search,
filter, or reloading after an action abandons it: `useRowSelection` is keyed by
the list identity `useAdminList` hands out, so there is never a render where a
new page shows the previous page's ticks. This is also why an action never
reaches a row you cannot see.

## How it reaches the server

There are no batch endpoints behind any of this. Each ticked row goes through
the same single-row route the row's own button uses — `DELETE /api/users/{id}`,
`POST /api/admin/warnings`, `POST /api/admin/shares/{id}/revoke` — one request
at a time, in `runBulkAction`.

That is a deliberate trade of requests for correctness:

- The permission check, the audit entry and the refusal message are identical
  whether an administrator acted on one row or forty. A batch endpoint would be
  a second copy of each, and the copy is what drifts.
- Sequential, not concurrent. These are destructive operations against a shared
  installation, and firing a page of them at once turns one careless click into
  a load spike.

The cost is real: forty rows is forty requests, and the bar shows
`Working… 12 of 40` while they go. If a page size ever grows to the point where
that is the bottleneck, the answer is a batch endpoint that reuses the
per-row service, not a client that fires them all at once.

## Partial success is the normal outcome

Deleting eight accounts where three still own comics deletes five. A failure
does not stop the run; failures are collected and reported by name:

> **5 of 8 users deleted** — Carol: This user still owns comics; Dave: …

Three failures are named before the summary stops listing them and counts the
rest. The list reloads either way, including after a partial run, because some
of the rows on screen no longer describe reality and working out which is
exactly what an administrator cannot be expected to do.

Rows an action is known to refuse are filtered out before the run rather than
sent and reported — your own account for Warn and Delete, a share already
revoked for Revoke. The bar says so above the buttons:

> Delete selected: 3 of 4 eligible — your own account is never included

## Warning several at once

One message, written once, sent to each recipient in turn through the same
endpoint a single warning uses. See
[administrator-notices.md](administrator-notices.md) for what a notice is and
who it reaches — warning three comics reaches their owners, which may be one
account or three.

The dialog closes once anything has been sent, since a warning cannot be
unsent and reopening the same list would send it twice. If nothing was sent at
all it stays open with the message intact.

## Where the pieces are

| File | Holds |
| --- | --- |
| `lib/row-selection.js` | range, intersection, header tick-box state |
| `hooks/use-row-selection.js` | the selection itself, keyed to the list it was made against |
| `components/SelectionCheckbox.jsx` | the tick box that carries the shift key |
| `lib/bulk-actions.js` | the sequential runner and the phrasing of its result |
| `hooks/use-admin-bulk-action.js` | run, report, reload |
| `components/admin/AdminBulkActionsBar.jsx` | the bar |

The library's own table (`ComicTableView`) shares the first three. Its bulk
operations are different — tag, move, share, and a real batch delete endpoint
scoped to the owner — but the tick boxes underneath are the same code, which is
why shift-click behaves identically in both places.
