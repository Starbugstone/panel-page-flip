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
| Every supported source format | Upload chunks (they have [their own limits](#chunked-uploads-in-progress)), validation temporaries, logs |

Derivatives are rebuildable server cache and belong to nobody's quota. The
exclusions are not a simplification to be tidied up later: they are what makes
the admin figure agree with `StorageQuotaService`.

## Where the numbers come from

The sum is written once, in `ComicRepository`, and reached through three doors:

| Caller | Asks about | Method |
| --- | --- | --- |
| Upload admission | one owner | `getStorageBytesForOwner()` |
| The account's own view | itself | `getStorageStatsByOwner()` |
| Admin user list | a page of owners | `getStorageStatsByOwner()` |
| Admin dashboard tile | everybody | `getTotalStorageBytes()` |

All three build on the same `STORAGE_BYTES` expression, so there is no second
definition to drift. `getStorageStatsByOwner()` returns the comic count and the
byte total from one grouped query, which is why a comic cannot be counted by one
and missed by the other; `UserRepository::getOwnedContentStats()` only joins tag
counts onto that result.

The quota half lives in `StorageQuotaService`:

- `getUserStorageBytes()` is what upload admission is checked against.
- `getQuotaBytes(User)` returns the effective limit: the account's stored
  override when present, otherwise `UPLOAD_USER_QUOTA_BYTES`.
- `getDefaultQuotaBytes()` returns that installation default, 10 GiB when the
  environment variable is omitted.

The stored override is nullable. `null` means "follow the server default" and
`0` explicitly means unlimited; those states are not interchangeable. An
unlimited account has no application-level protection from filling the disk,
which is why the admin form warns before saving it. Nothing outside the service
may reproduce this precedence rule or read `%upload_user_quota_bytes%`.

The admin list does **not** calculate usage once per row — one grouped query
serves the page. Resolving each account's quota reads the already-loaded user
and the configured default, and never touches the filesystem.

## The API contract

`GET /api/users`, `GET /api/users/{id}` and `GET /api/me/storage` report the same
core storage fields for the same account, from the same grouped query:

```json
{
  "comicCount": 25,
  "storageUsedBytes": 9341553868,
  "storageQuotaBytes": 10737418240,
  "unmeasuredComicCount": 0
}
```

`/api/users` responses additionally report
`storageQuotaOverrideBytes` (nullable) and `storageDefaultQuotaBytes`, which
are the two values the quota editor needs to distinguish inheritance from an
explicit limit. The account's own response only needs the effective quota.

Raw integers, never a percentage or a formatted string. The client divides, so
an account at 112% is shown as 112% rather than clamped on the way out; only the
progress bar is clamped, and only visually.

Administrators change an override with
`PATCH /api/users/{id}/storage-quota`, sending
`{"storageQuotaOverrideBytes": <bytes-or-null>}`. Setting an override below
current use never deletes a comic: the account remains over quota and cannot
add canonical source bytes until use falls or its allowance rises. Every change
is recorded in the admin audit log.

`/api/me/storage` is deliberately its own request rather than a field on
`/api/me`, which the session monitor polls: a grouped sum over every comic an
account owns is cheap once and pointless every thirty seconds. The account sees
its own figures in the library sidebar and on the settings page, both through
`useStorageUsage`, and both render the same `UserStorageUsage` component the
admin user list does — so an account and the administrator looking at it can
never be told different things about the same disk.

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

## Chunked uploads in progress

Staged chunks are on the disk long before they are a `Comic` row, so they are not
in the sum above and are limited separately, per account, by
`ComicUploadStagingService`:

| Limit | Value | Refused with |
| --- | --- | --- |
| Unfinished uploads at once (`upload_max_active_uploads`) | 10 | 429 at `/upload/init` |
| Bytes staged across all of them (`upload_max_staged_bytes`) | 2 GiB | 413 |
| Used storage plus staged bytes against the account's quota | `getQuotaBytes()` | 413 |

The limits sit in `config/services.yaml` beside the other `upload_max_*` values.
They leave plenty of room for `MAX_PARALLEL_FILE_UPLOADS`, because a cancelled
upload goes on counting until it expires; raising that setting well past its
default of 2 means raising these too.

An account's admissions are serialised by one lock, and each one measures the
files actually on disk and holds the lock until the chunk is written. That is the
reservation: two parallel uploads cannot both spend the same room. The client does
not declare a file's size, so `/upload/init` can only refuse an account with no
room at all, and it is the chunk that would not fit that gets refused after that.
Parallel uploads share what is left, and if they do not all fit, the one whose
chunk arrives second gets the 413.

A refused upload's staged files are removed at once. The client abandons a file at
its first refused chunk and cannot resume it, so keeping them would only count
against the next attempt. `ComicService` stays the authority on whether a finished
upload fits: while it decides, the upload's bytes stop counting towards the quota
check, since they would otherwise be counted twice in the moment between its row
committing and its staged copy being deleted. They do still count towards the 2 GiB.

### Expiry

An upload with no activity for an hour (`upload_staging_idle_seconds`) is
abandoned. A request working on one holds its lock, and the sweep skips anything
locked, so a slow validation is never mistaken for it. Two things remove them:

- `/upload/init` clears the account's own expired uploads before measuring, so
  somebody returning after cancelling is not refused over their own leftovers.
- `app:cleanup-personal-data` clears every account's, which is what reclaims the
  space of an account that never comes back. It is
  [already required daily](../SSH-deploy.md#7-background-jobs-cron--systemd-timers):

```cron
0 3 * * * cd /var/www/comics/backend && php bin/console app:cleanup-personal-data --env=prod >> /var/log/comics-cleanup.log 2>&1
```

Staging lives in `var/upload-staging/` rather than the system temp directory,
because PHP-FPM and cron do not have to agree on what that is, and a sweep looking
in the wrong one would find nothing and report success. Directories are created
group-writable, so a cron job running as the deploy user can remove what the web
server made as long as the two share a group; if they do not, the command warns
and counts what it could not remove.

## Deleted comics and quarantine

Deleting a comic, singly, in bulk or as an administrator, moves its files to
`var/quarantine/comics/<date>/<batch>/` inside the transaction, so a failed commit
can put them back. The commit also queues them in `pending_file_deletion`, and they
are purged immediately after it. A purge that fails stays queued, and
`app:cleanup-personal-data` retries it. So a deleted comic cannot be recovered, and
the delete dialogs say so.

`FileQuarantineService::RETENTION_DAYS` (7) is the longest anything stays in
quarantine, and the same daily command enforces it. It is what removes orphans
quarantined by `app:cleanup-comics` or **Admin → Orphan File Cleanup**, which
can be moved back by hand until then, and everything deleted before purging existed,
which the layout cannot tell apart from orphans. A file's age comes from its batch
date, because a move keeps the file's own timestamp; a file outside that layout is
aged by its timestamp. Empty directories left behind are removed too, since their
names hold user and comic ids.

Quarantined files are not in the backup, and they do not count towards storage used.

## On-disk layout, and the ceiling it implies

Comic sources live one flat directory per account,
`public/uploads/comics/<userId>/`, with
a slug and a uniqid in each filename. The quota is what bounds that directory:
at 10 GiB an account reaches five figures of files only with unusually small
comics, and a hashed directory index (ext4 `dir_index`, or any modern
equivalent) answers exact-name lookups at that size without trouble.

That holds because serving a page never lists the directory — it opens one file
by name. The only enumeration is `ComicCleanupService`'s orphan sweep, and it
runs from `app:cleanup-comics` rather than from a request, so its cost lands on
a maintenance window instead of on a reader.

Raising `UPLOAD_USER_QUOTA_BYTES` or a user override substantially is the
change that would move this ceiling. The answer at that point is sharding into
nested directories
(`<userId>/ab/cd/<file>`), which is a migration of existing files and a change
to every path already stored in the database — not a one-line edit at the point
of upload. Worth knowing before the quota is raised, rather than after.
