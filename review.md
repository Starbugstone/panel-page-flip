# Production-readiness review: `develop` → `main`

Review date: 2026-08-29
Reviewed head: `a3648c08a6a72213d4cfa4b6e9a42773133625ff` (`develop`, synchronized with `origin/develop`)
Merge base / current `main`: `0cb57f364f15147d7fd54742fbdfe92ad2f6c4d7`
PR: <https://github.com/Starbugstone/panel-page-flip/pull/165>

## Verdict

Do **not** merge or deploy the reviewed head yet.

The application is broadly healthy: the frontend suite, production build, dependency audits, static analysis, fresh-schema migrations, and browser smoke tests pass. The immediate blocker is one deterministic backend test failure. There is also meaningful maintainability work if the release criterion is literally “no overcomplicated or dead code.”

No implementation from the interrupted fix attempt remains in the worktree. While this handoff was being written, separate concurrent edits appeared in `frontend/src/index.css`, `frontend/src/pages/ComicReader.jsx`, and the new `frontend/src/hooks/use-reader-controls-height.js`. They were not made, reverted, or reviewed as part of this audit. The next agent must inspect and validate them independently against the reviewed head before continuing.

## Progress log

Updated 2026-08-30. Findings 1, 2, 5 and 6 are done and verified green:

- **1 — sharing self-share drift.** `ComicShareService` is the sole authority;
  the controller copy is gone. `ShareControllerTest`, `SharingWorkflowControllerTest`
  (data-driven over email/uppercased email/username/user code) and
  `UsernameIdentityTest` all pass — 78 tests.
- **2 — dead Twig/Form stack.** `base.html.twig`, `registration/register.html.twig`
  and `RegistrationFormType` deleted, `symfony/form` dropped from
  `composer.json`/`composer.lock`. `lint:twig templates` is green and now runs in
  CI and in the CLAUDE.md pre-push list.
- **5 — React `act(...)` warning.** `IS_REACT_ACT_ENVIRONMENT` set in
  `frontend/src/test/setup.js`; the suite runs warning-free.
- **6 — production CORS.** `PROD_CORS_ALLOW_ORIGIN` is required in
  `REQUIRED_PROD_VARS`, the old `^https://.*$` fallback is gone, and the build
  refuses that value by name.

**3 — complexity.** 55 warnings → 18. Decomposed so far: `ComicReader`,
`ShareComicsDialog`, `MetadataSuggestions`, `Dashboard`, `Sharing`,
`AdminUsersList`, `AdminUserDetails`, `SharingCodesCard`, `DropboxSyncPage`,
`ComicTableView`, `ComicCard`, `Header`, `ReaderSettings`, `SinglePageReader`,
`usePageImageCache`, `useReaderTransform`, `AdminComicFormats`,
`UserMetadataCredentials`, `Landing`, `LegalPages`, `ReportContent`,
`ResetPassword`, `TagProvider`, `ComicEditDialog`.

The quota feature from #167 was fast-forwarded into this worktree. Its
`AdminStorageQuotaForm` now lives in the extracted `AdminUserOverviewTab`, so
the new control does not reverse the `AdminUserDetails` decomposition. The
frontend suite is green at 104 files and 1,008 tests after integration.

New pure modules carry their own tests, because most of what was decomposed had
no direct coverage: `lib/library-view` (22), `lib/admin-user-roles` (15),
`lib/comic-selection` (8), `lib/comic-capabilities` (3), plus
`hooks/use-library-location` (7).

Still over the thresholds, largest first: `UserSettings` (302),
`AdminTagsList` (283), `Login` (262),
`ShareInvitation` (261, plus a 176-line nested function),
`AdminSharingCodesList` (255), `SearchBar` (255),
`AdminContentReports` (237, complexity 29), `AdminComicsList` (223),
`AdminMetadataProviders` (221), `AdminSharesList` (220, plus a
complexity-24 callback), `TagCombobox` (205), `BulkUploadQueue` (184),
`EmailVerification` (175), `UploadComicForm` (167, complexity 21).

The ESLint gate itself is **not yet enabled** — it goes in last, once the tree
satisfies it. Frontend suite: 104 files, 1006 tests, green. `npm run lint` green.

## Scope and evidence

This was a full-tree review of the resulting application, including code inherited from `main`, not only `main...develop` changes.

The branch contains 247 changed files relative to `main`, about 12,380 insertions and 1,478 deletions. High-risk areas reviewed and exercised included deployment/configuration, migrations, sharing, content reports, bulk uploads, advertising, authentication, and reader behavior.

Checks run:

- Git worktree cleanliness, remote synchronization, merge-base and merge-conflict check.
- GitHub PR/check/review-thread state.
- Frontend ESLint, Vitest, production dependency audit, route/CSP/tool consistency, production Vite build, and SEO validation.
- Backend Composer validation/audit, Symfony container lint in test and prod, PHPStan, PHP-CS-Fixer dry run, fresh MySQL migrations, Doctrine schema validation, PHPUnit, and prod cache warmup.
- Bash syntax checks for deployment scripts.
- Twig and YAML lint.
- Duplicate-code scan with `jscpd` over production PHP/JS/JSX.
- Additional ESLint complexity and function-length audit over production frontend code.
- Production-style browser smoke tests at desktop and mobile widths.
- Read-only baseline checks against the currently deployed public site.

## 1. Merge blocker: sharing contract/test drift

### Evidence

The full backend run finished with:

```text
Tests: 1311, Assertions: 4852, Failures: 1, Skipped: 1.
```

The deterministic failure is:

```text
App\Tests\Functional\Controller\ShareControllerTest::testAComicCannotBeSharedWithItsOwner
Failed asserting that 'You cannot share a comic with yourself.' contains "already own".
```

Relevant locations:

- `backend/src/Service/ComicShareService.php:802-815`
- `backend/tests/Functional/Controller/ShareControllerTest.php:579-588`
- `backend/src/Controller/SharingWorkflowController.php:163-178`
- `backend/tests/Functional/Controller/SharingWorkflowControllerTest.php:121-134`

The latest copy commit changed the service response from `You already own this comic.` to `You cannot share a comic with yourself.`, but the older functional test was not updated. Both current GitHub backend jobs fail for the same reason; the PR is `MERGEABLE` but `UNSTABLE`.

There is a second maintainability problem behind the failed assertion: self-share validation exists in both `SharingWorkflowController` and `ComicShareService`. The controller path returns HTTP 409 for a resolved username/user-code, while the service returns HTTP 400 for an email. That duplicates one domain invariant and allows message/status behavior to drift by recipient type.

### Recommended solution

Make `ComicShareService::assertInvitableRecipient()` the single authority:

1. Keep the canonical message in the service: `You cannot share a comic with yourself.`
2. Keep one canonical status, currently HTTP 400, for all recipient forms.
3. Remove the controller-level `recipientUser->getId() === $user->getId()` response from `SharingWorkflowController::bulkInvite()`; resolve the public identity to its normalized email and allow the service to reject it.
4. Change `ShareControllerTest.php:587` to an exact assertion on the new canonical sentence.
5. Add data-driven functional cases proving that email, username, and user code all produce the same 400 response and message.
6. Rerun the single failing test, the sharing controller suites, and then all backend tests.

Suggested focused commands:

```bash
docker compose exec -T php php bin/phpunit \
  --filter testAComicCannotBeSharedWithItsOwner \
  tests/Functional/Controller/ShareControllerTest.php

docker compose exec -T php php bin/phpunit \
  tests/Functional/Controller/ShareControllerTest.php \
  tests/Functional/Controller/SharingWorkflowControllerTest.php
```

## 2. Dead server-rendered registration stack and failing Twig lint

### Evidence

`php bin/console lint:twig templates` fails:

```text
ERROR in templates/base.html.twig
Unknown function "importmap".
```

Relevant files:

- `backend/templates/base.html.twig:11`
- `backend/templates/registration/register.html.twig`
- `backend/src/Form/RegistrationFormType.php`
- `backend/composer.json` (`symfony/form`)

Repository search found no controller rendering `registration/register.html.twig`, no use of `RegistrationFormType`, and no other Symfony form types. Registration is implemented by the JSON endpoint in `backend/src/Controller/RegistrationController.php` and the React UI.

The email templates still require Twig, so Twig itself must remain. Only the stale HTML base/registration templates and Form component are dead.

### Recommended solution

1. Delete:
   - `backend/templates/base.html.twig`
   - `backend/templates/registration/register.html.twig`
   - `backend/src/Form/RegistrationFormType.php`
2. Remove the direct `symfony/form` requirement from `backend/composer.json`.
3. Update `backend/composer.lock` inside the PHP container so platform extensions match CI:

   ```bash
   docker compose exec -T php composer update symfony/form \
     --with-all-dependencies --no-interaction --no-progress
   ```

   This should remove `symfony/form` and, if no other package requires it, `symfony/polyfill-intl-icu`. Review the lock diff rather than accepting unrelated package upgrades.
4. Run Composer validation, container lint, static analysis, all backend tests, and Twig lint.
5. Add Twig lint to CI so stale templates cannot silently become invalid again:

   ```bash
   php bin/console lint:twig templates
   ```

Do not remove `symfony/twig-bundle` or Twig packages; services render email templates through them.

## 3. High frontend complexity and oversized functions

### Evidence

An additional audit using thresholds of complexity 20 and 120 nonblank/noncomment lines per function found **55 warnings in production frontend code**:

- 14 complexity warnings.
- 41 oversized-function warnings.

This is not currently an official lint gate, so ordinary `npm run lint` still passes. The numbers are diagnostic, not proof of bugs, but several components clearly mix data fetching, state transitions, domain decisions, effects, event handling, and large render trees in one function.

### Highest-priority hotspots

| File/function | Approximate size | Complexity | Recommended boundary |
| --- | ---: | ---: | --- |
| `frontend/src/pages/ComicReader.jsx:53` | 619 lines | 83 | Controller hook + viewport/chrome/dialog components |
| `frontend/src/components/ShareComicsDialog.jsx:93` | 689 lines | 79 | Reducer/workflow hook + recipient/comic/review/result steps |
| `frontend/src/components/MetadataSuggestions.jsx:76` | 344 lines | 40 | Candidate card + suggestion list + tag/classification sections |
| `frontend/src/pages/Dashboard.jsx:28` | 306 lines | 44 | Query/controller hook + toolbar/library/empty-state views |
| `frontend/src/components/AdminUsersList.jsx:47` | 533 lines | 34 | Query/filter hook + row/actions/dialog components |
| `frontend/src/components/ComicTableView.jsx:29` | 283 lines | 35 | Selection hook + row/table actions |
| `frontend/src/components/ComicEditDialog.jsx:46` | 262 lines | 33 | Form-state hook + metadata/tag/folder sections |
| `frontend/src/components/ComicCard.jsx:27` | 286 lines | 33 | Actions/menu/metadata presentation components |
| `frontend/src/pages/AdminUserDetails.jsx:52` | 369 lines | 30 | Data hook + account/security/storage/sharing sections |
| `frontend/src/components/AdminContentReports.jsx:26` | 237 lines | 29 | Filters/list/detail/action panels |

### Remaining oversized production functions

- `AdminComicFormats` — 132 lines.
- `AdminComicsList` — 223 lines.
- `AdminMetadataProviders` — 221 lines.
- `AdminSharesList` — 220 lines; one nested callback has complexity 24.
- `AdminSharingCodesList` — 255 lines.
- `AdminTagsList` — 283 lines.
- `BulkUploadQueue` — 184 lines.
- `Header` — 125 lines.
- `SearchBar` — 255 lines.
- `SharingCodesCard` — 368 lines.
- `TagCombobox` — 205 lines.
- `UploadComicForm` — 167 lines, complexity 21.
- `UserMetadataCredentials` — 154 lines.
- `ReaderSettings` — 168 lines.
- `SinglePageReader` — complexity 23.
- `usePageImageCache` — 138 lines.
- `useReaderTransform` — 127 lines.
- `TagProvider` — 132 lines.
- `DropboxSyncPage` — 346 lines; one nested function is 161 lines.
- `EmailVerification` — 175 lines.
- `Landing` — 178 lines.
- `PrivacyPolicy` — 136 lines.
- `Login` — 262 lines.
- `ReportContent` — 145 lines.
- `ResetPassword` — 198 lines.
- `ShareInvitation` — 261 lines; one nested function is 176 lines.
- `Sharing` — 547 lines; nested functions of 163 and 135 lines.
- `UserSettings` — 302 lines.

### Recommended refactoring sequence

Refactor behavior-preservingly, one component at a time, while keeping its existing tests green. Avoid one large rewrite.

#### `ComicReader`

- Keep `ComicReader` as composition/orchestration only.
- Extract comic/page loading into `useComicReaderData(comicId)`.
- Extract progress persistence/navigation into `useReaderProgress(...)`.
- Extract fullscreen, toolbar visibility, keyboard focus, and settings-dialog state into a UI controller hook or reducer.
- Move toolbar/chrome markup to `ReaderChrome`.
- Move thumbnails/settings/fit-suggestion composition to focused components.
- Retain existing helpers such as `usePageImageCache`, `useReaderTransform`, and reader gesture/page modules rather than reimplementing them.
- Preserve all reader regression tests, especially zoom persistence, page-at-top behavior, gestures, and single/spread/continuous mode transitions.

#### `ShareComicsDialog`

- Model the workflow with `useReducer` or a `useShareComicsWorkflow` hook instead of many independent state flags.
- Extract `RecipientStep`, `ComicSelectionStep`, `ResponsibilityStep`, and `ShareResults`.
- Keep API calls and state transitions in the hook; keep step components mostly presentational.
- Centralize recipient normalization and request-payload construction.
- Preserve the current single workflow used by all entry points; do not recreate separate dialogs.

#### `MetadataSuggestions`

- Extract one reusable `MetadataCandidateCard` for the duplicated candidate header, confidence badge, field suggestions, and “use all” action.
- Support optional cover art/action buttons through props or slots rather than maintaining two nearly identical markup blocks.
- Extract classification and suggested-tag sections if the parent remains over the target.

#### `Dashboard` and admin screens

- Move query/filter/pagination/mutation state into hooks.
- Split large conditional render trees into named sections.
- Keep dialogs close to the mutation they represent, but avoid duplicating request/toast/invalidation logic.
- Use the existing API and test mocks; do not introduce a second data layer.

### Maintainability gate

After the intentional refactors, make complexity enforceable rather than relying on an ad hoc audit. Add ESLint rules with thresholds the cleaned tree actually satisfies, for example:

```js
complexity: ["error", 20],
"max-lines-per-function": ["error", {
  max: 120,
  skipBlankLines: true,
  skipComments: true,
}],
```

Tests may need a somewhat higher function-length threshold or test-specific override because long scenario suites are less risky than long production orchestrators. Do not suppress individual production components merely to get green CI.

Audit command used:

```bash
cd frontend
npx eslint src \
  --ignore-pattern '**/*.test.*' \
  --rule 'complexity: [warn, 20]' \
  --rule 'max-lines-per-function: [warn, {max: 120, skipBlankLines: true, skipComments: true}]' \
  --no-warn-ignored
```

## 4. Duplicate code

### Evidence

The duplicate scan was reassuring overall:

```text
496 files analyzed
18 clones
272 duplicated lines
0.42% duplicated lines
```

There is no widespread copy/paste problem. Most matches are framework headers, Doctrine entity accessors, or command/controller boilerplate where an abstraction may be worse than the duplication.

Detected clone groups:

- `ComicVineProvider.php` ↔ `MetronProvider.php` response-processing block.
- `MetadataProviderSecretsSubscriber.php` ↔ `UserSecretsSubscriber.php` subscriber structure.
- `EmailVerificationToken.php` ↔ `ResetPasswordToken.php` entity accessors.
- Two admin user lookup/authorization blocks inside `UserController.php`.
- Two query/filter blocks inside `TagController.php`.
- `RegistrationController.php` ↔ `UserController.php` initial validation/import structure.
- Two folder lookup/authorization blocks inside `LibraryFolderController.php`.
- `AdminShareController.php` ↔ `AdminUserWarningController.php` controller structure.
- Two admin list/action blocks inside `AdminController.php`.
- `AdminContentReportController.php` ↔ `AdminShareController.php` controller structure.
- `TestApiEndpointsCommand.php` ↔ `TestMailCommand.php` namespace/import structure.
- `ResetUserPasswordCommand.php` ↔ `TestEmailVerificationCommand.php` command structure.
- `DropboxSyncCommand.php` ↔ `GenerateSampleDataCommand.php` command boilerplate.
- `CreateUserCommand.php` ↔ `ResetUserPasswordCommand.php` command boilerplate.
- `CreateAdminUserCommand.php` ↔ `ResetUserPasswordCommand.php` command boilerplate.
- `CleanupComicsCommand.php` ↔ `CleanupLogsCommand.php` command boilerplate.
- Two candidate-card markup blocks inside `MetadataSuggestions.jsx` (reported as two overlapping JSX clones).

### Recommended solution

- Fix the `MetadataSuggestions` duplication by extracting `MetadataCandidateCard`; it is real UI duplication and is already part of a high-complexity component.
- Fix the sharing-message duplication as described in finding 1; it already caused drift.
- Consider small private helpers for repeated entity lookup/authorization inside a single controller when doing nearby work.
- Do **not** create broad base controllers, base commands, or Doctrine inheritance solely to eliminate small structural clones. The measured total is already low, and those abstractions would add coupling for little value.
- If a duplicate gate is added, use a modest ceiling such as 1% and exclude tests/vendor/generated files. A goal of literal 0% would encourage harmful abstractions.

Audit command used:

```bash
npx --yes jscpd@4.0.5 \
  --min-lines 8 \
  --min-tokens 70 \
  --format javascript,jsx,php \
  --reporters console \
  --ignore '**/*.test.*,**/node_modules/**,**/vendor/**' \
  frontend/src backend/src
```

## 5. React test warning

### Evidence

All 937 frontend tests pass, but `src/hooks/derived-state.test.jsx` emits twice:

```text
The current testing environment is not configured to support act(...)
```

The warning occurs in the `useIsMobile > follows the viewport when it changes` test. The test wraps listener delivery in `act`, but React 19 also expects the test environment flag.

### Recommended solution

Set the React act-environment flag in `frontend/src/test/setup.js`:

```js
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
```

Then rerun the single DOM test and the complete frontend suite. Do not silence `console.error`; the objective is a warning-free test environment.

## 6. Deployment hardening and unverified release artifact

### Evidence

`scripts/.env.deploy` is absent in the reviewed workspace. This is correct for a gitignored secret file, but it means the exact release package and target production configuration were not built or verified.

`scripts/build-release.sh` also correctly refuses to build unless local `HEAD` exactly matches `origin/main`, so it cannot perform the final release build while the code is still on `develop`.

The script currently falls back to a broad production CORS regex:

```bash
write_dotenv CORS_ALLOW_ORIGIN "${PROD_CORS_ALLOW_ORIGIN:-^https://.*$}"
```

This conflicts with the documented same-origin deployment model. Credentialed cross-origin requests are not currently enabled, so this is defense-in-depth rather than an observed data exposure, but production defaults should fail closed.

### Recommended solution

1. Add `PROD_CORS_ALLOW_ORIGIN` to `REQUIRED_PROD_VARS` in `scripts/build-release.sh`, or safely derive an exact regex from `PUBLIC_URL`.
2. Prefer requiring the explicit value because `scripts/.env.deploy.example` already documents it and regex escaping is easy to get wrong.
3. Validate it is nonempty and does not equal `^https://.*$` for production.
4. After merge, switch to/update `main` so `HEAD === origin/main`.
5. Create `scripts/.env.deploy` from the example with mode 600 and real values. Never commit it.
6. Confirm a current database + uploads backup and preserve `APP_DATA_KEY`.
7. Build and deploy frontend and backend together; do not deploy only `frontend/dist`.
8. Run migrations, cache warmup, and post-deploy health checks through the documented backup-gated release flow.

## 7. Browser verification note

The configured Playwright MCP attempted to launch `/opt/google/chrome/chrome`, which is absent. The provided browser doctor passed with the bundled Chromium runtime. The smoke test therefore used that same verified local Playwright runtime directly with its required library path.

Verified against `http://127.0.0.1:8080` at 1440×900 and 390×844:

- `/`
- `/login`
- `/privacy`
- `/report-content`

All returned 200, rendered the expected title/H1, had no console errors, had no failed requests, and had no horizontal overflow.

After frontend refactoring, repeat this check and additionally exercise authenticated reader, sharing, bulk-upload, and admin flows with non-production test credentials. The public smoke test alone cannot validate those authenticated paths.

## Passing gates at reviewed head

### Frontend

- `npm run lint`: passed with zero official warnings.
- `npm run test`: 97 files, 937 tests passed; see the `act(...)` warning above.
- `npm run audit:production`: no production advisories.
- `npm run check:routes`: passed.
- `npm run check:csp`: passed.
- `npm run check:tools`: passed.
- `npm run build`: passed; 2,099 modules transformed.
- `npm run check:seo`: passed for five indexable routes.

### Backend

- `composer validate --strict`: passed.
- `composer audit --locked --no-dev`: no advisories.
- Symfony test/prod container lint: passed.
- PHPStan: passed with no errors.
- PHP-CS-Fixer dry run: 0 of 446 files need changes.
- YAML lint: all 27 files valid.
- Production cache warmup: passed.
- Fresh isolated MySQL database: 44 migrations / 113 SQL queries applied successfully.
- Doctrine mapping and schema validation: passed.
- PHPUnit: one deterministic failure described in finding 1; all other tests passed.

### Repository and remote state

- `develop` matched `origin/develop` at review time.
- `main` matched `origin/main` at review time.
- Worktree was clean before this review document.
- No merge conflicts with `main` were detected.
- GitHub reported the PR mergeable with no open, non-outdated review threads.
- CodeRabbit and GitGuardian checks passed.
- Frontend GitHub checks passed.
- Both backend GitHub checks failed because of finding 1.

## Full completion checklist

The next agent should not call the release complete until all items below are true.

### Code cleanup

- [ ] Sharing self-recipient rule has one canonical implementation/message/status.
- [ ] Email, username, and user-code self-share tests pass.
- [ ] Dead Twig registration templates and `RegistrationFormType` are removed.
- [ ] Unused `symfony/form` dependency is removed without unrelated lock upgrades.
- [ ] Twig lint is green and added to CI.
- [ ] `MetadataCandidateCard` removes the duplicated candidate markup.
- [ ] Highest-risk React orchestrators are decomposed with behavior preserved.
- [ ] Production complexity/function-length gates are enabled and green.
- [ ] Frontend tests run without React `act(...)` warnings.
- [ ] Duplicate scan remains below the chosen ceiling without artificial base abstractions.
- [ ] Production CORS no longer defaults to every HTTPS origin.

### Validation

- [ ] `git diff --check`
- [ ] `npm run audit:production`
- [ ] `npm run lint`
- [ ] `npm run test`
- [ ] `npm run check:routes`
- [ ] `npm run check:csp`
- [ ] `npm run check:tools`
- [ ] production frontend build + SEO check
- [ ] `composer validate --strict`
- [ ] `composer audit --locked --no-dev`
- [ ] Symfony YAML/Twig/container lint in test and prod
- [ ] PHPStan
- [ ] PHP-CS-Fixer dry run
- [ ] fresh migrations + Doctrine schema validation
- [ ] full PHPUnit suite with zero failures
- [ ] production cache warmup
- [ ] desktop and mobile browser smoke tests
- [ ] authenticated reader/sharing/upload/admin browser flows
- [ ] GitHub CI fully green and no unresolved review threads

### Deployment

- [ ] Merge commit is on `origin/main` and local `HEAD` matches it.
- [ ] `scripts/.env.deploy` exists locally with mode 600 and all required values.
- [ ] Production database, uploads, and `APP_DATA_KEY` have a verified backup.
- [ ] Complete frontend+backend release artifact builds successfully.
- [ ] Migration is tested on a production-like upgrade copy, not only a fresh schema.
- [ ] Deployment uses the documented backup-gated script.
- [ ] Post-deploy health, public routes, API configuration, security headers, logs, and primary authenticated workflows are verified.
