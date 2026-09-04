# September 2026 codebase review

## Baseline and scope

The review started from production `main` at `8d6da79` in a dedicated worktree.
Implementation started from `develop` at `5672d08` and now incorporates
`develop` at `156c43c`, including the security/stability review in PR #218 and
the updated agent guide in PR #220. It preserves the newer consent, sharing
tables, admin filters and local fixtures.
This is an architectural, security-boundary and user-journey review, supported
by static checks and regression tests; it is not a penetration-test certification.

The inventory covers the Symfony backend, React application, source parsers,
database model and migrations, mail templates, Docker configuration, deployment
scripts, CI and feature documentation. The production-code duplication scan
covered 563 files / 72,345 lines on main. The dead-code and duplication checks
passed. The baseline backend suite ran 1,501 tests / 5,712 assertions with one
existing skip. The frontend suite passed 1,404 of 1,405 tests; the remaining
admin form test timed out under the full run and passed on its isolated rerun.

## Findings and implementation decisions

| Area reviewed | Findings / decision |
| --- | --- |
| Authentication, OAuth, CSRF, session monitoring | Server sessions, password checks, session-bound OAuth state and API CSRF protection are already established. Client authentication requests need ordering so an old response cannot restore a logged-out account or overwrite a new login. A malformed 401 response must still expire the client session. |
| Authorization, sharing, moderation | `ComicAccess` and `ComicVoter` centralize existence disclosure and owner/recipient/admin permissions. Sharing codes, age gates, quarantine and revocation retain those boundaries. Existing transactions and post-commit notification behavior should remain intact. |
| Uploads, ZIP/7z/PDF, metadata | Source factories, enum-constrained paths, size/entry/expansion limits and bounded subprocess output are established. ComicInfo numeric fields still permit values that overflow integer arithmetic or database columns. Bound those before conversion while retaining usable metadata. |
| Lists, queries, persistence | Sort fields are allow-listed and filters use parameters. Pagination must also bound its page value before computing an integer offset; a maximum integer page currently overflows. |
| Library, folders, reader | Existing hooks separate retrieval, navigation, selection and mutation. Preserve folder destinations, return-to-comic behavior, progress revision ordering, page caching, touch gestures and fullscreen geometry. Apply common visual primitives without replacing the reader's layout. |
| Client API and recovery | Centralize HTTP failure handling independent of successful response format, so a failed download retains its JSON error. Add route-level rendering recovery that keeps navigation usable and does not expose exception details. |
| Shared navigation | Desktop and mobile duplicate the destinations and use different active-state behavior. Share destination definitions, expose the current page, keep admin detail routes active, and preserve the special bulk-upload entry link. |
| Visual identity and accessibility | Keep the book mark and purple identity; use readable semantic colors, consistent headings, surfaces, spacing, focus indicators and responsive controls. Light-mode primary text/button contrast needs correction. Missing legal typography leaves headings and lists visually undifferentiated. |
| Account and public flows | Consolidate the account-page presentation, give each page a real primary heading, keep non-enumerating reset copy, provide retry after reset-link validation failure, and remove the delayed navigation that can pull someone away after leaving the success page. |
| Themes and preferences | Validate stored themes against supported values. A broken legacy preference or failed cookie migration must not prevent a usable theme; native controls should follow the selected color scheme. |
| Settings, Dropbox and administration | Keep existing actions, capability gates, confirmations and table state. Apply the shared page header and loading pattern, retain overflow containment, and make section choices readable at narrow widths. |
| Logging, encryption and retention | Credential encryption, structured audit channels, redaction and retention services already exist. Preserve their responsibilities and avoid putting raw errors or private route tokens into new UI telemetry. |
| Delivery, CI and documentation | Preserve deployment/backup gates, CSP manifests, committed route/tool artifacts and host-only checks. Use the worktree's own containers and database for validation. No production deployment is part of this PR. |
| Develop integration and demo data | Preserve the current sharing tables and their filter state; share their repeated status/date columns. Reuse migration-created global tags and transact demo loading so a failure does not leave a falsely complete account set. |

## Refactoring boundaries

Shared components own presentation; feature hooks and services retain their
business decisions. Existing public APIs remain compatible. The integrated
develop migration removes only the unused derived audit payload column and
its full-text index; the original JSON audit records remain intact.
An abstraction is introduced only when it removes an actual repeated rule or
creates a testable boundary. Large domain services are not split mechanically:
moving transaction-sensitive sharing methods purely to reduce line counts would
make their invariants harder to follow.

The palette tests check actual stylesheet token pairs against the
[W3C normal-text contrast threshold](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html).
Rendering recovery follows the [React error-boundary contract](https://react.dev/reference/react/Component#catching-rendering-errors-with-an-error-boundary).
These checks complement browser inspection; they do not claim whole-site WCAG
conformance.

## Validation

New behavior and bug fixes are exercised first against the unchanged code to
record the failing regression, then after implementation. Reproductions included
old authentication requests restoring cleared sessions, a maximum page number
returning HTTP 500, oversized ComicInfo integers throwing, reset validation with
no retry, missing keyboard upload controls, and mobile dialogs overflowing.

All required pre-push gates passed in this checkout's isolated stack and host:
Composer validation and production audit; container/Twig/schema validation;
PHPStan and PHP-CS-Fixer; backend tests; npm production audit, ESLint and frontend
tests; committed route/CSP/conversion-tool checks; production build and SEO using
the same `APP_URL=https://comics.starbugstone.com`. Additional coverage,
dead-code and zero-duplication checks passed.

- Backend: 1,550 tests, 6,123 assertions, one existing conditional skip.
- Frontend: 1,547 tests across 183 files, all passing.
- Backend coverage: 86.66% lines, 76.79% methods.
- Frontend coverage: 84.37% lines, 82.53% statements, 79.98% functions,
  76.78% branches. Every executable production file was reached.
- ShellCheck passed; Unix conversion tests passed 14 cases and Windows
  conversion tests passed 11 cases.
- Fresh development migrations plus additive demo loading passed with the
  migration-created global tags retained. Transaction rollback and idempotency
  have functional regression coverage.

The first integration run exposed the existing slow admin form test under load;
it now enters field values through realistic paste interactions while retaining
all required-field and password-policy assertions. The local check runner also
initially passed production `APP_URL` into tests expecting the local origin;
the final runner scopes it to build/SEO. Coverage thresholds and gates remain
intact; the later obsolete-test removals are described below.

The registration regression waits for the router's visible tab update and still
fails with the original uncontrolled tabs. An admin bulk-delete test timed out
when coverage ran alongside lint; the final standalone coverage run passed
with that test, its assertions and the timeout unchanged.

Browser checks used only the worktree's synthetic demo accounts. Desktop and
320-pixel checks covered library navigation, light/dark appearance, sharing and
its populated dialog, single/bulk upload presentation, account settings,
unconfigured Dropbox, legal typography, sign-in, and administrator overview.
The document stayed within the viewport. The sharing dialog's content width
equaled its scroll width after the fix. A protected comic image loaded, reader
controls stayed available and return-to-library worked. Desktop user-list and
account-detail navigation retained the active admin section. Automated tests
also cover route-crash recovery, private-title handling and keyboard focus.

After integrating the latest develop and removing retired UI, browser checks
reconfirmed the active sharing tables, navigation menu and keyboard pagination
selector at 320 pixels. The sharing dialog measured 276 pixels for both content
and scroll width, and the page had no horizontal overflow. Navigation from
sharing to privacy and back loaded the required fresh documents, returned to
the signed-in library, and produced no browser warnings or errors. The local
stack has no live Google credentials, so this checks document navigation rather
than a live consent-provider response.

Live Google OAuth, consent/ad inventory and Dropbox credentials were not used;
those integrations remain subject to their existing tests and deployment
verification. This review did not perform a production penetration test or
mutate production data.

## Latest develop integration and cleanup

[PR #218](https://github.com/Starbugstone/panel-page-flip/pull/218) is merged into
develop and incorporated here. The update review covered sharing-search
redaction and age gates, consent/CSP document transitions, cross-tab consent
withdrawal, late CMP listener cleanup, stale table selection, admin filter
input handling, fixture transactions, the audit-storage migration and their
tests/documentation. Integration retains route recovery/accessibility and
preserves the one-use privacy-panel query through the signed-in root redirect.

The previous dead-code gate did not check unused exports, and tests made
retired sharing components look reachable. The strengthened gate failed on
five obsolete component files and 59 unused exports before cleanup. It now
passes both the full-project export check and the production-only reachability
check without exemptions for those files.

- Removed the retired invitation/received-share cards, their shell and cover
  components, and the unused grouping/recipient-summary helpers. The active
  sent/received tables remain the sharing interface.
- Removed 20 unused UI primitive definitions and the unused toast re-export;
  kept implementation details private where their code is still used.
- Removed eight tests exclusively covering retired cards/helpers and
  consolidated the duplicate mobile table assertion into `SharedByMeList`'s
  suite. All current table, redaction, age-confirmation and selection tests remain.
- Moved progress tests into the progress-helper suite and session-monitor
  tests beside their component, retaining their assertions.
- Reviewed backend reference candidates against Symfony/Doctrine entry points.
  Framework methods remain; a method without an explicit application caller
  is not sufficient evidence of dead code.

Fresh test-database migrations and the existing demo-database upgrade passed,
including `Version20260904214000`. The first migration attempt against the
test database found tables rebuilt by Foundry without migration history; a
fresh disposable test database then applied all 51 migrations successfully.
