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

An administrator's `COMIC_VIEW` is what lets the **Comics** tab open any comic in
the reader, so a report about a specific comic can be checked rather than acted
on blind. The tab labels a comic marked 18+ beside its title for the same
reason: whether a comic is classified correctly should not require opening the
edit dialog on every row.

Individual share grants are listed and revoked on the **Shares** tab — see
[revoking one grant](#revoking-one-grant) below.

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

## Revoking one grant

The admin **Shares** tab lists share grants rather than the codes some of them
came from. The distinction matters: the sharing-codes table can stop a code
being redeemed again, but it cannot see a share made by emailed invitation, and
it cannot take back access that has already been granted. Both of those are what
a report about one comic reaching one person actually asks about.

`POST /api/admin/shares/{id}/revoke` performs the same operation the owner
performs from their own Sharing page: the recipient loses access, the owner
keeps their comic, and nothing is deleted. It is idempotent — revoking an
already-revoked share is the state the caller wanted, so a double click or a
stale table answers the same way rather than erroring.

Removing the comic itself is a heavier decision and stays on the
[content report](content-reporting.md) screen. Telling the sharer why is a
[notice](administrator-notices.md), and the same row offers both.

The admin view of a share is deliberately not the owner's view of it. The 18+
redaction that view applies is for a *recipient's* benefit, and an administrator
checking what adult material is moving between accounts is exactly the person
who has to see the title and the flag. Both parties are named, because "who gave
this to whom?" is the only question the table exists to answer.

## Reading a sharing code back

A `C-` or `G-` code is compared by hash and nothing else, so a code cannot be
redeemed by reading it out of the database. Alongside that hash the code is also
kept encrypted with `APP_DATA_KEY` — the same key that protects Dropbox tokens,
see [the application data key](application-data-key.md) — so its owner can ask
for it again.

That exists for one case: a code pasted into a conversation and then lost. The
alternative was withdrawing a live code and minting another, which breaks it for
everybody who already had it.

The rules around it:

- **Only the owner.** `GET /api/shares/content-codes/{id}/reveal` answers for
  the account that issued the code and reports somebody else's as missing rather
  than forbidden. An administrator cannot read a code; support acting on a
  report needs to *stop* one, which they can.
- **One code per request, and charged for.** Revealing spends the same allowance
  as issuing, so an account cannot walk its own list and come away holding every
  live capability on it in a single response — and neither can a session
  somebody else has taken over.
- **Listing never reveals.** `GET /api/shares/content-codes` carries a
  `canReveal` boolean and never a code.
- **Dead codes are still readable.** "Which one was that?" is a question about a
  withdrawn or expired code as much as a live one, and answering it hands over
  nothing redeemable.
- **Every reveal is audited** as `SHARE_CLAIM_CODE_REVEALED` — identifiers only,
  never the code. That is the question worth answering after an account is taken
  over: which capabilities did the intruder walk away holding?

Codes issued before this column existed have nothing stored. `canReveal` is
false for them and the endpoint says so rather than returning an empty string.
A key rotated without a re-encrypt pass reads the same way: the code still works
for anybody holding it, so it is a display failure and says so.
