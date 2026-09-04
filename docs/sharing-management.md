# Managing a large sharing history

Both **Shared with me** and **Shared by me** are server-paged management tables.
One row is one durable grant between a comic and a recipient. The two tables
share their layout, search, sortable/filterable columns, current-page selection,
pagination, and responsive horizontal scrolling while keeping the actions
appropriate to the side of the relationship being viewed.

## Table controls

`GET /api/shares/shared-by-me` and `GET /api/shares/shared-with-me` accept the
same bounded `page`, `limit`, `search`, `sort`, and `direction` contract as the
administrative tables. The repository applies every control before pagination;
sorting only the 25 rows already in the browser would give a convincing but
incorrect order for a large account.

The supported sorts are comic title, recipient, status, and creation date. The
column filters are:

| Query parameter | Matches |
|---|---|
| `filterComic` | comic title or author |
| `filterRecipient` | current username/name or U-code, saved alias, or an address the owner already knew |
| `filterStatus` | Accepted, Pending, Declined, or Revoked |
| `filterCreatedAt` | an inclusive local-calendar date range |

The recipient table substitutes `filterOwner` for `filterRecipient`, matching
the sharer's current name, username or email and the durable owner-name snapshot
kept for unavailable history. Its global search covers those owner fields plus
comic title and author. Comic and owner sorting also fall back to snapshots when
the original comic or account no longer exists.

The global search covers comic title/author and the same recipient identity
fields. A U-code match is limited to relationships whose address was already
hidden behind a code, so search cannot turn a known email share into a code
lookup. The browser sends `filterTimezone` so a date filter follows the dates
shown on screen across timezone and daylight-saving boundaries.

The endpoint never turns this into a user directory. It starts with
`s.owner = :owner`, and recipient fields are searched only inside relationships
that account already created. Addresses hidden by username or a `U-` code stay
hidden in `ComicShareSerializer::forOwner()`.

List responses are committed only while both the signed-in account and the
requested table URL still match. A request revision orders overlapping reloads,
so an action started on one page cannot overwrite a newer search, sort, filter,
or page after its response arrives late. Stale responses also skip their error
state and summary refresh.

## Selection and bulk actions

The header checkbox selects the current page. Shift-click extends a range, and
changing the page, query, filter, sort, or loaded result retires the selection.
Nothing off screen can be acted on accidentally.

Two confirmed bulk actions are available on **Shared by me**:

- **Revoke selected** acts only on pending or accepted grants. Recipients lose
  access immediately; the owner's comics and files are untouched.
- **Delete records** acts only on revoked, declined, or expired grants. It
  clears history and cannot be used as a quieter form of revocation.

Mixed selections are expected. The action bar states how many selected rows
are eligible and skips the rest. Requests run sequentially through the same
single-row endpoints used by each row, preserving their authorization, audit,
and lifecycle rules. Partial failures are reported by row, followed by a fresh
server read.

Each live row also retains **Stop sharing this comic with everyone** for the
owner who wants to withdraw every grant on that comic in one confirmed action.

On **Shared with me**, the confirmed bulk action removes selected unavailable
entries from the recipient's history and skips every live grant. Row actions
retain age confirmation, accepting or declining invitations, reading, removing
or restoring a comic in the collection, and clearing one unavailable entry.
Pending folder snapshots remain one decision in the table even though the
underlying grants stay independently revocable by the owner.

## Responsive behaviour

Search and bulk controls stack on narrow screens. Both data tables remain real
tables and scroll horizontally within their bordered regions, keeping
selection, column headings, and row actions aligned.

## Main implementation files

| File | Responsibility |
|---|---|
| `backend/src/Repository/ComicShareRepository.php` | owner- and recipient-scoped search, sort, filters, counts, and page queries |
| `backend/src/Controller/ShareController.php` | validates query controls and serializes both table views |
| `frontend/src/hooks/use-sharing.jsx` | independent debounced search, paging, loading identity, and reloads for both tables |
| `frontend/src/components/share/SharedByMeList.jsx` | owner table, row actions, selection, and bulk confirmations |
| `frontend/src/components/share/SharedWithMeList.jsx` | recipient table, invitation and collection actions, selection, and history cleanup |
