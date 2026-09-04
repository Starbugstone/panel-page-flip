# Sorting and filtering the admin tables

Every admin table heading carries a dropdown: sort ascending or descending, and
a filter control suited to that column. Columns with a defined set of values,
such as status, role, scope and verification, offer those values in a select
instead of asking the administrator to type them. The trigger lights up when
the column is the active sort or is carrying a filter, so a table narrowed
three columns ago says so without anything being opened.

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

Text and range filters are applied on **Apply filter**, not per keystroke. For
the server-paged tables, a free-text box starts asking
`/api/admin/table-filter-suggestions/{table}/{column}` for suggestions once its
trimmed value reaches three characters. The lookup runs when the input changes (including paste and clear), searches the
full database with a case-insensitive substring match, returns at most six
distinct values, and ranks prefix matches first. Each input change aborts the previous
request so a slow answer for an older value cannot replace the current list.
Choosing a suggestion, or a value from a fixed-value select, applies it in one
click. The small client-side tables continue to derive suggestions from the
complete result set they already hold.

The suggestion endpoint accepts only its internal table/column allow-list and
requires an administrator. Relationship joins use their foreign-key indexes. Text matching uses escaped
`LIKE` substrings, including the JSON audit payload. A full-text index cannot
accelerate these predicates, so there is no duplicate stored audit payload or
full-text index to maintain. Migration `Version20260904214000` removes those
derived objects while preserving every original JSON audit record. Audit
inserts no longer reload a generated copy of their payload.

## What a column filter matches

| Kind of column | Filter control and match |
| --- | --- |
| Text (owner, title, details) | any part of it, case-insensitively |
| Date | an inclusive start/end range, with either edge optional |
| Number (pages, comics, storage) | an inclusive minimum/maximum range |
| Fixed set (status, role, scope, verified) | select one of the labels the column defines |

Two rules keep a filter honest. Text is escaped before it reaches `LIKE`, so
`50%` finds "50% Off" rather than every row — see
`Service\Pagination\LikePattern`. And a fixed-set column given a word none of
its labels contain excludes every row: dropping the filter instead would answer
a search for "nonsense" with the unfiltered table, reading as though every row
had matched.

Date filters open an application-styled calendar with explicit From and
Through boundaries, month navigation, and quick ranges. They do not fall back
to browser-native date inputs or ask an administrator to type date syntax. The
picker sends ranges as `YYYY-MM-DD..YYYY-MM-DD`. A missing first or last date
is an open range, and a legacy single `YYYY-MM-DD` URL continues to mean that
whole day. Server-paged lists also send the browser's IANA timezone, so the
range follows the local dates rendered in the cells even across a daylight-
saving transition. Malformed dates or timezone names are ignored rather than
rejected.

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
  `tableControls.headerProps` for the wiring every header shares. Date columns
  set `filterType="date"`; finite-value columns set `filterType="select"` and
  pass their labels through `filterOptions`. A server-paged text column adds an
  allow-listed `suggestionSource`; a client-side text column passes
  `filterSuggestions`, built with `adminFilterSuggestions` from its complete
  result set.

## Related

- [admin-bulk-actions.md](admin-bulk-actions.md) — acting on the rows a filter
  leaves
