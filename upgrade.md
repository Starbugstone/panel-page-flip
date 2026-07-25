# CBZ Comic Reader — Upgrade Plan

> **Audience**: a junior developer who needs to bring this live "vibe-coded" project up to a clean, secure production standard.
> **How to use this document**: work through the sections **in order**. Each section is split into numbered tasks. Each task has:
> - **What** — the change to make
> - **Why** — the reason / risk it addresses
> - **Where** — the file(s) and approximate location
> - **How** — concrete steps and code snippets
> - **Verify** — how to confirm it works
>
> Always commit after a green test/build, never let the agent commit for you (see `README.md`).
> Do **not** start a new section until the previous one is reviewed and merged.

---

## Table of Contents

1. [Part 0 — Ground rules & local setup](#part-0--ground-rules--local-setup)
2. [Part 1 — Critical security fixes (BLOCKER)](#part-1--critical-security-fixes-blocker)
3. [Part 2 — High-priority security hardening](#part-2--high-priority-security-hardening)
4. [Part 3 — Admin section (admin-only features)](#part-3--admin-section-admin-only-features)
5. [Part 4 — Database migrations / "evolutions"](#part-4--database-migrations--evolutions)
6. [Part 5 — Multi-upload (multiple comics at once)](#part-5--multi-upload-multiple-comics-at-once)
7. [Part 6 — General clean-up & quality-of-life](#part-6--general-clean-up--quality-of-life)
8. [Appendix A — Test checklist](#appendix-a--test-checklist)
9. [Appendix B — File-by-file summary of issues](#appendix-b--file-by-file-summary-of-issues)

---

## Part 0 — Ground rules & local setup

These rules apply to **every task** below. Read them once, follow them always.

### 0.1 — Do not commit secrets
- Never commit `backend/.env.local`, `backend/.env.prod.local`, `passwords.txt`, or anything containing credentials.
- The current root `.env` contains a hard-coded `APP_SECRET`. We are going to rotate it (see [Task 1.1](#11--rotate-and-protect-app_secret)).

### 0.2 — Branching
- Create one branch per "Part" or per "Task" depending on size, e.g. `fix/security-app-secret`, `feat/admin-create-user`, `feat/multi-upload`.
- Never push to `main` directly. Open PRs against `develop`.

### 0.3 — Run the stack
```sh
# At repo root
docker compose up -d
docker compose ps                 # all services should be Up
docker compose logs -f php        # tail PHP logs while developing
```
Frontend dev URL: `http://localhost:3001` — Backend API + nginx: `http://localhost:8080` — Mailpit: `http://localhost:8025` — Adminer: `http://localhost:8081`.

### 0.4 — Run tests
- Backend: `docker compose exec php ./vendor/bin/phpunit`
- Frontend: from WSL2 inside `/home/stone/dev/panel-page-flip/frontend`, run `npx vitest`.

### 0.5 — When you finish a task
1. Run lints/tests for the impacted layer.
2. Manually retest the flow you changed (see [Appendix A](#appendix-a--test-checklist)).
3. Commit with a message like `fix(security): rotate APP_SECRET and remove from VCS`.
4. Update this file's checkbox if you want to track progress.

---

## Part 1 — Critical security fixes (BLOCKER)

> **These must be fixed before the next production deploy.** Some of them are exploitable today.

### 1.1 — [DONE] Rotate and protect `APP_SECRET`

- **What**: Generate a new `APP_SECRET`, remove it from version control, and only ever load it from `.env.local` / `.env.prod.local` / a real secrets manager.
- **Why**: The current value `996dbe9d34e00af050e8dd9bc7c4f9d4` lives in the committed `backend/.env` and is published in the public repo. With this secret an attacker can forge signed cookies, CSRF tokens, password-reset tokens, etc.
- **Where**: `backend/.env`, `backend/.env.local` (new), `.gitignore`, `README.md`.
- **How**:
  1. Generate a new secret:
     ```sh
     docker compose exec php sh -c "php -r 'echo bin2hex(random_bytes(32)).PHP_EOL;'"
     ```
  2. Put it in **`backend/.env.local`** (this file is already in `backend/.gitignore`):
     ```env
     APP_SECRET=THE_NEW_HEX_STRING
     ```
  3. In the committed `backend/.env`, replace the real secret with the placeholder Symfony Flex uses by default:
     ```env
     APP_SECRET=ChangeMeInEnvLocal
     ```
  4. Treat the old secret as permanently compromised. Rotation fixes the live risk, but it does **not** remove the value from existing git history.
  5. Only if the repo owner explicitly wants history rewritten, plan a separate maintenance task using `git filter-repo` or GitHub secret scanning remediation. Do **not** bundle history rewriting into the normal app fix PR.
  6. Force a session/cookie rotation on production after deploy: `docker compose exec php php bin/console cache:clear && docker compose restart php`.
  7. Document the secret rotation procedure in the production runbook (see [Task 6.6](#66--production-runbook)).
- **Verify**: `rg "996dbe9d34e00af050e8dd9bc7c4f9d4" backend .` returns no hit in the current tree. After deploy, all currently-logged-in users are logged out (expected behaviour, communicate it).

### 1.2 — [DONE] Remove `dump()` debug calls in `DropboxController`

- **What**: Delete every `dump(...)` line in `backend/src/Controller/DropboxController.php`.
- **Why**: `dump()` writes to the response/web profiler in `dev` and silently no-ops in `prod`, **but** the calls currently embed the **session ID, full session content, and OAuth state** in the page output during the OAuth flow. In `dev` mode behind the dev profiler this is a session-hijack primer. They should never have been merged.
- **Where**: `backend/src/Controller/DropboxController.php` lines 64, 84, 93 (search for `dump(`).
- **How**: Replace the `dump(...)` calls with a Monolog logger:
  ```php
  use Psr\Log\LoggerInterface;
  // inject LoggerInterface $logger via the constructor
  $this->logger->debug('Dropbox OAuth state set', ['session_id' => $this->session->getId()]);
  ```
  Only the session ID hash is needed for debugging; never log the OAuth state, refresh token or access token.
- **Verify**: `rg "dump\(" backend/src` returns no hits. OAuth flow still works in dev (test by clicking "Connect to Dropbox" in `/dropbox-sync`).

### 1.3 — [DONE] Stop double-registering controllers in `routes.yaml`

- **What**: Remove the duplicate `api:` resource that mounts every controller a second time under `/api/...`.
- **Why**: `backend/config/routes.yaml` registers controllers twice — once via `controllers:` (no prefix) and once via `api:` with prefix `/api/`. Because every controller already declares `#[Route('/api/...')]`, the second registration produces routes like `/api/api/comics`. These extra routes are still protected by `^/api` access controls, but they:
  - Confuse routing/cache and inflate router compile time
  - Open undocumented endpoints (`/api/api/login`, `/api/api/users`, …) that future security rules might forget to protect
  - Make every URL change a hidden double-edit
- **Where**: `backend/config/routes.yaml`.
- **How**: Reduce to one resource:
  ```yaml
  controllers:
      resource:
          path: ../src/Controller/
          namespace: App\Controller
      type: attribute

  frontend:
      path: /{reactRouting}
      controller: App\Controller\FrontendController::index
      requirements:
          reactRouting: ^(?!api|_wdt|_profiler).+
      defaults:
          reactRouting: ''
  ```
- **Verify**: `docker compose exec php php bin/console debug:router | grep "/api/api"` returns nothing. The frontend continues to work.

### 1.4 — [DONE] Tighten `security.yaml` access controls

- **What**: Make admin-only endpoints actually admin-only for **every** HTTP method, not just GET/DELETE.
- **Why**: Today only `GET ^/api/users$` and `DELETE ^/api/users/[0-9]+$` require `ROLE_ADMIN` at the firewall level. `POST /api/users` (create) and `PUT/PATCH /api/users/{id}` (update, e.g. role change) fall through to the catch-all that only requires `IS_AUTHENTICATED_FULLY`. The controller does its own check today, but defense-in-depth requires the firewall to enforce as well.
- **Where**: `backend/config/packages/security.yaml`.
- **How**: Replace the `access_control` block with:
  ```yaml
  access_control:
      # Public routes
      - { path: ^/api/login$,                        roles: PUBLIC_ACCESS }
      - { path: ^/api/register$,                     roles: PUBLIC_ACCESS }
      - { path: ^/api/login_check$,                  roles: PUBLIC_ACCESS }
      - { path: ^/api/forgot-password$,              roles: PUBLIC_ACCESS }
      - { path: ^/api/reset-password,                roles: PUBLIC_ACCESS }
      - { path: ^/api/email-verification/verify,     roles: PUBLIC_ACCESS }
      - { path: ^/api/email-verification/resend$,    roles: PUBLIC_ACCESS }
      - { path: ^/api/ping$,                         roles: PUBLIC_ACCESS }

      # Admin routes (all methods)
      - { path: ^/api/users(/[0-9]+)?$,              roles: ROLE_ADMIN }
      - { path: ^/api/admin,                         roles: ROLE_ADMIN }   # see Part 3

      # Everything else under /api/ requires login
      - { path: ^/api,                               roles: IS_AUTHENTICATED_FULLY }
  ```
  Notes:
  - The `/api/users/me` endpoint is accessed by everyone authenticated; move that route to its own controller path (`/api/me`) so it doesn't sit under the `^/api/users` admin regex. See [Task 1.5](#15--split-apiusersme-out-of-the-admin-namespace).
  - Keep the controller-level `denyAccessUnlessGranted('ROLE_ADMIN')` checks as defense-in-depth.
- **Verify**:
  - As a regular user, `PUT /api/users/<other-id>` returns `403`.
  - As an admin, the admin dashboard still works.
  - `GET /api/me` (after Task 1.5) returns the current user.

### 1.5 — [DONE] Split `/api/users/me` out of the admin namespace

- **What**: Move the "me" endpoint from `/api/users/me` to `/api/me`.
- **Why**: The new `^/api/users` admin firewall rule blocks `/api/users/me`. Cleanly separating "who am I?" from "manage users" is also clearer.
- **Where**:
  - `backend/src/Controller/UserController.php` — change route attribute from `/me` to remove it from `UserController` and create a tiny `MeController`.
  - `frontend/src/hooks/use-auth.jsx` — change fetch URL.
  - `frontend/src/lib/session-manager.js` (or wherever the keep-alive ping lives).
  - `backend/config/packages/security.yaml` — `/api/me` requires `IS_AUTHENTICATED_FULLY`.
- **How**:
  1. Create `backend/src/Controller/MeController.php` containing the `me()` action exactly as it is now, but at `#[Route('/api/me', methods: ['GET','POST'])]`.
  2. Delete the `me()` action from `UserController.php`.
  3. In the frontend, replace every call to `/api/users/me` with `/api/me` (`rg "users/me" frontend/src`).
- **Verify**: Logging in still works. `GET /api/me` returns user data with roles. `GET /api/users` returns 403 for non-admins.

### 1.6 — [DONE] Sanitise chunked-upload `fileId` and `filename`

- **What**: Validate `fileId` (UUID-only) and `filename` (basename + cbz extension only) on every chunked-upload endpoint.
- **Why**: In `ComicController::initUpload`, `$fileId = $data['fileId']` is concatenated into a filesystem path: `$this->tempUploadDir . '/' . $user->getId() . '/' . $fileId`. A `fileId` like `../../../../etc` lets an authenticated user write/delete arbitrary files. The same applies to `metadata['filename']` in `completeUpload`.
- **Where**: `backend/src/Controller/ComicController.php`, methods `initUpload`, `uploadChunk`, `completeUpload`.
- **How**:
  ```php
  // At the top of the controller
  private const FILE_ID_REGEX = '/^[A-Za-z0-9\-]{8,64}$/';

  private function assertSafeFileId(string $fileId): void {
      if (!preg_match(self::FILE_ID_REGEX, $fileId)) {
          throw new BadRequestHttpException('Invalid fileId.');
      }
  }

  private function assertSafeFilename(string $filename): string {
      $base = basename($filename);                              // strip any path
      if (!preg_match('/^[A-Za-z0-9._\- ]{1,200}\.cbz$/i', $base)) {
          throw new BadRequestHttpException('Invalid filename.');
      }
      return $base;
  }
  ```
  Use them in every endpoint:
  ```php
  $this->assertSafeFileId($fileId);
  $filename = $this->assertSafeFilename($filename);
  ```
  Also in `completeUpload`, replace `$metadata['filename']` with `$this->assertSafeFilename($metadata['filename'])` before using it as a destination filename.
- **Verify**: `curl -X POST /api/comics/upload/init -d '{"fileId":"../../etc","filename":"x.cbz","totalChunks":1}'` returns `400`.

### 1.7 — [DONE] Enforce upload size limits (per chunk, per file, per user quota)

- **What**: Reject chunked uploads that exceed configured limits.
- **Why**: Today `chunkSize` is enforced only by the frontend (1 MB). A malicious client can send 1 GB chunks. There's no upper bound on `totalChunks` or the cumulative size, so a logged-in user can fill the disk.
- **Where**: `backend/config/services.yaml` (parameters), `backend/src/Controller/ComicController.php`, `php.ini` (already enforced for single-shot upload).
- **How**:
  1. Add parameters in `backend/config/services.yaml`:
     ```yaml
     parameters:
         upload_max_chunk_bytes: 2097152          # 2 MB
         upload_max_total_bytes: 524288000        # 500 MB per file
         upload_max_total_chunks: 600             # 500 MB / ~1 MB
         upload_user_quota_bytes: 10737418240     # 10 GB per user
     ```
  2. Inject them into `ComicController` and check:
     - `initUpload`: reject if `totalChunks > upload_max_total_chunks`.
     - `uploadChunk`: reject if `$chunk->getSize() > upload_max_chunk_bytes`.
     - `completeUpload`: sum chunk sizes from metadata; reject if > `upload_max_total_bytes`.
     - Before persisting, query the user's current consumption (sum of `Comic.fileSize`, see [Task 1.13](#113--store-cbz-file-size-on-comic-entity)) and reject if > `upload_user_quota_bytes`.
  3. Surface human-readable errors in JSON.
- **Verify**: Trying to upload a 600 MB file as a non-admin user returns 413/400 with "File too large".

### 1.8 — [DONE] Validate the CBZ MIME type and content, not just the extension

- **What**: Verify the uploaded file is actually a ZIP archive containing image files.
- **Why**: `ComicService` only checks the extension. An attacker can upload `evil.cbz` containing executable code or malformed data that crashes `ZipArchive`.
- **Where**: `backend/src/Service/ComicService.php`.
- **How**: After the file is written to disk:
  ```php
  $finfo = new \finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($absolutePath);
  if (!in_array($mime, ['application/zip', 'application/x-cbz', 'application/octet-stream'], true)) {
      unlink($absolutePath);
      throw new \Exception('Uploaded file is not a valid CBZ archive.');
  }

  $zip = new ZipArchive();
  if ($zip->open($absolutePath) !== true) {
      unlink($absolutePath);
      throw new \Exception('Could not open archive.');
  }
  $hasImage = false;
  for ($i = 0; $i < $zip->numFiles; $i++) {
      $name = $zip->getNameIndex($i);
      if (preg_match('/\.(jpe?g|png|gif|webp)$/i', $name)) { $hasImage = true; break; }
  }
  $zip->close();
  if (!$hasImage) {
      unlink($absolutePath);
      throw new \Exception('Archive contains no images.');
  }
  ```
- **Verify**: Upload a renamed `.txt` (extension `.cbz`) — backend rejects with 400.

### 1.9 — [DONE] Review CSRF exposure and harden cookie-authenticated writes

- **What**: Add a consistent CSRF strategy for cookie-authenticated API writes, or move fully to bearer tokens.
- **Why**: The current setup already relies on session cookies and even has partial frontend XSRF helpers (`UploadComicForm.jsx`, `session-manager.js`), but the backend does not validate any CSRF token. `SameSite=Lax` reduces classic cross-site `POST` attacks, so this is **not** the most urgent issue in the file, but it still leaves us with a half-implemented security model and future risk if the deployment model changes (subdomains, embedded app, relaxed cookie settings, new GET side effects, etc.).
- **Where**: `backend/config/packages/security.yaml`, `backend/config/packages/framework.yaml`, frontend fetch helpers.
- **How (recommended path: keep cookies, add explicit CSRF for write endpoints)**:
  1. Keep session-cookie auth for now; do **not** flip to `SameSite=Strict` blindly until the share/email verification/OAuth flows are retested.
  2. Add CSRF tokens to every non-`GET` API call that relies on the logged-in session. Symfony 6 ships a CSRF token manager; create middleware that:
     - Issues a `XSRF-TOKEN` cookie when a session is created (uses `csrf_token('api')`).
     - Verifies the `X-XSRF-TOKEN` header on every non-`GET` request under `/api`.
  3. Exclude explicit bearer-style callback/link flows from generic CSRF enforcement where needed, and document them clearly:
     - `/api/dropbox/callback` must stay a Dropbox OAuth callback and is already protected by the `state` parameter.
     - `/api/email-verification/verify/{token}` is a one-time bearer link, not a session-authenticated form post.
  4. Update the frontend: the `getCsrfToken()` helper already exists in `UploadComicForm.jsx` and `session-manager.js`; centralise that logic and make every authenticated write use it.
- **How (alternative: switch to JWT/bearer)**:
  1. Install `lexik/jwt-authentication-bundle`.
  2. Use httpOnly cookie containing the JWT, refresh-token endpoint, and stop relying on PHP sessions for auth.
  3. This is more work but removes CSRF entirely.
- **Verify**: A `POST /api/comics` from a different origin without the `X-XSRF-TOKEN` header returns 403, while login, password reset, Dropbox OAuth callback, and email verification still work.

### 1.10 — [DONE] Stop logging PII / file paths via `error_log`

- **What**: Remove or replace every `error_log(...)` with structured Monolog logging that respects the configured log level.
- **Why**: `ComicController`, `ComicService`, `DropboxController` etc. log user IDs, IPs, full filesystem paths and request bodies via `error_log`. In production these end up in the web server's error log readable by anyone who can access logs (FTP, etc.). Some lines also leak into JSON responses (`request_info`, `details`, `file_info`).
- **Where**: search the backend: `rg "error_log\(" backend/src`.
- **How**:
  1. Inject `Psr\Log\LoggerInterface $logger` everywhere it's needed.
  2. Replace `error_log('Foo ' . $bar)` with `$this->logger->info('Foo', ['bar' => $bar])`.
  3. Use log levels: `debug` for dev only, `info` for normal flow, `warning` for recoverable issues, `error` for failures.
  4. Remove the `request_info`, `file_info`, `details`, and `debug` keys from JSON error responses (replace with a generic message).
- **Verify**: `rg "error_log\(" backend/src` returns 0 hits. JSON errors no longer contain stack traces or paths.

### 1.11 — [DONE] Ensure tags are user-scoped everywhere

- **What**: When looking up tags by name (in `ComicController::update`, `ComicService::uploadComic`, and `TagController::create`), always include the `creator` filter.
- **Why**: Today `findOneBy(['name' => $tagName])` returns *any* user's tag with that name. So uploading a comic with the tag "Marvel" reuses someone else's "Marvel" tag — you've leaked tag ownership and possibly references between users.
- **Where**:
  - `backend/src/Controller/ComicController.php::update()` (the `if (isset($data['tags']))` block).
  - `backend/src/Service/ComicService.php::uploadComic()` (the `if (!empty($tags))` block).
  - `backend/src/Controller/TagController.php::create()` (the conflict check) and `update()`.
- **How**: Pass `creator` in every lookup:
  ```php
  $tag = $repo->findOneBy(['name' => $tagName, 'creator' => $user]);
  ```
  Then add a unique constraint covering `(name, creator_id)` in the database (write a new Doctrine migration — see [Part 4](#part-4--database-migrations--evolutions)).
- **Verify**: User A creates tag "Action", uploads comic with "action". User B's "action" tag is unaffected. The DB now has two distinct rows.

### 1.12 — [DONE] Encrypt Dropbox tokens at rest

- **What**: Encrypt the `dropboxAccessToken` and `dropboxRefreshToken` columns before persisting.
- **Why**: A DB dump leaks live OAuth tokens for every connected user — the attacker then has read-write access to those users' Dropbox folders.
- **Where**: `backend/src/Entity/User.php`.
- **How**: Use Symfony's `secrets:` vault to store an `APP_DATA_KEY`, then add an event subscriber or custom Doctrine type that wraps `sodium_crypto_secretbox` for these two columns. A simple alternative is the `doctrine-extensions/encrypted-fields` library:
  ```php
  use ESolution\DoctrineEncryptedField\Annotations\Encrypted;

  #[ORM\Column(type: Types::TEXT, nullable: true)]
  #[Encrypted]
  private ?string $dropboxAccessToken = null;
  ```
  Provide a migration to encrypt existing values (run once in a `php bin/console app:migrate-dropbox-tokens` command).
- **Verify**: Inspect the `user` table in Adminer — the `dropbox_access_token` column shows ciphertext, not the bearer.

### 1.13 — [DONE] Store CBZ file size on Comic entity

- **What**: Add `fileSize` (bigint, nullable) to `Comic`, populate it on upload.
- **Why**: Required for the per-user quota check ([Task 1.7](#17--enforce-upload-size-limits-per-chunk-per-file-per-user-quota)) and for storage stats in the admin dashboard ([Part 3](#part-3--admin-section-admin-only-features)).
- **Where**: `backend/src/Entity/Comic.php`, new migration, `ComicService::uploadComic()`.
- **How**:
  1. Add the column + getter/setter to the entity.
  2. `php bin/console make:migration && php bin/console doctrine:migrations:migrate --no-interaction`.
  3. In `ComicService::uploadComic`, after the file is in place: `$comic->setFileSize(filesize($absolutePath));`.
  4. Backfill existing rows with a one-shot console command (see [Part 4](#part-4--database-migrations--evolutions)).
- **Verify**: Newly uploaded comics have a non-null `file_size` in DB. Quota checks pass/fail correctly.

### 1.14 — [DONE] Remove or guard the `/api/comics/test` endpoint

- **What**: Either delete `ComicController::testEndpoint()` or restrict it to `dev` only.
- **Why**: It's reachable in prod (with auth, but still pointless) and provides server timestamps + confirmation that the API is live.
- **Where**: `backend/src/Controller/ComicController.php`.
- **How**: Delete the method, or wrap with `if ($this->getParameter('kernel.environment') !== 'dev') throw new NotFoundHttpException();`.
- **Verify**: Hit `/api/comics/test` in prod — gets 404.

### 1.15 — [DONE] Delete the leftover `*.new` files

- **What**: Remove these files from the repo:
  - `backend/src/Controller/ShareController.php.new`
  - `frontend/src/components/PendingSharesAlert.jsx.new`
  - `frontend/src/hooks/use-pending-shares.jsx.new`
- **Why**: They're stale duplicates of the live files. They're shipped but not loaded; they confuse search/audit and risk re-introducing bugs.
- **How**: `git rm` them. Verify the live files still build.

---

## Part 2 — High-priority security hardening

These are not exploitable in one click but should ship within a week or two.

**Actual implementation status — updated 2026-05-07:** Part 2 is implemented at code level.

- **2.1 Password policy:** Implemented with `PasswordValidator` and enforced on registration, password reset, and admin-created/admin-updated passwords. Frontend registration/reset forms now show the policy before submit.
- **2.2 Rate limiting:** Implemented with Symfony RateLimiter + Lock. `/api/login`, `/api/register`, `/api/forgot-password`, and `/api/email-verification/resend` are IP-limited with 429 + `Retry-After` responses.
- **2.3 CORS:** Tightened to the env-driven localhost/dev regex in `.env`, and CORS now allows the explicit `X-XSRF-TOKEN` header required by protected writes.
- **2.4 Cookie/security headers:** Implemented prod-only secure session cookies and nginx security headers (`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, HSTS for non-dev frontend config, and CSP).
- **2.5 Dropbox token refresh:** Implemented `DropboxClientFactory` and refactored Dropbox API/controller/command paths to refresh via the stored Dropbox refresh token before creating a client.
- **2.6 localStorage trust:** Implemented. Auth initialization now always asks `/api/me`; `localStorage.user` is only a stale/missing hint and no longer drives admin access.
- **2.7 Admin role safety:** Implemented server-side prevention for self-demotion and removing/deleting the last admin.
- **2.8 Token hashing:** Implemented for reset and email-verification tokens. A migration hashes existing plaintext reset/email verification tokens.
- **Related session/logout fix:** `/api/me` keep-alive no longer regenerates the session ID on every POST, which could invalidate concurrent requests and cause unexpected logout behavior.

**Validation:** PHP syntax passed for changed files; `php bin/console lint:container` passed in Docker; Doctrine mapping validation passed with `--skip-sync`; frontend IDE lints passed for changed files; Vite build passed when building to `/tmp/panel-page-flip-part2-build`. The normal `npm run build` still fails because `frontend/dist/apple-touch-icon.png` is not writable, and `npx vitest run` still fails with the existing Cursor `ENOENT /home/stone/.cursor-server/bin/lib` environment issue. PHPUnit runs but there are no tests to execute.

### 2.1 — Enforce a real password policy

- **What**: Require minimum 12 characters, with mixed-case + digit + symbol, on register and reset.
- **Why**: Current minimum is 6 chars on register and *NotBlank* on reset. Bots will brute-force this.
- **Where**: `backend/src/Controller/RegistrationController.php`, `backend/src/Controller/ResetPasswordController.php`, `backend/src/Service/ResetPasswordService.php` (introduce a new `PasswordValidator`).
- **How**: Add a `PasswordValidator` service with a single `validate(string $password): array` returning a list of human messages. Use it in both controllers. Show messages in the frontend in real-time.
- **Verify**: Registering with `qwerty` returns errors.

### 2.2 — Rate-limit login, register, forgot-password, and verification resend

- **What**: Use Symfony's `RateLimiter` component to add 5 attempts / 15 min per IP for `/api/login`, 3/hour for `/api/register`, 5/hour for `/api/forgot-password`, and 5/hour for `/api/email-verification/resend`.
- **Why**: Right now an attacker can brute-force passwords or enumerate emails freely.
- **Where**: `backend/config/packages/rate_limiter.yaml` (new), `backend/src/EventListener/LoginRateLimitListener.php` (new), and use `#[RateLimiter(...)]` or equivalent service checks on register/forgot-password/resend actions.
- **How**: Follow [Symfony rate-limiter docs](https://symfony.com/doc/current/rate_limiter.html) — fixed-window strategy, IP-keyed.
- **Verify**: 6 wrong logins in a row → 429 with Retry-After.

### 2.3 — Tighten CORS

- **What**: Allow only the production frontend origin and the dev URL via env-driven regex.
- **Why**: `nelmio_cors.yaml` uses `origin_regex: true` with an env value — if `CORS_ALLOW_ORIGIN` is set permissively (e.g. `^.*$`), any site can call your API.
- **Where**: `backend/.env`, `backend/.env.local`, `nelmio_cors.yaml`.
- **How**: Set `CORS_ALLOW_ORIGIN=^https://comics\.yourdomain\.com$` in prod. Document that dev uses `^https?://localhost(:\d+)?$`.
- **Verify**: `curl -H "Origin: https://evil.test" -I /api/comics` returns no `Access-Control-Allow-Origin` header.

### 2.4 — Set secure cookie flags + CSP / security headers

- **What**: In `framework.yaml`, set `cookie_secure: true` for prod, keep `cookie_samesite` deliberate and tested, and add a security headers nginx snippet.
- **Why**: Today cookies are `auto`-secure (only when HTTPS detected) and `lax` SameSite. There are no CSP/X-Frame/X-Content-Type-Options headers.
- **Where**: `backend/config/packages/framework.yaml`, `docker/nginx_frontend/*.conf`.
- **How**:
  1. Use `when@prod:` overrides in `framework.yaml`.
  2. Set `cookie_secure: true`.
  3. Keep `cookie_samesite: lax` until [Task 1.9](#19--review-csrf-exposure-and-harden-cookie-authenticated-writes) is implemented and the email/share/OAuth flows are retested. If everything still works and you want the tighter posture, then switch to `strict`.
  4. Add to nginx:
  ```
  add_header X-Frame-Options DENY always;
  add_header X-Content-Type-Options nosniff always;
  add_header Referrer-Policy strict-origin-when-cross-origin always;
  add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;
  add_header Content-Security-Policy "default-src 'self'; img-src 'self' data:; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self'" always;
  ```
- **Verify**: Inspect response headers in the browser network tab.

### 2.5 — Refresh expired Dropbox tokens automatically

- **What**: Centralise Dropbox client creation in a `DropboxClientFactory` that detects token expiry, refreshes via `dropboxRefreshToken`, and persists the new token.
- **Why**: Today the controller does `new DropboxClient($user->getDropboxAccessToken())` and lets it explode after 4h. Users must reconnect manually.
- **Where**: New file `backend/src/Service/DropboxClientFactory.php`, refactor `DropboxController` and `DropboxSyncCommand` to use it.
- **How**: On 401, use `https://api.dropboxapi.com/oauth2/token` with `grant_type=refresh_token` to fetch a new access token, persist it, retry.
- **Verify**: Disconnect → reconnect → wait > 4h → list files still works without re-auth.

### 2.6 — Stop trusting `localStorage` user data

- **What**: In the frontend, treat `localStorage.user` as a UI hint only. Always re-fetch `/api/me` before showing admin pages.
- **Why**: Anyone with DevTools can edit `localStorage.user.roles` to `["ROLE_ADMIN"]` and momentarily access the admin dashboard UI. The backend will still 403 calls, but exposing admin UI structure leaks info.
- **Where**: `frontend/src/hooks/use-auth.jsx`, `frontend/src/App.jsx`.
- **How**: In `AdminRoute`, always wait for `loading=false` and `user.roles` from the *server response*, never from `localStorage`. Optionally drop `localStorage` storage of the user object entirely.
- **Verify**: After tampering with localStorage, refresh — admin route redirects to dashboard.

### 2.7 — Disallow self-demotion / removal of last admin

- **What**: Server-side check that prevents removing `ROLE_ADMIN` from yourself or from the last remaining admin.
- **Why**: The current code already prevents deleting your own account but not removing your own admin role. If the only admin demotes themselves the system is unrecoverable except via CLI.
- **Where**: `backend/src/Controller/UserController.php::update()` and `delete()`.
- **How**:
  ```php
  if ($targetUser->getId() === $user->getId() && !in_array('ROLE_ADMIN', $newRoles, true)) {
      return $this->json(['message' => 'You cannot remove your own admin role'], 403);
  }
  $remainingAdmins = $entityManager->getRepository(User::class)->countAdminsExcluding($targetUser);
  if (!in_array('ROLE_ADMIN', $newRoles, true) && $remainingAdmins === 0) {
      return $this->json(['message' => 'There must be at least one admin'], 409);
  }
  ```
- **Verify**: Demote yourself → 403. Demote the last other admin (with you also demoting) → 409.

### 2.8 — Hash reset & email-verification tokens before storing

- **What**: Store `hash('sha256', $token)` in DB; only the unhashed token leaves your server (in the email).
- **Why**: A DB leak today exposes valid live tokens that can reset any user's password.
- **Where**: `backend/src/Service/ResetPasswordService.php`, `backend/src/Repository/ResetPasswordTokenRepository.php`, `backend/src/Entity/User.php` (for email verification token).
- **How**: When creating, generate `$plain = bin2hex(random_bytes(32)); $hash = hash('sha256', $plain);` — store `$hash`, mail `$plain`. When validating, hash the incoming token and compare.
- **Verify**: Reset password works end-to-end. Inspect the DB — only hex digests, never the email link's value.

---

## Part 3 — Admin section (admin-only features)

The admin tab today has three sections: Users, Comics, Tags. Several actions are stubs. This part fixes them and adds the missing pieces.

**Actual implementation status — verified after cross-checking the tree**

- **Done in code**: `AdminController` exists with endpoints for stats, Dropbox monitoring/actions, orphan-file cleanup dry-run/apply, and audit log listing.
- **Done in code**: `AdminAuditLog`, `AdminAuditService`, `ComicCleanupService`, and migration `Version20260507213000` exist. `User.dropboxLastSyncedAt` exists and is updated by Dropbox import/sync paths.
- **Done in code**: Admin dashboard has `Overview`, `Pending`, `Users`, `Comics`, `Tags`, `Dropbox`, and `Audit` tabs.
- **Done in code**: Admin-created users are posted to `POST /api/users`, marked email-verified server-side, and audit-logged.
- **Done in code**: Admin comic owner data is returned for `adminContext=true`; admin view/edit actions are wired; page/cover/progress endpoints allow admin read-only inspection of another user's comic.
- **Done in code**: Admin Tags now fetches `/api/tags?all=true&adminContext=true` and displays `creator.name || creator.email`.
- **Done in code**: Pending verification tooling now has a dedicated `Pending` admin tab backed by `GET /api/users?verified=false`, with resend and manual verify actions.
- **Done in code**: `frontend/src/lib/api.js` is the sole frontend transport layer. It centralises credentials, CSRF, response parsing, typed status/data errors, abort signals, blobs/FormData, and 401 session notification for admin and non-admin flows.
- **Done in code**: Admin confirm/error UX now uses `AlertDialog` and `Toast`; the old browser `alert(...)` / `window.confirm(...)` paths are gone from the admin screens.
- **Done in code**: Admin create/edit user dialogs now surface the stronger password policy inline; public registration/reset flows already show the same policy.
- **Browser verification (2026-07-25)**: Authenticated admin login, cleanup dry-run, admin user create/delete with visible audit rows, Dropbox admin/user screens, and five-file bulk upload were exercised locally. No connected Dropbox account was available, so force sync/disconnect remains untested; cleanup apply was intentionally not run because the dry-run result may identify user files; admin reading another user's comic remains untested against seeded data.

**Validation performed**

- PHP syntax checks passed for changed backend files.
- `docker compose exec php php bin/console lint:container` passed.
- `docker compose exec php php bin/console doctrine:schema:validate --skip-sync` passed.
- Frontend Vite build passed with a temporary output directory: `npx vite build --outDir /tmp/panel-page-flip-admin-build --emptyOutDir`.
- `docker compose exec php ./vendor/bin/phpunit` ran but reported `No tests executed!`.
- Normal `npm run build` is blocked by an existing permissions issue on `frontend/dist/apple-touch-icon.png`.
- `npm run lint` is blocked by the repository ESLint flat-config issue.
- `npx vitest run` is blocked locally by an npm/Cursor path `ENOENT` issue.
- Route existence was checked for `api_admin_stats`, `api_admin_dropbox_users`, `api_admin_cleanup_dry_run`, `api_admin_audit_logs`, and `api_users_verify`.

### 3.1 — Wire `Add User` to the backend

- **What**: Make `AdminUsersList.handleCreateUser()` actually call the existing `POST /api/users` endpoint.
- **Why**: It currently just shows `alert('Create user functionality to be implemented with backend.')`.
- **Where**: `frontend/src/components/AdminUsersList.jsx::handleCreateUser`.
- **How**:
  ```jsx
  const handleCreateUser = async () => {
      if (!newUserData.email || !newUserData.password || !newUserData.name) {
          toast({ title: 'Missing fields', description: 'Name, email and password are required.', variant: 'destructive' });
          return;
      }
      const res = await fetch('/api/users', {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
          body: JSON.stringify(newUserData),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.message || 'Create failed');
      setUsers([...users, data.user]);
      setIsAddUserDialogOpen(false);
      toast({ title: 'User created' });
  };
  ```
  - Important: `UserController::create()` already creates users directly and does **not** currently send verification mail. Decide the intended admin-created-user behaviour explicitly:
    - Recommended default: admin-created users start with `isEmailVerified = true`.
    - Optional alternative: add a checkbox in the dialog, e.g. "Require email verification", and implement that branch deliberately.
  - Also apply the stronger password policy from [Task 2.1](#21--enforce-a-real-password-policy) here, not only in public registration/reset.
- **Verify**: Create a user from the dashboard, log in as them, no verification required.
- **Actual status**: Implemented. `AdminUsersList.handleCreateUser()` posts to `POST /api/users` through `frontend/src/lib/api.js`; `UserController::create()` marks admin-created users as verified and writes an audit row. The admin create/edit dialogs now surface the stronger password policy inline as well.

### 3.2 — Implement comic edit & view from admin list

- **What**: `handleEditComic` and `handleViewComic` in `AdminComicsList.jsx` only `console.log`.
- **Why**: Admin can't edit metadata or open comics for inspection.
- **Where**: `frontend/src/components/AdminComicsList.jsx`, reuse `frontend/src/components/ComicEditDialog.jsx`.
- **How**:
  - **View**: `navigate(`/read/${comicId}`)` — but the backend still assumes "reader = owner only" in multiple places. Update all of these together:
    - `ComicController::getPage()` must allow `ROLE_ADMIN`.
    - `ComicController::get()` must build cover URLs with the comic owner's ID, not the current admin's ID.
    - `ComicController::getCoverImage()` must allow admin access to another user's cover.
    - `ComicController::updateReadingProgressEndpoint()` should either become admin-aware or explicitly no-op in admin read-only mode so the reader does not break on page changes.
  - **Edit**: Open `ComicEditDialog` with the selected comic, submit `PATCH /api/comics/{id}` (the backend already permits admin).
- **Verify**: As an admin, click view/edit on someone else's comic — metadata loads, cover image loads, page turning works, and the reader does not 404 on progress updates.
- **Implemented**: Admin Comics view now navigates to `/read/{comicId}`; edit opens `ComicEditDialog` and saves with `PATCH /api/comics/{id}`. Backend page, cover, and progress endpoints were updated so admins can inspect another user's comic without writing admin reading progress.

### 3.3 — Return the comic owner in admin comic list

- **What**: When `adminContext=true`, include `owner: { id, email, name }` in the JSON returned by `GET /api/comics`.
- **Why**: The frontend already tries to display `comic.owner?.email` but the backend doesn't ship it.
- **Where**: `backend/src/Controller/ComicController.php::list()`.
- **How**: Inside the `if (!$adminContext)` block stays as-is; outside, build the JSON to include owner only when `$adminContext` is true (don't leak owner in non-admin responses).
- **Verify**: Owner column in admin Comics tab is populated.
- **Implemented**: `ComicController::list()` includes `owner: { id, email, name }` only when `adminContext=true` and the requester is an admin.

### 3.4 — New: Admin overview / stats panel

- **What**: New tab `Overview` in the admin dashboard with: total users, total verified users, total comics, total storage used (sum of `Comic.fileSize`), last 10 sign-ups.
- **Why**: Admins have no quick visibility on usage today.
- **Where**:
  - Backend: new controller `AdminController` at `/api/admin/stats` (GET, ROLE_ADMIN).
  - Frontend: new component `AdminOverview.jsx` and a tab in `AdminDashboard.jsx`.
- **How**: Use Doctrine query builder for counts/sums. Cache the result for 60 s with the Symfony cache pool to avoid hammering DB.
- **Verify**: Tab loads, numbers match the DB.
- **Implemented**: Added `/api/admin/stats`, cached for 60 seconds, and new `AdminOverview.jsx` tab with totals, storage, recent sign-ups, and cleanup controls.

### 3.5 — New: Admin "Pending verifications" tool

- **What**: List users where `isEmailVerified = false`, with a button to:
  - Resend verification email
  - Mark as verified manually (audit-logged)
- **Why**: Today there's no UI to unstick a user who lost the email.
- **Where**:
  - Backend: in `AdminController` (or `UserController`) add `GET /api/users?verified=false` and `POST /api/users/{id}/verify`.
  - Frontend: extend `AdminUsersList` with a "Verified?" column and an action.
- **Verify**: Mark a user as verified — they can log in immediately.
- **Actual status**: Implemented. `GET /api/users?verified=false` drives a dedicated `Pending` admin tab, and `POST /api/users/{id}/verify` marks a user verified and audit-logs the action. Admins can resend verification emails or verify accounts manually from that view.

### 3.6 — New: Admin Dropbox monitoring panel

- **What**: List users with a connected Dropbox, last sync time, total imported comics, and a "force sync" / "disconnect" button per row.
- **Why**: Admins can't see who is using Dropbox sync today.
- **Where**:
  - Backend: `AdminController::dropboxUsers()` returning `{ id, email, lastSyncedAt, dropboxComicCount }` for users with a token.
  - To get `lastSyncedAt`, add a `dropboxLastSyncedAt` column on `User` (new migration), set in `DropboxController::importSingle/sync` and `DropboxSyncCommand`.
  - Frontend: new tab `AdminDropbox.jsx`.
- **Verify**: After a Dropbox sync, the timestamp updates in the panel.
- **Implemented**: Added `User.dropboxLastSyncedAt`, admin Dropbox listing, force-sync, and disconnect actions. `DropboxController::importSingle/sync` and `DropboxSyncCommand` update the sync timestamp.

### 3.7 — New: Admin "Orphan files" cleanup UI

- **What**: Surface the existing `app:cleanup-comics` command as a UI action (dry-run + apply).
- **Why**: The CLI already finds orphaned files but admins shouldn't need shell access.
- **Where**:
  - Backend: `POST /api/admin/cleanup/dry-run` and `/apply`. They internally call the same logic as `CleanupComicsCommand` (extract that into a service so both the CLI and the controller can use it — Single Responsibility).
  - Frontend: button in the new Overview panel.
- **Verify**: Manually orphan a file (delete a row, leave the file) → dry-run lists it → apply removes it.
- **Implemented**: Added `ComicCleanupService`, `POST /api/admin/cleanup/dry-run`, and `POST /api/admin/cleanup/apply`. The existing `app:cleanup-comics` command now delegates to the service for normal orphan cleanup; the command's `--days` legacy path remains in the command.

### 3.8 — Audit log for admin actions

- **What**: Persist a row every time an admin creates, deletes, or promotes a user, or changes a comic they don't own.
- **Why**: Required for any system that handles user data — also tells you who promoted who.
- **Where**:
  - New entity `AdminAuditLog { id, adminUser, action, targetType, targetId, payloadJson, createdAt }`.
  - New service `AdminAuditService` injected into `UserController`, `ComicController`, `AdminController`.
  - Admin dashboard tab `AdminAuditList.jsx`.
- **Verify**: Promote a user — a row appears in the audit list.
- **Implemented**: Added `AdminAuditLog`, `AdminAuditService`, audit logging for admin user create/update/delete/verify, admin Dropbox actions, cleanup apply, and admin edits to comics owned by someone else. Added `AdminAuditList.jsx`.

### 3.9 — Centralise frontend API calls

- **What**: Create `frontend/src/lib/api.js` exporting `api.get/post/put/delete` that always:
  - Adds `credentials: 'include'`
  - Adds `X-XSRF-TOKEN` header (Task 1.9)
  - Throws on `!ok`
  - Refreshes session on 401
- **Why**: Today every component re-implements `fetch(...)` with subtly different error handling.
- **Where**: New `frontend/src/lib/api.js`. Replace direct `fetch(` calls in `AdminUsersList.jsx`, `AdminComicsList.jsx`, `AdminTagsList.jsx`, `UploadComicForm.jsx`, etc. progressively.
- **Verify**: All admin actions still work; CSRF is now mandatory.
- **Actual status**: Implemented. All frontend HTTP calls now use `frontend/src/lib/api.js`; only that transport module calls `fetch`. Unauthorized responses notify `AuthProvider`, while login/session probes can suppress that notification to avoid loops.

### 3.10 — Better confirm dialogs

- **What**: Replace `window.confirm(...)` and `alert(...)` calls in `AdminUsersList`, `AdminComicsList`, `AdminTagsList` with shadcn-ui `AlertDialog` / `Toast`.
- **Why**: The plain browser dialogs break the dark-mode UI and on mobile.
- **How**: Reuse `@/components/ui/alert-dialog` and `useToast`.
- **Actual status**: Implemented for the admin screens. Users/Comics/Tags now use `AlertDialog` and `Toast` instead of browser dialogs.

### 3.11 — Fix admin Tags tab so it actually shows admin data

- **What**: Make `AdminTagsList.jsx` request the real admin dataset and display the fields the backend actually returns.
- **Why**: Right now the admin Tags tab fetches `/api/tags` with no `adminContext=true&all=true`, so it only shows the current admin's own tags. It also expects `creator.username`, while the backend serialises `creator.name`.
- **Where**: `frontend/src/components/AdminTagsList.jsx`, optionally `TagController::search()` if you want admin-wide search there too.
- **How**:
  1. Fetch `/api/tags?all=true&adminContext=true` for the admin tab.
  2. Display `tag.creator?.name || tag.creator?.email`.
  3. If search is supported in admin mode, pass `adminContext=true` there as well.
  4. Re-test add/edit/delete once the backend per-user uniqueness change from [Task 1.11](#111--ensure-tags-are-user-scoped-everywhere) lands.
- **Verify**: As an admin, the Tags tab lists tags created by multiple users, and the creator column is populated.
- **Implemented**: `AdminTagsList.jsx` now fetches `/api/tags?all=true&adminContext=true` and displays `creator.name || creator.email`.

---

## Part 4 — Database migrations / "evolutions"

Doctrine migrations are the project's "evolutions". The current state is functional but messy.

### 4.1 — Normalise migration naming policy without breaking shipped environments

- **What**: Bring migrations back to the `VersionYYYYMMDDHHMMSS.php` convention, but do **not** rename a migration that has already shipped to any shared environment.
- **Why**: Doctrine sorts migrations by classname. The non-standard file is awkward, but renaming an already-run migration is riskier than living with one ugly filename.
- **Where**: `backend/migrations/`.
- **How**:
  1. Check whether `Version20250524_ComicCascadeDelete` has ever been applied outside local-only databases.
  2. If it has **not** shipped anywhere, rename file and class now.
  3. If it **has** shipped, leave it as-is, document the exception in `backend/migrations/README.md`, and make sure every **new** migration follows the standard timestamp class name.
- **Verify**: The migration list is understandable and future migrations follow the standard convention. Do not force a risky rename in production just for aesthetics.

### 4.2 — New migration: per-user unique tag name

- **What**: Add unique constraint `(creator_id, name)` on the `tag` table.
- **Why**: Backs [Task 1.11](#111--ensure-tags-are-user-scoped-everywhere). Stops two users colliding on tag names through race conditions.
- **How**: `php bin/console make:migration` after editing `Tag` entity to add `#[ORM\UniqueConstraint(name: 'uniq_tag_creator_name', columns: ['creator_id', 'name'])]`. Resolve any existing duplicates first by merging rows (write the data step in the same migration's `up()`).
- **Verify**: Trying to insert a duplicate via SQL fails.

### 4.3 — New migration: `comic.file_size`

- **What**: Add nullable bigint `file_size` to `comic`.
- **Why**: Backs [Task 1.13](#113--store-cbz-file-size-on-comic-entity) and quota/stats.
- **How**: Make the migration; backfill in a separate console command `app:backfill-comic-file-size` that scans the filesystem and updates each row.

### 4.4 — New migration: `user.dropbox_last_synced_at`

- **What**: Add nullable datetime_immutable column.
- **Why**: Backs [Task 3.6](#36--new-admin-dropbox-monitoring-panel).

### 4.5 — New migration: `admin_audit_log`

- **What**: Table for [Task 3.8](#38--audit-log-for-admin-actions).

### 4.6 — New migration: hashed reset / email-verification tokens

- **What**: For [Task 2.8](#28--hash-reset--email-verification-tokens-before-storing). Rename columns to `*_token_hash` and clear them once.

### 4.7 — Document migration policy

- **What**: Add a short `backend/migrations/README.md` explaining:
  - `make:migration` workflow
  - Always reversible (`down()` must work)
  - Never edit a migration that has shipped to production — write a new one
  - For data migrations, prefer a separate console command run by deploy
- **Why**: A junior dev should be able to add a migration without breaking prod.

### 4.8 — Make migrations part of the deploy pipeline

- **What**: Add `php bin/console doctrine:migrations:migrate --no-interaction` to the production deploy script (currently a TODO in `.github/workflows/build-frontend.yml`).
- **Why**: Today migrations must be run manually after each prod deploy — easy to forget.
- **Where**: `.github/workflows/build-frontend.yml` and a new `.github/workflows/deploy-backend.yml`.
- **How**: The production server already supports SSH (per `DEV_README.md`). Add a job that SSHs in, pulls, `composer install --no-dev`, runs migrations, clears cache, restarts php-fpm.
- **Verify**: A test PR with a no-op migration deploys cleanly.

---

## Part 5 — Multi-upload (multiple comics at once)

**Actual status (2026-07-25): Implemented and browser-verified.** `/upload/bulk` provides multi-select/drag-and-drop, inline titles, shared tags, per-file progress/status/cancel/retry, two-file concurrency, and completion links. Single and bulk uploads share `use-chunked-upload.js`. Five generated CBZ files uploaded successfully in an authenticated Chromium run and were removed afterward.

The chunked-upload backend was designed for one file at a time. We can support N files with limited backend changes and a richer frontend.

### 5.1 — Backend: nothing structural, but add the limits from Part 1

- **What**: Confirm [Tasks 1.6 / 1.7 / 1.8](#16--sanitise-chunked-upload-fileid-and-filename) are merged. They already enforce per-file size and per-user quota — those are the only backend constraints multi-upload needs.
- **Why**: A naive multi-upload that opens 10 parallel chunked uploads from the browser would otherwise blow the disk.

### 5.2 — Frontend: replace single-file picker with a file queue

- **What**: Turn `UploadComicForm.jsx` into "Upload a single comic", and create a new `BulkUploadComic.jsx` page accessible from `/upload?mode=bulk`.
- **Why**: The metadata fields (`title`, `author`, `tags`) make a single shared form awkward — for a bulk upload the user just drops files; we extract title from the filename (already done with `generateTitleFromFilename`) and rely on per-row edits afterwards.
- **Where**:
  - New file `frontend/src/pages/BulkUploadComic.jsx`.
  - New file `frontend/src/components/BulkUploadQueue.jsx` — table with one row per file, showing `name | size | progress | status | actions`.
  - Update `frontend/src/App.jsx` route.
  - Add a "Bulk upload" link in `Header.jsx` next to "Upload comic".
- **How**:
  1. `<input type="file" multiple accept=".cbz" />` with drag-and-drop accepting multiple files.
  2. State: `files: Array<{ id, file, progress, status, error, comicId? }>`.
  3. Configurable concurrency `MAX_PARALLEL_FILES` (default 2). Inside each file we still use `concurrentUploads` chunks like today.
  4. Per file, build a small state machine: `idle → initialising → uploading → completing → done | error`.
  5. Reuse the existing `/api/comics/upload/init|chunk|complete` endpoints — extract the chunk-upload logic from `UploadComicForm.jsx` into a shared hook `useChunkedUpload(file, metadata, options)`.
  6. Title for each file is derived from the filename via the existing `generateTitleFromFilename` helper. Allow inline editing of title before clicking "Start all".
  7. A single "Tags" input applies to all files in the batch (DRY); per-file overrides via row expansion.
  8. After all complete, show a summary with links to the comic detail / admin edit dialog.
- **Verify**: Drop 5 CBZs, click "Start all", they upload 2-by-2, progress bars update, all 5 appear on the dashboard.

### 5.3 — Frontend: reuse `useChunkedUpload`

- **What**: Extract the upload logic from `UploadComicForm.jsx` into `frontend/src/hooks/use-chunked-upload.js`.
- **Why**: SOLID / DRY. Without this, the bulk uploader and the single uploader will diverge.
- **How**: The hook returns `{ start, cancel, status, progress, comic? }` and accepts `{ file, metadata, csrfToken, signal }`.
- **Verify**: Single-file upload page still works exactly as before.

### 5.4 — Optional: server-side resumable uploads

- **What**: Make `/api/comics/upload/init` idempotent based on `fileId`, so an aborted upload can be resumed.
- **Why**: For a 500 MB file on a flaky connection it's a big UX win, and lays groundwork for any future "upload from phone" feature.
- **How**: When `init` is called with an existing `fileId`, return the chunks already received from `metadata.json`. Frontend reads the response and skips already-uploaded chunks.
- **Verify**: Cut Wi-Fi mid-upload, reconnect, click "Resume" — only missing chunks are sent.

### 5.5 — Optional: admin "bulk import from server folder"

- **What**: Wrap the existing `app:import-comics` console command behind a UI action in the admin dashboard.
- **Why**: For seeding a server with hundreds of comics during initial setup.
- **How**: Same pattern as [Task 3.7](#37--new-admin-orphan-files-cleanup-ui).

---

## Part 6 — General clean-up & quality-of-life

### 6.1 — Delete obsolete debug routes / endpoints

- **What**: Remove `/api/comics/test`, `/api/ping`, and `ApiTestController.php` from prod.
- **How**: Wrap in `kernel.environment !== 'prod'` or just delete.

### 6.2 — Remove `console.log` noise from frontend

- **What**: `rg "console\.(log|warn)\(" frontend/src` — replace with the `logger` helper already defined in `UploadComicForm.jsx`. Promote `logger` to `frontend/src/lib/logger.js`.

### 6.3 — Add basic tests where there are none

- **Backend**: Add PHPUnit tests for:
  - `UserController` admin endpoints (list, create, update, delete) — happy path + 403s.
  - `ComicController::create` (single shot upload) and chunked endpoints (init/chunk/complete).
  - `ComicService` cover extraction.
- **Frontend**: Add Vitest tests for:
  - `AdminUsersList` create/edit/delete flows (mock `fetch`).
  - `useChunkedUpload` hook.

### 6.4 — Add OpenAPI/Swagger documentation

- **What**: Install `nelmio/api-doc-bundle`, annotate controllers, expose `/api/doc` (admin-only).
- **Why**: Today the API surface is documented only in `README.md`, drifting from reality.

### 6.5 — Move email routing back to async in production

- **What**: `config/packages/messenger.yaml` has the email routing commented out so emails send synchronously. For production, uncomment and set up a Messenger consumer as a systemd service.
- **Why**: Currently a slow SMTP server stalls every login/registration request.

### 6.6 — Production runbook

- **What**: Create `docs/RUNBOOK.md` with:
  - How to rotate `APP_SECRET`
  - How to take a DB backup
  - How to roll back a bad deploy (frontend FTP, backend git revert)
  - How to add an admin user via CLI (`app:create-admin-user`)
  - Where logs live and how to read them
  - Emergency restore procedure
  - Note: `README.md` / `DEV_README.md` mention `.github/workflows/emergency-backend-restore.yml`, but that file is **not** present in this repo right now. Either create it or remove those references while writing the runbook.

### 6.7 — Tighten file permissions on uploads

- **What**: Replace every `mkdir(..., 0777)` and `chmod(..., 0777)` with `0775` (dir) / `0644` (file). Make sure the `setup.sh` script in Docker `chown`s `public/uploads/` to the php user, not world-writable.
- **Why**: 0777 lets any other container or process write to the upload dirs.

### 6.8 — Prune `error_log` cleanup once Task 1.10 is done

- **What**: Search for any leftovers and confirm they're behind a `dev`-only guard, e.g.:
  ```php
  if ($this->getParameter('kernel.environment') === 'dev') {
      $this->logger->debug(...);
  }
  ```

### 6.9 — Health-check endpoint

- **What**: Add `GET /api/health` (public) returning `{status: ok, db: ok, mailer: ok, dropbox: ok}` for uptime monitoring (e.g. UptimeRobot). Cache 30 s.

### 6.10 — Clean up abandoned chunk-upload temp directories

- **What**: Add scheduled cleanup for stale directories under `sys_get_temp_dir() . '/comic_uploads'`.
- **Why**: `ComicController::cleanupTempDirectory()` only runs after a successful `completeUpload()`. Failed, cancelled, or interrupted uploads leave chunk files behind forever, which becomes more important once bulk upload lands.
- **Where**: extract temp-upload cleanup into a service and call it from:
  - a new console command, e.g. `app:cleanup-temp-uploads`
  - optionally at the start of `initUpload()` for very old directories
- **How**:
  1. Define a TTL, e.g. delete upload dirs older than 24 hours based on `metadata.json` timestamp or file mtime.
  2. Remove only directories inside the known temp root.
  3. Log how many upload dirs / bytes were cleaned.
- **Verify**: Create a fake old temp upload directory, run the cleanup command, confirm it is removed while active uploads remain untouched.

---

## Appendix A — Test checklist

After each part, run through the relevant flows manually.

### Authentication
- [ ] Register new user → verification email arrives in Mailpit → click link → account verified
- [ ] Login → redirected to dashboard
- [ ] Forgot password → email arrives → reset form → success email arrives
- [ ] Logout → redirected to landing
- [ ] Re-login after `APP_SECRET` rotation forces logout (expected)

### Upload (single)
- [ ] Click "Upload Comic", select a 50 MB CBZ → progress bar moves, success toast
- [ ] Try uploading a `.zip` renamed to `.cbz` → rejected (Task 1.8)
- [ ] Try upload while offline → cancel works, no half-state

### Upload (bulk)
- [ ] Drop 5 CBZs → all queued, max 2 in parallel → all complete
- [ ] Cancel one mid-upload → others continue
- [ ] After complete, all comics appear on dashboard

### Admin
- [ ] Open `/admin` as a non-admin → redirected
- [ ] Create user → user appears in list and can log in
- [ ] Edit user roles (not yourself) → effective immediately
- [ ] Try to demote yourself → 403 (Task 2.7)
- [ ] Edit a comic owned by another user → success
- [ ] View a comic owned by another user → reader opens (Task 3.2)
- [ ] Pending verifications panel shows new unverified user, "Verify" button works
- [ ] Dropbox panel shows connected users with last-sync time
- [ ] Audit log shows your recent admin actions

### Security smoke tests
- [ ] `curl -X POST /api/comics/upload/init -d '{"fileId":"..","filename":"x.cbz","totalChunks":1}' -H "Cookie: PHPSESSID=..."` returns 400
- [ ] `curl -X PUT /api/users/<other-id> -d '{"roles":["ROLE_ADMIN"]}'` as non-admin returns 403
- [ ] `curl /api/comics/test` in prod returns 404
- [ ] DB dump shows no plaintext Dropbox tokens, no plaintext reset tokens

---

## Appendix B — File-by-file summary of issues

This is a quick lookup; cross-reference with the tasks above.

### `backend/.env`
- `APP_SECRET` hard-coded → **Task 1.1**
- `DATABASE_URL` defaults to PostgreSQL but Docker uses MySQL — set the correct one.

### `backend/config/packages/security.yaml`
- Access control too narrow on `/api/users` → **Task 1.4**
- Add CSRF on writes → **Task 1.9**

### `backend/config/packages/framework.yaml`
- Cookie flags only `auto`/`lax` → **Task 2.4**

### `backend/config/packages/nelmio_cors.yaml`
- Trusts a single env var pattern; document and lock down → **Task 2.3**

### `backend/config/routes.yaml`
- Double registration of controllers → **Task 1.3**

### `backend/src/Controller/AuthController.php`
- `/api/logout_user` is a parallel logout endpoint that bypasses Symfony's logout — confusing; consolidate after CSRF/JWT migration.

### `backend/src/Controller/ApiTestController.php`
- `/api/ping` and `/api/ping-auth` are dev tools; restrict to `dev` only → **Task 6.1**.

### `backend/src/Controller/ComicController.php`
- `error_log()` everywhere → **Task 1.10**
- `getPage` only allows owner — admin can't read others' comics → **Task 3.2**
- `get()` builds cover URLs with the current user's ID instead of the comic owner's ID → **Task 3.2**
- Chunked upload `fileId`/`filename` not sanitised → **Task 1.6**
- No size or quota check → **Task 1.7**
- `getCoverImage` rejects admin access → align with Task 3.2 (admin = OK)
- `updateReadingProgressEndpoint()` is owner-only, so admin reader mode breaks unless handled explicitly → **Task 3.2**
- `/api/comics/test` public-ish leak → **Task 1.14**
- Tag lookup not user-scoped → **Task 1.11**
- No cleanup path for abandoned temp chunk uploads → **Task 6.10**

### `backend/src/Controller/UserController.php`
- `me` endpoint sits under `/api/users` → **Task 1.5**
- Update endpoint allows non-admin self-edit (OK) but missing self-demote guard → **Task 2.7**
- Create endpoint exists but frontend doesn't call it → **Task 3.1**

### `backend/src/Controller/TagController.php`
- Tag uniqueness checked globally instead of per-creator → **Task 1.11**, **Task 4.2**

### `backend/src/Controller/DropboxController.php`
- `dump()` debug calls → **Task 1.2**
- Tokens stored plaintext → **Task 1.12**
- No refresh handling → **Task 2.5**
- `error_log()` exposes file paths → **Task 1.10**
- Imported file path goes through `dropbox/` and then again through `ComicService` causing a leftover file in `dropbox/` → fix in **Task 5.1**'s scope: clean up `$localPath` after the service moves it (delete `$dropboxDirectory` content after use).

### `backend/src/Controller/ResetPasswordController.php`
- Password complexity = NotBlank → **Task 2.1**
- No rate limit → **Task 2.2**

### `backend/src/Controller/RegistrationController.php`
- Password min 6 chars only → **Task 2.1**
- No rate limit → **Task 2.2**
- Email enumeration prevention OK; keep that.

### `backend/src/Controller/EmailVerificationController.php`
- Verification tokens are stored plaintext on the user row → **Task 2.8**
- `POST /api/email-verification/resend` should be rate-limited too → **Task 2.2**

### `backend/src/Controller/ShareController.php`
- Path-traversal mitigations are present but ad-hoc (`str_replace(['../','..\\','./'])`); replace with `basename()` + a regex similar to **Task 1.6**.
- Sharer's email is reply-to but `from` is a system address — that's fine; just ensure `replyTo` matches the actual sender's verified email (no spoofing).

### `backend/src/Service/ComicService.php`
- Same `error_log` issues → **Task 1.10**
- No size/MIME checks → **Tasks 1.7, 1.8**
- Tag lookup not user-scoped → **Task 1.11**
- `chmod 0777` everywhere → **Task 6.7**

### `backend/src/Entity/User.php`
- Dropbox tokens unencrypted → **Task 1.12**

### `backend/migrations/`
- One file outside the `VersionYYYYMMDDHHMMSS` convention → **Task 4.1**
- Plus several new migrations needed → **Tasks 4.2 – 4.6**

### `frontend/src/App.jsx`
- `AdminRoute` reads from `localStorage` user → **Task 2.6**

### `frontend/src/hooks/use-auth.jsx`
- Stores user in localStorage including roles → **Task 2.6**

### `frontend/src/components/AdminUsersList.jsx`
- `handleCreateUser` is a stub → **Task 3.1**
- Uses `window.confirm`/`alert` → **Task 3.10**

### `frontend/src/components/AdminComicsList.jsx`
- `handleEditComic`/`handleViewComic` are stubs → **Task 3.2**
- Owner field expected but never sent → **Task 3.3**

### `frontend/src/components/AdminTagsList.jsx`
- Fetches `/api/tags` without admin params, so the admin tab only shows the current admin's own tags → **Task 3.11**
- Expects `creator.username` while the backend returns `creator.name` → **Task 3.11**
- Tag uniqueness assumed global → **Task 1.11** (after backend fix, also drop the front-end "name already exists" toast that compares across users).

### `frontend/src/components/UploadComicForm.jsx`
- Only single-file → **Task 5.2**
- Lots of inline `console.log`/`logger` calls — move to global helper → **Task 6.2**

### Stale duplicates
- `backend/src/Controller/ShareController.php.new`
- `frontend/src/components/PendingSharesAlert.jsx.new`
- `frontend/src/hooks/use-pending-shares.jsx.new`
- → **Task 1.15**

---

## Suggested execution order (TL;DR)

If you can only do one thing per day, do it in this order. Each item is roughly one PR.

1. **Day 1**: Tasks 1.1, 1.2, 1.15 (rotate secret, kill dumps, delete `.new` files).
2. **Day 2**: Tasks 1.3, 1.4, 1.5 (routing/security cleanup).
3. **Day 3**: Tasks 1.6, 1.7, 1.8, 1.13 (upload hardening + size column).
4. **Day 4**: Task 1.9 (CSRF) — the biggest single PR; review carefully.
5. **Day 5**: Tasks 1.10, 1.11, 1.14, 4.1, 4.2 (logging, tag scoping, route cleanup, migration tidy).
6. **Day 6**: Tasks 1.12, 2.5 (Dropbox encryption + refresh).
7. **Day 7**: Tasks 2.1, 2.2, 2.7, 2.8 (password policy, rate limits, self-demote, hashed tokens).
8. **Day 8**: Tasks 2.3, 2.4, 2.6, 6.7 (CORS / cookie / headers / file perms).
9. **Day 9**: Tasks 3.1, 3.2, 3.3, 3.10, 3.11 (fix the broken admin actions and the broken admin tags scope).
10. **Day 10**: Tasks 3.4, 3.5, 3.6, 3.7, 3.8 (new admin features + audit log).
11. **Day 11**: Tasks 5.1, 5.2, 5.3 (multi-upload).
12. **Day 12+**: Polish — Tasks 6.x, 4.7, 4.8, 5.4, 5.5.

Good luck — and remember: **commit small, test often, never let the bot push to `main`.**
