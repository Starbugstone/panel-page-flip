# Sorting and filtering the admin tables

Every admin table heading carries a dropdown: sort ascending or descending, and
a box to filter that column by. The trigger lights up when the column is the
active sort or is carrying a filter, so a table narrowed three columns ago says
so without anything being opened.

One column sorts at a time — a second choice replaces the first. Filters
accumulate: each column narrows what the ones before it left.

## Where the work happens

| Table | Sorted and filtered by |
| --- | --- |
| Users, Comics, Tags, Shares, Sharing codes, Audit log | the database |
| Overview sign-ups, Dropbox, Reports | the browser |

The split follows the endpoint, not the table. The first six are paged by the
server, so sorting them in the browser would only order the twenty-five rows
already fetched and quietly lie about the rest — the sort and the filters go
into the request instead. The last three return their whole result set (the
report queue is bounded, the other two are short by construction), so
`lib/admin-client-table.js` sorts and filters the rows in place.

A server-side filter is a new result set, so `useAdminList` resets to page 1
when one changes. Landing on page 3 of a list that now has one page shows an
empty table.

Filters are applied on **Apply filter**, not per keystroke: these lists are
server-paged, and typing five characters should not run five queries.

## What a column filter matches

| Kind of column | What to type |
| --- | --- |
| Text (owner, title, action, details) | any part of it, case-insensitively |
| Date | `YYYY-MM-DD`; matches the whole of that day |
| Count (pages, comics, storage) | the number as the cell shows it |
| Fixed set (status, scope, verified) | any part of a label the column shows |

Two rules keep a filter honest. Text is escaped before it reaches `LIKE`, so
`50%` finds "50% Off" rather than every row — see
`Service\Pagination\LikePattern`. And a fixed-set column given a word none of
its labels contain excludes every row: dropping the filter instead would answer
a search for "nonsense" with the unfiltered table, reading as though every row
had matched.

Anything half-typed is ignored rather than rejected. A date being entered a
character at a time should show the list it is narrowing, not an error.

## Adding a column

- **Server-side.** Add the query alias and its DQL expression to that
  repository's `ADMIN_SORT_FIELDS`. `PaginationRequest` only accepts a `sort`
  that is a key of the map and falls back to the default otherwise, which is
  what makes interpolating it into DQL safe — nothing else may reach an
  `ORDER BY`. Read the filter in the controller as `filter<Column>` and handle
  it in the repository through `Service\Pagination\ColumnFilter`, which holds
  the shared reading of a typed-in value: blank means no filter, text becomes an
  escaped pattern, a date becomes a day, a label resolves to the value behind
  it.
- **Client-side.** Add an entry to the `columns` map passed to
  `filterAndSortAdminRows`: a `value` for the row, and a `filter` only where
  matching the cell needs more than a substring of it.
- **Either way.** Render the heading with `AdminColumnHeader`, spreading
  `tableControls.headerProps` for the wiring every header shares.

## Related

- [admin-bulk-actions.md](admin-bulk-actions.md) — acting on the rows a filter
  leaves
