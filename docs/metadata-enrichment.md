# Metadata enrichment

Roadmap and working notes for issue #74 (`LIBRARY - P0`): reading structured
metadata out of comics, filling the gaps from providers, and letting the user
decide what actually gets applied.

This is written to be picked up cold. Each slice below is a separate PR, and
there may be weeks between them, so every slice states what it depends on, what
it must not do, and how you know it is finished.

**Status:** the four original slices are the **foundation**, not the whole of
#74. They shipped as PRs #106-#109, with #112-#115 fixing what real use found.
The **close-out** — the provider account model, the tag/classification workflow,
provenance and refresh, ranking, and quota handling — is the section further
down, [Close-out](#close-out-p0-a-to-p0-f). Where the shipped code deviates from
the original plan, the plan is annotated rather than rewritten, so the reasoning
stays visible.

Read the close-out section before touching this area. The four slices below
describe how the foundation was built; they no longer describe how it behaves.

Numeric ComicInfo values are validated as decimal strings before conversion.
Volume, count and page dimensions are capped at the signed database integer
maximum (2,147,483,647); page image indexes must be below the parser's 20,000-page
limit. Leading zeroes remain valid. Invalid or oversized fields are ignored
without losing the remaining title, creators or valid page information.

## Why this comes before the reader work

The obvious reading of #74 is that it is a library feature: series, issue,
publisher, creators. That is true, but it is not the reason it is scheduled
ahead of #86 and #89.

`ComicInfo.xml` carries **per-page** metadata, and that is exactly what the
reader issues need in order to stop guessing:

| ComicInfo | Consumer |
| --- | --- |
| `<Manga>` = `YesAndRightToLeft` | #86 right-to-left reading direction |
| `<Page DoublePage="true">` | #86 wide-page detection, without decoding images to measure them |
| `<Page Type="FrontCover">` | #86 cover-alone / first-page-alone behaviour |
| `<Page ImageWidth>` / `<ImageHeight>` | #89 page dimensions |
| `<AgeRating>` | the existing `Comic::$explicitContent` flag |
| `<Series>` `<Number>` `<Volume>` `<Count>` | #91 series-aware Next Issue |

Issue #86 says it does not hard-depend on #89 "if this feature consumes an
abstract page-info contract that #89 can later provide". Slice 1 below defines
that contract and makes ComicInfo its first provider. #89 later becomes a second
provider of the same contract for comics that have no ComicInfo.

**Consequence for sequencing:** slice 1 is the only slice the reader work needs.
Slices 2-4 are library/UX value and can land at any later point without
blocking #86 or #89.

## Historical starting state (superseded)

The points below were verified against `main` at the merge of PR #103 and PR
#104. They describe the pre-implementation baseline, not the current code:

- There is **no** ComicInfo handling anywhere — `grep -ril comicinfo` over
  `backend/src`, `backend/tests` and `frontend/src` returns nothing.
- `Comic` has only flat metadata: `title`, `author`, `publisher`, `description`,
  plus `sourceType`, `pageCount`, `fileSize`, `explicitContent` and tags. No
  series, volume, issue, publication date, structured creators, language or
  reading direction. See `backend/src/Entity/Comic.php`.
- There is **no XML parsing anywhere in the codebase**. Slice 1 introduces the
  first. See the XXE note in that slice.
- `ComicPageProviderInterface` is `supports` / `inspect` / `readPage`. There is
  no way to read a *named* entry out of a source, which is what ComicInfo needs.

Useful precedents to copy rather than reinvent:

- **Per-format work behind a factory:** `App\ComicSource\ComicPageProviderFactory`
  and the providers beside it (PR #103).
- **A single-row admin configuration entity:** `App\Entity\ComicFormatConfiguration`
  — assigned id `1`, seeded by its migration, read through a service that caches
  it. This is the model for provider API keys in slice 3.
- **An outbound HTTP client:** `App\Service\DropboxClientFactory` and
  `App\Service\DropboxImportService` use `HttpClientInterface`. This is the model
  for the Metron / Comic Vine clients in slice 3.
- **A feature doc per major feature:** `docs/comic-formats.md`, `docs/reader.md`.

## The rule that governs all four slices

From the issue, and it is not negotiable:

```text
ComicInfo.xml → filename parsing → provider search → user review → explicit apply
```

Provider data **never** silently overwrites user metadata or categorisation.
Provider tags stay suggestions until the user explicitly applies them. Every
slice below has to preserve this, which is why the review/apply UI is its own
slice rather than being smuggled into the provider slice.

### Which sources need accepting, and which do not

The pipeline above is one line and reads as though everything waits for a
person. It does not, and the difference is worth stating because two slices
could otherwise be built to contradict each other:

| Source | When it lands | Why |
| --- | --- | --- |
| `ComicInfo.xml` | **Persisted at import**, no acceptance step | It is the file's own statement about itself, arriving with the file. Nothing is being guessed and there is no second opinion to weigh — the same standing as the page count, which is also read at import and never confirmed. |
| Filename | **Suggestion only** | Inferred from a naming convention that the file never promised to follow. |
| Provider | **Suggestion only** | Somebody else's record of what this comic might be. |
| The user | Authoritative | They were looking at the comic. |

So "nothing reaches the database without an explicit action" in slice 4 is
about **suggestions**, not about everything. What ComicInfo says still never
overwrites a value the uploader typed — see the field rules in slice 1.

---

## Slice 1 — Structured metadata model and ComicInfo.xml ingestion

**Unblocks #86 and #89. No network. Fully unit-testable. Do this one first.**

### Scope

1. **A named-entry seam on the source providers.** ComicInfo.xml lives inside the
   archive, and nothing can read it today. Add a capability alongside
   `ComicPageProviderInterface` — a separate interface is preferable to widening
   the existing one, because PDF cannot implement it meaningfully:
   - CBZ: enumerate entries and read the match by index, not by name.
   - CBR/CB7/CBT: `7z x -so -spd -- <archive> <entry>`, matching the existing
     call's argument shape exactly (`--` and `-spd` are load-bearing; see
     `SevenZipPageProvider::readPage`). The entry name comes from the listing,
     so it is never a pattern.
   - PDF: has no ComicInfo.xml, so it implements nothing here. Contributing what
     its Info dictionary offers was planned and **deliberately dropped** — that
     is title and author at best, which the upload form already collects, and it
     would put a special case into an otherwise uniform design. Revisit only if
     a real PDF turns up whose Info dictionary carries something worth having.

   Locating it has to be **bounded and deterministic**, because an archive is
   untrusted input and "whichever one we happened to hit first" is not an
   answer that survives a re-read:

   - Match `ComicInfo.xml` **case-insensitively** and **only at the archive
     root**. Real files use both spellings; a nested one describes a
     subdirectory, not the comic.
   - **First match wins** when an archive carries the name twice, so the result
     cannot depend on read order.
   - Bound it with a dedicated `MAX_METADATA_BYTES`, separate from the page
     limits. Check the declared size *before* reading where the format exposes
     one, and cap the read regardless — a declaration is the archive's own claim
     about itself. On the 7z path, read incrementally and stop at the cap rather
     than buffering the entry and measuring it afterwards.

2. **A parsed, validated representation.** A value object, not an array. Unknown
   fields are dropped rather than carried; out-of-range values fall back per
   field the way `App\Reader\ReaderPreferences::normalize()` does, so one bad
   value cannot discard a whole file's metadata.

3. **Persistence.** New columns/table for series, volume, issue number, issue
   count, publication date, language, reading direction, and structured
   creators. Per-page metadata (`type`, `doublePage`, `width`, `height`) is
   keyed by page number.

   **How a ComicInfo page becomes a reader page.** Get this wrong and
   double-page flags attach to the wrong pages, which is a silent wrong answer
   rather than a visible failure:

   - `<Page Image="N">` is **authoritative**. Document order is not — entries
     are sorted by page, so a file listing them out of order still maps
     correctly.
   - `Image` counts from **zero**; the readers count from **one**. Convert in
     exactly one place.
   - It indexes the comic's page sequence, which is the natural-sorted list of
     image entries the page providers build (`strnatcasecmp`) — the same order
     the stored page count came from.
   - **Duplicates:** first entry claiming a page wins.
   - **Missing:** a page with no entry simply has no metadata; consumers must
     cope with a partial map rather than assuming one entry per page.
   - **Out of range:** an entry past the page count is stored but never looked
     up, since consumers ask by page number. It is not worth a rejection.

4. **The page-info contract.** A read model the reader can consume that answers
   "is page N a double-page", "what are its dimensions", "is it the cover", and
   "what direction does this comic read". ComicInfo populates it; #89 later
   populates it for comics without ComicInfo. **Define this deliberately — it is
   the actual deliverable for #86 and the hardest thing to change later.**

5. **Ingestion at import.** All four entry points funnel through
   `ComicService::uploadComic()` (`ComicUploadController` for direct and chunked
   uploads, `ImportComicsCommand`, and `DropboxImportService`), so there is
   exactly one place to hook. A comic whose
   ComicInfo is missing or unparseable must import exactly as it does today.

6. **Serialization.** Extend `ComicSerializer` so the frontend can see the new
   fields. Additive only — nothing existing changes shape.

### Security: XXE

This introduces the first XML parsing in the codebase, on a file that came out
of an untrusted upload. Before writing the parser:

- disable external entity loading and DTD processing outright;
- prefer a parser configured to reject DTDs rather than one that merely ignores
  them;
- bound the document size before parsing, the way `PdfDocument` bounds inflate
  output (`MAX_STREAM_BYTES`);
- treat every field as untrusted for length and type; a `<Series>` of 10 MB is a
  valid XML string.

A billion-laughs or external-entity test belongs in this PR, not a later one.

### Explicitly not in this slice

No filename parsing. No provider calls. No UI for reviewing anything — ComicInfo
is the file's own statement about itself, so applying it at import is not the
"provider data" the review flow exists to gate. Reading direction and page
metadata are *suggestions to the reader*, which stays authoritative over its own
persisted preference (see `docs/reader.md`).

### Done when

- A CBZ, CB7 and CBT with ComicInfo.xml all import with structured metadata.
- A CBR does too, on a host whose 7z has RAR support.
- A PDF imports without embedded ComicInfo metadata; it follows the same
  no-ComicInfo path as any source with no usable `ComicInfo.xml`.
- A comic with no ComicInfo, a malformed one, and one with a hostile DTD all
  import exactly as they do today.
- The page-info contract is queryable for a comic and covers direction,
  double-page flags, cover position and dimensions.
- `php bin/phpunit`, `npm test`, `npm run lint`, `npm run build` all pass.

---

## Slice 2 — Filename parsing fallback

**Depends on slice 1's value object. No network. Small.**

Most comics have no ComicInfo.xml. `Batman - 001 (2011) (Digital).cbz` still
carries series, issue and year.

- A pure function: filename in, candidate metadata out. No I/O, no entity access,
  so it can be table-tested against a corpus of real-world naming patterns.
- Produces **suggestions**, never applied automatically. This is the first point
  in the pipeline where the data is inferred rather than declared, and the
  distinction has to be visible in the model from here on — a field needs to know
  whether it came from ComicInfo, a filename guess, a provider, or the user.
- Ranked below ComicInfo: where both speak, ComicInfo wins.

### Done when

Known naming conventions parse correctly, ambiguous names produce no confident
guess rather than a wrong one, and nothing is written without a user action.

---

## Slice 3 — Provider clients (Metron, Comic Vine)

**Depends on slice 1's model and slice 2's provenance concept. First slice that
talks to the network.**

- One interface, two implementations, selected through a factory — the same
  shape as `ComicPageProviderFactory`.
- API keys are admin configuration. Follow `ComicFormatConfiguration`: single
  row, assigned id, seeded by migration, read through a caching service. Keys are
  secrets — they must not reach the frontend, appear in logs, or land in the
  personal-data export.
- Both APIs rate-limit. Respect their documented limits, cache responses keyed by
  the query, and fail soft: a provider being down or unconfigured must never
  block an upload or break the library.
- Admin visibility, mirroring what `docs/comic-formats.md` does for formats:
  which providers are configured, which are reachable, what to do about it.
- **Search only.** This slice returns candidates. It applies nothing.

### Done when

A configured provider returns ranked candidates for a comic; an unconfigured or
unreachable one degrades silently; no key is exposed anywhere; and rate limits
are respected under a bulk run.

---

## Slice 4 — Review and apply

**Depends on 1-3. The half of the issue that makes the rest safe.**

- A UI showing current value vs suggested value per field, with provenance, and
  per-field accept/reject. `frontend/src/components/ComicEditDialog.jsx` is the
  natural home or the model to extend.
- Applying writes through the existing `PUT/PATCH /api/comics/{id}`
  (`ComicController::update`, line ~660) rather than a parallel write path.
- Tags from providers are proposed in a way that is clearly distinct from the
  user's own tags, and are only created on explicit acceptance. This is the
  specific failure the issue calls out: do not dump structured metadata into the
  tag system.
- Bulk apply across a selection is desirable but should reuse the same
  per-field gate rather than introducing an "apply everything" path.

### Done when

A user can see exactly what would change and where each value came from, accept
some fields and reject others, and nothing reaches the database without an
explicit action.

---

## How the pre-implementation questions were settled

Kept for the reasoning rather than the decision, since each of these is the kind
of thing that gets re-litigated later.

1. **Per-page metadata storage** — a JSON column on `comic`. Every consumer reads
   the whole set at once and none filters on it. The queryable scalars (`series`,
   `issue_number`, …) got columns, with an `(owner_id, series)` index for #91.
2. **Provenance granularity** — per field, through `MetadataSource`, ranked
   user > comicinfo > provider > filename.
3. **Re-ingestion** — not built. ComicInfo is read once at import. The original
   filename *is* stored, so filename suggestions are derived on demand and
   improve with the parser; ComicInfo does not get that treatment yet.
4. **Reading direction precedence** — ComicInfo seeds a per-comic default; the
   reader's own persisted settings stay authoritative. Unchanged from the plan.

---

## Close-out (P0-A to P0-F)

What the four foundation slices left open, and how each was settled. P1 bulk
enrichment is **not** built and is deliberately a follow-up — the issue says so,
and getting the manual path right first is what makes a bulk path safe.

### The provider account model

Metron moved from an account **username and password** to a revocable **bearer
token**. The old columns are dropped rather than migrated: they held a real
account password, which the new flow never asks for and must not keep. An
installation that had Metron configured re-enters a token, which is the intended
outcome of the migration rather than a casualty of it.

Credentials now come from two places, and `MetadataAccessResolver` is the single
point that decides between them:

| Source | Who pays | Notes |
| --- | --- | --- |
| A user's own token | Them | Answers on its own, for **both** providers. None of the shared switches apply. |
| The installation's shared token | Everybody | Gated on the full conjunction below. |

**A personal token answers first and answers alone.** It spends its owner's
allowance rather than anybody else's, so the switches governing the shared
account have nothing to say about it — those exist to control who may spend the
installation's credential, and a personal token is not one. Metron and Comic Vine
behave identically here; an earlier cut gated Comic Vine's personal key behind
the shared switch, and the asymmetry was the surprising part rather than the
protection.

Whether users may bring a token at all is itself an administrator's switch —
`personalCredentialsEnabled`, on by default. Turning it off makes the resolver
ignore stored tokens and **fall back to the shared credential**; it does not stop
the lookup and does not delete anything. A user whose token has stopped being
used can still see that it is stored and still remove it, which is why clearing
a token stays permitted while setting one is refused.

Shared access is:

```text
environment allows it        (METRON_SHARED_ENABLED / COMIC_VINE_SHARED_ENABLED)
AND an administrator enabled it
AND a token is configured
AND the circuit breaker is not holding the account off
```

The environment flag is checked **first**, and an operator who turned a provider
off must not be overrulable from inside the application — that is the whole point
of putting a switch outside it.

**The two defaults differ, deliberately:**

- **Shared Metron is off unless set.** It spends a token the installation owns on
  behalf of every user, so it is opted into rather than inherited from a shipped
  default. A personal Metron token is unaffected by it.
- **Comic Vine is on by default.** This is self-hosted software and its ordinary
  deployment is somebody's own library, which is squarely inside Comic Vine's
  non-commercial terms. Shipping it disabled would make every operator hunt for a
  switch to get behaviour they were already entitled to. A deployment that leaves
  those terms turns it off — in the environment, or in one click in the admin
  panel.

Both switches govern the credential the **installation** owns. Neither reaches a
user's own token — see the personal-token rule above, which applies to Comic Vine
exactly as it does to Metron.

**Per-user access.** `User::$metadataApiEnabled` lets an administrator withdraw
external lookups from one account, from the admin user page. Local sources —
ComicInfo.xml and the filename parser — are unaffected, because neither leaves
the server, so a withdrawn account keeps every suggestion that does not cost
anybody anything.

### The circuit breaker is a pause, never a setting

`ProviderCircuitBreaker` holds an account off after a refused credential, a 429,
or a run of failures. It is cache-backed and expires on its own. It deliberately
never changes the administrator's switch: if a burst of timeouts flipped a
setting off, somebody would have to notice and turn it back on.

`Retry-After` wins over anything computed locally — the provider knows its own
quota and we are guessing. The pause is keyed by a **hash of the credential**, so
one user's exhausted personal token cannot pause the shared one, and two people
who pasted the same token correctly share one pause.

### One provider per lookup

`MetadataProviderRegistry::search()` used to ask every configured provider. That
spends two accounts on one click, and cascading to the second after the first
fails spends somebody else's allowance to paper over an outage. It now picks
**one** provider — a personal credential first, then anything else allowed, in
registration order — and reports the others as unasked.

### What a user is told, and what stays the operator's

The resolver's answer is operator information: it names which account would be
spent and exactly why a shared credential was refused. A normal user gets a
deliberately reduced view — `PublicProviderStatus` — of whether a provider will
answer *them*, plus a reason only when the reason is theirs to act on:

| Situation | What the user sees |
| --- | --- |
| Their own token was refused | "Metron rejected your token. Check it in your settings." |
| Their account has lookups withdrawn | "External metadata lookups are turned off for your account." |
| Anything about the shared credential | "Metron is currently unavailable." |

The last row collapses *unconfigured*, *disabled*, *paused*, *rate limited*,
*unreachable* and *failed* into one sentence on purpose. Those differences
describe the installation's own account, and being able to tell them apart is
how somebody maps the server's configuration by reading error messages.

`origin` — which credential a call would spend — never leaves the backend. It is
what the quota and circuit-breaker keys are built from, and nothing more.
`ProviderSearchResult` and `ProviderLookup` serialise a deliberate minimum so a
route that forgets to reduce cannot leak the difference, and
`ProviderSecrecyTest` walks every user-facing endpoint with sentinel credentials
asserting neither the secret nor the operator markers appear.

One inference survives and cannot be removed: a user with no personal token
whose search works can conclude the server has some way of doing it. That is a
consequence of the feature existing. Confirming the credential's state on top of
it is not.

Internally, every result still carries a full `ProviderStatus`, so the code can
tell apart:

```text
ok + no candidates   nothing matched
unconfigured         nobody gave it a credential
disabled             an administrator, or the environment, turned it off
forbidden            this user may not spend allowance
unauthorized         the credential was refused
rate_limited         throttled, upstream or by our own ceiling
paused               held off after failures
unreachable/failed   the network, or an unusable answer
```

Returning the same empty array for all of these is safe and useless — for the
*code*. What reaches the user is the reduced view above.

### Provider failures are never logged verbatim

A transport exception routinely quotes the request URL, and Comic Vine puts its
API key in the query string. So a provider failure logs the exception **class**
and nothing out of the exception itself: the message would put the
installation's credential into a log file that gets shipped, rotated and read.
The class name is enough to tell a timeout from a DNS failure, and there is a
regression test that throws an exception carrying a sentinel key and asserts it
never reaches the log.

### Search runs off the staged form, not the saved comic

`POST /api/comics/{id}/metadata-candidates` takes an optional normalized query
from the edit form. Accepting a filename suggestion and searching immediately now
works; before, the flow was *accept → save → reopen → search*.

Those staged values are **search hints only**. What may be edited is decided by
the comic id and `ComicVoter`, exactly as everywhere else — a request body cannot
widen it.

### Ranking and confidence

`CandidateRanker` scores normalized series and issue number first, then uses year
as a tiebreak and as a contradiction:

| | |
| --- | --- |
| `exact` | series and issue both agree |
| `high` | series agrees, nothing contradicts |
| `ambiguous` | plausibly the same series, or the wrong issue of the right one |
| `low` | came back from the search and little else recommends it |

A year off by more than one downgrades a match, because relaunches and reprints
share a series name and an issue number and are exactly where this would
otherwise be confidently wrong. **Nothing is ever auto-applied on confidence** —
it is a label on a row a person still clicks, which is why a heuristic is fine.

### Detail, provenance and refresh

Metron's issue *list* carries no publisher, description or classification (this
is verified, not assumed). So a search returns rows, and picking one fetches
`/issue/{id}/` — one request for the record somebody chose, not one per row.

`Comic::$metadataProvider` / `$metadataExternalId` / `$metadataFetchedAt` record
which external record was accepted, so **Refresh metadata** asks for that exact
issue instead of re-running a fuzzy search and hoping. A refresh still produces
suggestions and never overwrites: the id makes the question cheap, not the answer
authoritative. The stored origin says which record was chosen — not that every
current field still comes from it, because the user edits them afterwards.

`explicitContent` is absent from every suggestion path and must stay absent. Age
rating is carried as information; the flag is the owner's declaration about their
own library.

### Classification and tag suggestions

`ComicInfoParser` now reads `Genre`, `Tags`, `Characters`, `Teams`, `Locations`
and `StoryArc` into a `Classification` value object, stored on
`Comic::$classification`. Providers map the same shape.

**Only genres are ever offered as tags.** Characters, teams, locations and story
arcs stay structured metadata. A single crossover names dozens of them, and a
large collection enriched that way would produce thousands of tags nobody chose —
which is the specific failure #74 exists to prevent.

Accepting a genre goes through the ordinary comic update, which reuses an
existing global or personal tag by name and otherwise creates a **personal** one.
A metadata import can never create a global tag. Nothing is preselected, the
section collapses once it is long, and the spelling of an existing tag wins over
the source's so accepting cannot make a near-duplicate.

### Quota

The static `150/hour` limiter is kept as a local abuse ceiling but is no longer
the only representation of quota. `ProviderQuotaTracker` records
`X-RateLimit-*` and `Retry-After` per **account**, because two users pasting the
same token share one upstream allowance and tracking them separately would show
each a full budget while the account ran out. A separate per-user limiter stops
one enthusiastic library spending the installation's whole hourly allowance.

A non-ok result is **never cached**. The old code cached an empty list for a day
on any failure, which turned a thirty-second outage into a comic that
permanently "has no match".

### Privacy

The policy now discloses what a metadata search sends: series, issue, year and
volume, to the chosen provider, only when the user asks for a search. It states
what is *not* sent — identity, email, file or Dropbox paths, reading history,
tags, the archive — and how a personal token is stored and removed. Provider
tokens are excluded from the personal-data export, because an export is a file
that leaves the server.

---

## Known not to work yet

Written down after configuring a real provider and editing a real comic. None of
these is a crash; they are the quiet kind, where something returns nothing and
looks like it simply found nothing.

### Bulk enrichment is not built

The issue's P1: a resumable queue offering **Enrich metadata** after a bulk
import, respecting quota, pausing on a kill switch, resuming without repeating,
and marking ambiguous items for review. Deliberately left out of the close-out —
the manual path had to be correct first, and bulk acceptance is where a wrong
tag decision multiplies by a thousand.

### Collected editions do not match a provider

Metron indexes **issues**. A trade paperback like `theboys_vol2_getsome.cbz`
produces the query `theboys getsome`, and `/api/issue/?series_name=…` has nothing
to say about it. The filename parse is now correct — series `theboys getsome`,
volume 2 — and the search still finds nothing, because it is asking the wrong
index.

Metron has a `/series/` endpoint that suits collections. Using it would mean
deciding, per comic, whether we are looking for an issue or a volume — plausibly
from whether the filename yielded an issue number or a volume. Still not
attempted; the staged-query work makes it easier, since the query now knows
whether a volume was supplied.

**How to see it:** any `Vol N` filename. **What works instead:** a single issue,
e.g. `Batman 001 (2011).cbz`.

### Metron candidates carry no publisher or description — resolved

Verified against the live API: the issue list returns `id, series, number, issue,
cover_date, store_date, image, cover_hash, modified` and nothing else. Both
fields live on `/issue/{id}/`.

Fetched on demand now. Picking a candidate calls
`POST /api/comics/{id}/metadata-record`, which reads `/issue/{id}/` for that one
record — a request for the record somebody chose, rather than one per row of
every search.

### Comic Vine's field mapping has never seen a live response

Metron's is now verified; Comic Vine's is not, because there was no key to hand.
`ComicVineProvider::candidates()` maps `volume.name`, `issue_number`, `name`,
`deck`, `cover_date` and `image.original_url` from the documented shape only, and
its **detail** mapping — `person_credits`, `character_credits`, `team_credits`,
`location_credits`, `story_arc_credits` — has seen no live response either.

Given Metron's list turned out to differ from its documentation in exactly this
way, **assume Comic Vine's does too until someone runs one real search.** The
probe used for Metron is worth repeating:
`.claude/skills/browser-test` has the stack, and a short script against
`/api/search/?resources=issue` will settle it.

The credential path *is* verified — a real key was tested through Admin →
Metadata, and that is how we learned Comic Vine rejects a bad key with an HTTP
401 on `/issues/` rather than the documented 200-with-error-body.

### The filename parser will keep needing cases

It encodes conventions, not rules, and every corpus has new ones. It is a pure
function with a table test, so a new case is one row. Known-weak: a series whose
name genuinely ends in a number, and any language where the volume marker is not
`v`/`vol`/`volume`.

### GitGuardian flagged the provider configuration entity — resolved

`MetadataProviderConfiguration.php` used to trip the `Username Password`
detector on the adjacency of the `metronUsername` and `metronPassword`
identifiers, despite containing no string literals at all. The move to bearer
tokens removed both properties, so the finding should not recur. Noted here
because the old note said renaming them would misname the domain — which was
true right up until the domain changed.

### Metron's token header form is unverified

Metron is a Django REST framework application and DRF's `TokenAuthentication`
expects `Authorization: Token <token>`, which is what `MetronProvider` sends. No
live response has confirmed it. If lookups start failing with a refused token
against a token that works elsewhere, this header is the first thing to check —
Admin → Metadata → Test will report it as "Metron refused the token".

---

## Close-out design questions, and how they were settled

1. **Where the shared kill switch lives.** Environment *and* admin setting, with
   the environment holding final authority and failing closed. An operator needs
   a control the application cannot override; an administrator needs one they do
   not need shell access to use.
2. **What a circuit breaker is allowed to change.** Nothing persistent. It pauses
   an account and expires; it never edits a setting a person chose, because a
   setting silently turned off is one somebody has to notice.
3. **Whether a personal Comic Vine key bypasses the global switch.** Yes, and it
   does the same for Metron. The switch governs the credential the installation
   owns. Somebody using their own key against their own library is the party
   Comic Vine's terms bind, and obtaining a key is them accepting those terms —
   so the operator's switch stops the operator's key, not theirs. Gating it both
   ways made the two providers behave differently for no benefit anybody could
   name.
4. **Whether an administrator can refuse personal tokens outright.** Yes, one
   switch for all providers, on by default. A deployment that wants exactly one
   outbound credential and wants to know which one it is can have that. It
   ignores stored tokens rather than deleting them, because somebody turning it
   back on should not find everybody's token was thrown away meanwhile.
4. **Whether to cascade to a second provider on failure.** No. Spending another
   account's quota to hide the first one's outage is exactly the silent
   overspending the one-provider rule exists to prevent.
5. **Whether quota is per user or per credential.** Per credential, hashed. Two
   users with the same token share an upstream allowance, and per-user tracking
   would show both a full budget while the account ran dry.
6. **Whether characters and teams become tags.** No — see the classification
   section. Genres only, and only on acceptance.

## Historical open questions from the original plan (superseded)

These were settled during implementation and are retained only as the original
decision prompts. The current answers are in
[How the pre-implementation questions were settled](#how-the-pre-implementation-questions-were-settled).

1. **Per-page metadata storage.** A dedicated table (clean queries, one row per
   page, an extra join) or a JSON column on `comic` (matches
   `User::$readerPreferences`, cheap to read whole, awkward to query)? The reader
   always wants the whole set at once, which argues for JSON; #89 may later want
   to query by dimension, which argues for a table.
2. **Provenance granularity.** Per field, or per source-of-record for the whole
   comic? Per field is more honest and more work; it is probably required by
   slice 4 regardless, so decide it in slice 1 rather than retrofitting.
3. **Re-ingestion.** If a comic's file is replaced, or ComicInfo support improves,
   is metadata re-read? A console command in the shape of
   `app:comic-pages:prune` is likely the right answer, but it must not clobber
   user edits — which is the same provenance question again.
4. **Reading direction precedence.** ComicInfo says RTL, the user's persisted
   reader preference says LTR. The reader's own settings are authoritative
   (`docs/reader.md`), so ComicInfo should seed a per-comic default that the user
   can override, not overwrite the global preference. Confirm before #86 builds
   on it.
