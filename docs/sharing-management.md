# Managing a large sharing history

The owner-facing **Shared by me** tab is a server-paged table. One row is one
durable grant: a comic shared with one recipient. That is also the unit the
owner can resend, revoke, or remove from their history, so page sizes and bulk
selection remain truthful when one comic has many recipients.

The recipient-facing **Shared with me** tab remains card-based. Invitations,
age confirmation, collection access, and unavailable-comic explanations are
decisions about content arriving for the reader, not rows in an owner
management queue.

## Table controls

`GET /api/shares/shared-by-me` accepts the same bounded `page`, `limit`,
`search`, `sort`, and `direction` contract as the administrative tables. The
owner repository applies every control before pagination; sorting only the 25
rows already in the browser would give a convincing but incorrect order for a
large account.

The supported sorts are comic title, recipient, status, and creation date. The
column filters are:

| Query parameter | Matches |
|---|---|
| `filterComic` | comic title or author |
| `filterRecipient` | current username/name or U-code, saved alias, or an address the owner already knew |
| `filterStatus` | Accepted, Pending, Declined, or Revoked |
| `filterCreatedAt` | an inclusive local-calendar date range |

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

Two confirmed bulk actions are available:

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

## Responsive behaviour

Search and bulk controls stack on narrow screens. The data table remains a real
table and scrolls horizontally within its bordered region, keeping selection,
column headings, and row actions aligned instead of collapsing grants into the
old card layout.

## Main implementation files

| File | Responsibility |
|---|---|
| `backend/src/Repository/ComicShareRepository.php` | owner-scoped search, sort, filters, count, and page query |
| `backend/src/Controller/ShareController.php` | validates query controls and serializes owner rows |
| `frontend/src/hooks/use-sharing.jsx` | debounced search, paging, loading identity, and reloads |
| `frontend/src/components/share/SharedByMeList.jsx` | table, row actions, selection, and bulk confirmations |
