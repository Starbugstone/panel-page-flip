# Develop review — 4 September 2026

## Scope

Reviewed the updates through `origin/develop` at `5672d08446c00a26cff68182d53c04c26a4ceb3d`.
The production comparison was `origin/main...origin/develop`, starting at merge
base `c59e0fda0aeed9db73be6d5b25eaba42d770550c`: 124 changed files, with 7,118
insertions and 1,472 deletions. Fixes are on a separate branch targeting
`develop`.

The review covered sharing query scoping, pagination, state transitions and
selection; consent ownership, storage, asynchronous callbacks and route/CSP
interactions; administrative filters and suggestions; schema changes; demo
fixtures; generated deployment artefacts; and related tests and documentation.
Existing upload/parser, authentication, reader, storage and moderation behavior
was exercised through the complete application test suites.

## Confirmed findings and fixes

| Priority | Finding | Fix and regression evidence |
| --- | --- | --- |
| P1 | Sent-share search matched recipient email addresses hidden by username/U-code sharing. Received-share search matched owners' private emails. Search counts therefore confirmed guesses about withheld data. | Exclude hidden addresses from owner search/filter/sort; remove owner emails from recipient predicates. HTTP regressions prove private substrings return no results while public usernames still match. |
| P1 | Recipient search, column filters and title ordering used metadata redacted by the age gate, including deleted-comic snapshots. | Apply the same explicit-content/adult-confirmation condition in the database before matching or ordering title/author values. Tests cover live shares, tombstones and post-confirmation search. |
| P2 | While a new table page was loading, Select all could select old, invisible grants and enable revocation or deletion. | Both sharing tables have no selectable rows while loading. Tests reproduce the enabled destructive buttons before the fix and verify they stay disabled afterwards. |
| P1 | Client navigation kept the original document CSP: leaving a legal page continued blocking Google; entering one retained already-running integrations. | A small route boundary loads a fresh document only when crossing CSP profiles and withholds destination integrations until then. Tests cover both directions and normal client navigation within either profile. |
| P2 | The Google privacy-panel request existed only in a ref and would be lost across the required document reload. | Carry it in a one-use query parameter, consume it after configuration loads, preserve it through the signed-in dashboard redirect, remove it from the URL, and reopen the CMP once. |
| P1 | Rejecting analytics in one tab left other open tabs using an earlier grant. | Observe the shared storage key and cleared storage. Tests prove withdrawal and clearing update the active consent decision, including a withdrawal during public-configuration loading. |
| P2 | A queued CMP-ready callback or delayed TCF registration could install a listener after cleanup. | Reject registration after stopping; remove a listener whose ID arrives after cleanup. Both timing orders have regressions. |
| P2 | Admin suggestions used keyup instead of input changes, missing pasted text and issuing needless requests for navigation keys. | Fetch on value changes; retain cancellation of obsolete requests. Tests cover paste-like input, the minimum query length and arrow-key behavior. |
| P2 | A demo archive failure happened after six users were committed. A retry then declared the incomplete fixture set already loaded. | Wrap the command's database work in one transaction. A missing source image now leaves zero new users; normal additive/idempotent loading remains covered. |
| P2 | Audit records stored a lowercased JSON duplicate and full-text index that substring suggestions never used. ORM inserts also selected that generated field back. | Query the original JSON and remove the derived column/index through a new reversible migration. A regression verifies one INSERT rather than INSERT plus SELECT and preserves substring suggestions. |
| P2 | The sent/received status and date headings duplicated a production block, failing the zero-duplication gate. | Extract only those common headings into `ShareStatusColumns`, with tests for the shared controls and both consuming tables. |

Regression tests were run against the unfixed paths before implementation.
The initial duplication scan found one 29-line clone. The new shared component
also has direct behavior coverage.

## Validation

All 16 required pre-push commands passed, along with backend/frontend coverage,
dead-code and duplication checks: 20 successful checks in total.

| Check | Result |
| --- | --- |
| Backend tests | 1,545 tests, 6,099 assertions, one existing conditional skip |
| Frontend tests | 1,493 tests across 178 files, all passing |
| Backend coverage | 86.63% lines, 76.78% methods; thresholds passed |
| Frontend coverage | 83.18% lines, 81.40% statements, 78.54% functions, 75.72% branches; thresholds passed |
| Production duplication | Zero clones across 570 scanned files |
| Dead code, audits, lint, static analysis, schema and committed artefacts | Passed |
| Production build and SEO | Passed with the same production APP_URL |

The backend skip is `ComicFormatServiceTest::testCannotEnableAnUnavailableFormat`:
every optional runtime is installed, so there is no unavailable format to test.
Final frontend runs used two workers after earlier local timeouts under
concurrent coverage load. The validation harness scopes the production
`APP_URL` to build/SEO; ordinary frontend tests retain their expected local
origin. No test timeout or application expectation was relaxed.

All 50 pre-existing migrations applied successfully to a separate fresh test
database. The new migration applied successfully, then passed a down/up round
trip with an original JSON audit payload preserved.
The migrated schema matched the ORM mappings. The normal checkout's PHP
container bind mount was verified to point to this checkout.

The pre-existing local test database had tables without matching migration
history, so migrations were validated in the separate database rather than
rewriting that history. Ordinary functional tests use the repository's existing
database-reset/transaction isolation.

## Deployment and limits

Deploy backend, frontend and migration together. Migration
`Version20260904214000` removes only a derived audit column and its index; original
audit JSON is retained. MySQL schema operations can briefly lock the audit
table, so use the usual coordinated deployment procedure.

This is a code and automated-test review, not a proof that every possible bug is
absent. Live Google account configuration, ad inventory, real-user load and a
production browser network check are outside this run. Existing production
configuration and user data were not changed.
