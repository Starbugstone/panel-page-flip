# Metadata enrichment

Roadmap and working notes for issue #74 (`LIBRARY - P0`): reading structured
metadata out of comics, filling the gaps from providers, and letting the user
decide what actually gets applied.

This is written to be picked up cold. Each slice below is a separate PR, and
there may be weeks between them, so every slice states what it depends on, what
it must not do, and how you know it is finished.

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

## Starting state (at time of writing)

Verified against `main` at the merge of PR #103 and PR #104:

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

---

## Slice 1 — Structured metadata model and ComicInfo.xml ingestion

**Unblocks #86 and #89. No network. Fully unit-testable. Do this one first.**

### Scope

1. **A named-entry seam on the source providers.** ComicInfo.xml lives inside the
   archive, and nothing can read it today. Add a capability alongside
   `ComicPageProviderInterface` — a separate interface is preferable to widening
   the existing one, because PDF cannot implement it meaningfully:
   - CBZ: `ZipArchive::getFromName('ComicInfo.xml')`, with the same
     `MAX_*` bounds `ZipPageProvider` already applies to pages.
   - CBR/CB7/CBT: `7z x -so -spd -- <archive> ComicInfo.xml`, matching the
     existing call's argument shape exactly (`--` and `-spd` are load-bearing;
     see `SevenZipPageProvider::readPage`).
   - PDF: has no ComicInfo.xml. It contributes only what its own Info dictionary
     / XMP offers (title, author). Do not fabricate the rest.
   - The entry name is matched case-insensitively and only at the archive root —
     real files use `ComicInfo.xml` and `comicinfo.xml` roughly equally.

2. **A parsed, validated representation.** A value object, not an array. Unknown
   fields are dropped rather than carried; out-of-range values fall back per
   field the way `App\Reader\ReaderPreferences::normalize()` does, so one bad
   value cannot discard a whole file's metadata.

3. **Persistence.** New columns/table for series, volume, issue number, issue
   count, publication date, language, reading direction, and structured
   creators. Per-page metadata (`type`, `doublePage`, `width`, `height`) needs
   its own table keyed by comic + page index.

4. **The page-info contract.** A read model the reader can consume that answers
   "is page N a double-page", "what are its dimensions", "is it the cover", and
   "what direction does this comic read". ComicInfo populates it; #89 later
   populates it for comics without ComicInfo. **Define this deliberately — it is
   the actual deliverable for #86 and the hardest thing to change later.**

5. **Ingestion at import.** All four entry points funnel through
   `ComicService::uploadComic()` (`ComicController` x2, `ImportComicsCommand`,
   `DropboxImportService`), so there is exactly one place to hook. A comic whose
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
- A PDF imports with whatever its Info dictionary offers and no invented fields.
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

## Open questions to settle before slice 1 is coded

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
