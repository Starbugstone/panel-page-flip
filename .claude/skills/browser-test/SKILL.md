---
name: browser-test
description: Launch the full Panel Page Flip stack in Docker and drive it with a headless browser to prove a change works as a user would meet it. Use when asked to run the app, screenshot it, or confirm a change works end to end rather than only in tests.
---

# Driving this app in a browser

The suites are fast and thorough, but they mock the API. Uploading a comic and
reading it exercises PHP, MySQL, GD, the archive readers and the React reader
together, which is where the interesting failures live.

Everything below runs in containers. **Do not try the host toolchain first** —
see [Host limits](#host-limits) for why it cannot work.

## 1. Bring the stack up

```bash
.claude/skills/browser-test/scripts/up.sh
```

That script builds and starts `database`, `php`, `nginx` and `mailpit`, applies
migrations to the dev database, creates two verified accounts, and generates
fixtures. It is idempotent — run it again after a rebuild.

It prints the accounts it made. Both have a fixed password so a driver script
can log in without being told:

| Account | Role |
| --- | --- |
| `navtest@example.com` | plain user |
| `navadmin@example.com` | admin |

The app is then at **http://localhost:8080**. From inside a container on the
app network it is **http://nginx**.

### The Docker credential helper is broken on WSL here

Builds fail with `error getting credentials`. `up.sh` works around it by
pointing `DOCKER_CONFIG` at a throwaway directory holding `{}` and disabling
BuildKit. If you run `docker compose` by hand, do the same:

```bash
export DOCKER_CONFIG=/tmp/ppf-dockercfg DOCKER_BUILDKIT=0 COMPOSE_DOCKER_CLI_BUILD=0
mkdir -p "$DOCKER_CONFIG" && printf '{}' > "$DOCKER_CONFIG/config.json"
```

## 2. Drive it

`scripts/drive.mjs` is a worked example that logs in, uploads a CBZ and a PDF,
opens the reader and exercises the reader settings. `scripts/mobile-reader.mjs`
drives the same reader as a phone — real touch events for swipe, tap, pinch and
double tap — which is the only place gestures, transforms and viewport units can
actually be judged. Copy either, edit the middle, and run:

```bash
.claude/skills/browser-test/scripts/drive.sh /path/to/your-driver.mjs
```

Screenshots land in `var/browser-test/` and the run prints PASS/FAIL per
assertion plus any console errors and 4xx/5xx responses.

**Look at the screenshots.** A blank frame is a failure to launch, and the
assertions will not always tell you.

### What the driver has to know about this app

- **Playwright's npm package is not in the Playwright image.** `drive.sh`
  installs it into a cached scratch directory the first time.
- **Run on the app's network**, addressing the app as `http://nginx`.
  `drive.sh` does this. Host networking is unreliable from the container.
- **Dismiss the cookie banner after logging in** or it covers the buttons at
  the bottom of the page. The helper in `drive.mjs` does it.
- **Uploads only accept enabled formats.** Only CBZ is enabled out of the box;
  PDF and the archive formats must be turned on in Admin → Formats first, which
  `drive.mjs` shows. The upload form's file input silently refuses a disabled
  extension, and the submit button simply never enables — so wait for the button
  to become enabled rather than clicking it blind, or the failure looks like a
  timeout with no explanation.
- **Admin format toggles are checkboxes with `id="format-<name>"`**, not
  switches.
- **Reader page images** carry `alt="Page N of <title>"`, and the page
  container carries `data-page-fit`. Both are the reliable hooks.
- **A comic does not open at page 1.** It resumes where it was last left, which
  is the reader restoring saved progress and is correct. Asserting on page 1
  straight after navigating reads as an app bug when it is a test bug — set
  `#reader-page-input` to 1 first, as `drive.mjs` does.
- **Comics accumulate across runs.** Tag this run's uploads and drive only
  those, or a re-run silently tests a comic left over from last time.

## 3. Reset

```bash
docker compose down            # keeps the database volume
docker compose down -v         # also drops the data
```

The test accounts and comics live in the **dev** database, not the test one, so
they survive `php bin/phpunit`.

## Host limits

Verified 2026-08-14; do not spend time rediscovering it:

- Host Node is v18; `frontend/package.json` needs `>=22.12`. vitest will not
  start, and the committed `node_modules` lacks the rolldown native binding.
- Host PHP lacks `dom`, `mbstring`, `xmlwriter`, `zip` and `gd`, so PHPUnit
  refuses to start.
- Alpine images cannot load the host's glibc-built native modules — use
  `node:22-bookworm-slim` when mounting host `node_modules`.

## Gotchas that have bitten before

- **A 429 on `/api/login`** means the login limiter tripped — five attempts per
  fifteen minutes, and each driver run spends two. `up.sh` clears it, or:
  `docker compose exec -T php php bin/console cache:pool:clear cache.rate_limiter`.
  It looks like a hung login, because the driver waits for a redirect that never
  comes.
- **`/tmp/comic_uploads` owned by root** makes every upload 500 with
  `mkdir(): Permission denied`. Running console commands as root inside the php
  container is what causes it. `up.sh` fixes the ownership.
- **The `_test` database drifts** out of sync with migrations. Dropping and
  recreating it is the reliable fix and costs nothing — it is rebuilt from
  migrations.
- **The frontend build reads `../backend/config/frontend-routes.json`**, so a
  container running vitest or the build must mount the whole repo, not just
  `frontend/`.
