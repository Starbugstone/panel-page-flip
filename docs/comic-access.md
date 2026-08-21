# Who may reach a comic

Every endpoint that takes a comic id — metadata, cover, page, manifest,
archive, reading position, provider lookup — settles access the same way, in
one place. `App\Security\ComicAccess` loads the comic and authorises it as a
single step, so there is no point in between where a request holds a comic it
has not been cleared for.

## The rights

`App\Security\Voter\ComicVoter` is the only thing that decides these.

| Right | Who has it |
| --- | --- |
| `COMIC_VIEW` | The owner, an administrator, or a recipient whose share is accepted. Withdrawn while a comic is quarantined or sharing-restricted, and withheld from a recipient who has not answered the 18+ gate on an explicit comic. |
| `COMIC_EDIT`, `COMIC_DELETE` | The owner or an administrator. Kept while a comic is quarantined, so the owner can still clear it up. |
| `COMIC_SHARE` | The owner alone, and only while neither they nor the comic is sharing-restricted or quarantined. Administrators are not included: moderating a comic is not a reason to hand out access to it on somebody else's behalf. |
| `COMIC_KNOW` | The owner, an administrator, or anybody holding a share of any status — including pending, declined and revoked. Not a permission; see below. |

Downloading the archive is owner-only and is not an administrator's to take.
Moderating a library is not a reason to keep a copy of somebody's files.

## Why a refusal is sometimes a 404

Comic ids are small sequential integers. If a signed-in stranger could tell
"this comic is not yours" apart from "there is no such comic", they could walk
the id space and map out other people's libraries — how many comics each
account has, and which ids belong to whom. Nothing about that requires reading
a single page.

So refusals come in two shapes, and which one is used is itself a disclosure
decision:

- **404, "Comic not found"** — for anybody without `COMIC_KNOW`. Byte for byte
  the same answer as an id that was never issued.
- **403** — for anybody who has `COMIC_KNOW` but not the right they asked for.

`COMIC_KNOW` rather than `COMIC_VIEW` draws that line, because reading is
withdrawn from plenty of people who are perfectly aware of the comic: an owner
whose comic has been quarantined, a recipient who has not answered the 18+
gate, one whose access was revoked, one who has been invited but has not
accepted yet. Answering any of them with "no such comic" would hide nothing
from anybody and would turn a refusal they can act on into what looks like a
broken link.

Both refusals are raised as exceptions and rendered by
`App\EventSubscriber\ApiExceptionSubscriber`, so no single endpoint can decide
to be more forthcoming than the rest.

A denial that resolves to a 404 is logged, because the response will not be:
reaching a real comic with no standing at all is either probing or a
misconfiguration, and a 404 gives `AccessDeniedLogSubscriber` nothing to count.

## Listing

Listing is a separate path with the same boundary.
`App\Service\ComicLibraryQueryService` is the server-authoritative definition
of one viewer's library, and every consumer — the dashboard, folders, bulk
actions — goes through it, so a folder id can never become an alternate way to
reach a comic the viewer cannot currently access.

## Tests

- `tests/Functional/Security/ComicEnumerationTest.php` walks every comic
  endpoint and asserts a stranger cannot tell a real comic from an invented
  one.
- `tests/Unit/Security/ComicAccessTest.php` pins the choice between the two
  refusals directly.
- `tests/Functional/Security/AdminSurfaceIsClosedTest.php` derives the
  administrative routes from the router and asserts each one refuses an
  ordinary account.
