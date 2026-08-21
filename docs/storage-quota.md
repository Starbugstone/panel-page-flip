# Storage accounting and the per-user quota

Comic count is not a proxy for disk use: a 40-page CBZ can be 12 MiB or 900 MiB.
An administrator diagnosing a capacity problem needs bytes, and the bytes they
are shown have to be the same bytes an upload is refused over. This document is
about keeping those two numbers the same number.

## One definition of "storage used"

```text
storage used = SUM(Comic.fileSize) over the comics that account owns
```

That is the canonical source file as uploaded, and nothing else:

| Counts | Does not count |
| --- | --- |
| Direct uploads | Comics shared *with* the account — sharing grants access to the owner's file and copies nothing |
| Chunked uploads, once they become `Comic` rows | Generated page derivatives and the page cache |
| Dropbox imports that become owned `Comic` rows | Thumbnails and reader cache |
| Every supported source format | Upload chunks, validation temporaries, logs |

Derivatives are rebuildable server cache and belong to nobody's quota. The
exclusions are not a simplification to be tidied up later: they are what makes
the admin figure agree with `StorageQuotaService`.

## Where the numbers come from

`StorageQuotaService` owns both halves.

- `getUserStorageBytes()` sums one owner's comics — the figure upload admission
  is checked against.
- `getQuotaBytes(User)` returns the effective limit, `upload_user_quota_bytes`,
  10 GiB today.

`getQuotaBytes()` takes a `User` it does not currently read. That argument is the
seam: when the quota becomes configurable and per-user (#64), resolution changes
inside this one method and every caller keeps working. Nothing outside the
service may read `%upload_user_quota_bytes%` or hardcode the number.

The admin list does **not** call that service once per row. `UserRepository::getOwnedContentStats()`
answers for a whole page in one grouped query, alongside the existing grouped tag
query, and the admin list never touches the filesystem.

## The API contract

`GET /api/users` and `GET /api/users/{id}` report the same fields for the same
account, from the same grouped query:

```json
{
  "comicCount": 25,
  "storageUsedBytes": 9341553868,
  "storageQuotaBytes": 10737418240,
  "unmeasuredComicCount": 0
}
```

Raw integers, never a percentage or a formatted string. The client divides, so
an account at 112% is shown as 112% rather than clamped on the way out; only the
progress bar is clamped, and only visually.

## Unmeasured comics

`Comic.fileSize` is nullable because the column arrived after comics could
already exist. `app:backfill-comic-file-size` fills it in from disk and runs as
part of the deployment `upgrade-data` step, but it deliberately leaves the column
null when the source file cannot be found.

`SUM()` skips those rows without complaint, so a total can be understated with no
outward sign. `unmeasuredComicCount` is how many owned comics are in that state,
and the admin UI says so rather than presenting the sum as exact:

```text
Measured: 6.4 GiB / 10 GiB
2 comics have no stored file-size metadata; actual usage may be higher.
```

Seeing that warning means run the backfill, and if a comic still has no size
afterwards, its source file is genuinely missing — a data problem to investigate,
not a display bug. Serving `/api/users` will never stat those files to paper over
it: one broken historical file must not make the admin user list expensive, or
fail it entirely.
