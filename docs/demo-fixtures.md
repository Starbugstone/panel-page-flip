# Local demo fixtures

The development fixture set turns a new local database into a small multi-user
Panel Page Flip installation. It is intended for interface development,
screenshots, and manual testing of relationships that are awkward to build one
click at a time.

## Load the data

Run migrations first, then load the demo dataset:

```bash
docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T php php bin/console app:load-demo-fixtures
```

The command is additive: existing users, administrators, comics, uploads, and
other application records are preserved. Once all six demo accounts exist, a
repeat run reports that the fixtures are already loaded and changes nothing. If
only some of the reserved demo email addresses exist, the command stops with an
error instead of guessing whether those accounts are safe to change.

The command refuses to run outside the `dev` and `test` environments. During a
new load it only replaces generated files whose names begin with
`demo-fixture-`; unrelated files under `public/uploads/comics/` are not touched.

## Accounts

Every account uses the password `DemoPassword123!`.

| Email | Username | State | Useful view |
| --- | --- | --- | --- |
| `admin@example.test` | `@demo_admin` | Verified administrator | Users, comics, reports, warnings, audit history |
| `alex@example.test` | `@alex_reader` | Verified | Richest library, received and sent shares, pending folder invitation, warning |
| `blair@example.test` | `@blair_books` | Verified | Accepted, declined, and external pending shares; active group code |
| `casey@example.test` | `@casey_panels` | Verified | Right-to-left manga, code redemption, failed share notification |
| `drew@example.test` | `@drew_ink` | Unverified | Unverified-account state and a revoked incoming share |
| `erin@example.test` | `@erin_reads` | Verified, sharing restricted | Explicit content, quarantined comic, moderation targets |

Alex is the best first login for the ordinary user experience. The admin
account exposes the populated operational screens. Drew intentionally cannot
complete flows that require a verified email.

## Included state

The fixture graph contains:

- 18 comics spread across five owners, with series metadata, authors,
  publishers, classifications, reading directions, tags, and one simulated
  Dropbox import;
- global and personal tags, including an `Archived` tag that hides one comic
  from the default library view;
- nested library folders and per-viewer placements for owned and shared comics;
- reading progress for owned and accepted shared comics;
- accepted, pending, declined, revoked, removed, explicit-content-gated, direct,
  folder-batch, and external-email share states;
- active single-comic and group claim codes, a redeemed group code, and a
  withdrawn code;
- open, under-review, and closed content reports, along with open and
  acknowledged user warnings and representative administrator audit entries;
- the singleton comic-format and metadata-provider settings needed by a fresh
  database, without replacing settings that already exist.

All 18 comic rows point at separately named CBZ files, so the application sees
them as different comics. Each generated CBZ contains the same single page from
`backend/public/comic.png`. The files are hard-linked to one generated archive
when the filesystem supports it, with a copy fallback, which keeps the fixture
footprint small without bypassing the real reader and page-delivery paths.

Fixture output is written beneath `backend/public/uploads/comics/` in `dev` and
`backend/var/test-uploads/comics/` in `test`; neither generated tree is committed.
