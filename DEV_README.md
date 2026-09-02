# Panel Page Flip - Developer Documentation

## Project Overview

Panel Page Flip is a self-hosted web application for managing and reading comic
collections. Supported sources are CBZ, CBR, CB7, CBT and PDF; all reach the
reader through one protected numbered-page endpoint, so nothing downstream of
the source factory knows which format a comic came from.

This document is the developer's companion to [README.md](README.md), which
covers installation, configuration and operation. Per-feature documentation
lives in [`docs/`](docs/):

| Page | Covers |
|---|---|
| [reader.md](docs/reader.md) | Reader settings, gestures, keyboard, extension contract |
| [comic-formats.md](docs/comic-formats.md) | Source formats, runtime requirements, PDF handling |
| [page-derivatives.md](docs/page-derivatives.md) | Page sizes, conversion, cache invalidation |
| [comic-access.md](docs/comic-access.md) | Who may reach a comic, and how the voter decides |
| [library-covers.md](docs/library-covers.md) | Pacing cover requests, and recovering broken ones |
| [library-folders.md](docs/library-folders.md) | Private folder tree over the library |
| [metadata-enrichment.md](docs/metadata-enrichment.md) | ComicInfo.xml, Metron, Comic Vine |
| [storage-quota.md](docs/storage-quota.md) | Storage accounting and the per-user quota |
| [security-logging.md](docs/security-logging.md) | Security/audit channels, retention, alerts |
| [content-reporting.md](docs/content-reporting.md) | Illegal-content notices and restrictions |
| [administrator-notices.md](docs/administrator-notices.md) | Warning one account about their activity |
| [admin-bulk-actions.md](docs/admin-bulk-actions.md) | Tick-box selection and bulk actions across the admin tables |
| [admin-table-controls.md](docs/admin-table-controls.md) | Per-column sorting and filtering across the admin tables |
| [advertising.md](docs/advertising.md) | Optional AdSense, consent, AdSense Offerwall, strict CSP |
| [analytics.md](docs/analytics.md) | Optional privacy-first GA4, basic consent mode, route minimisation |
| [social-sign-in.md](docs/social-sign-in.md) | Optional Google OAuth, account linking, passwordless accounts |
| [application-data-key.md](docs/application-data-key.md) | `APP_DATA_KEY` and credential encryption |
| [development-tooling.md](docs/development-tooling.md) | Package manager, quality gates, Content-Security-Policy manifest, crawlable landing copy |
| [local-docker-environment.md](docs/local-docker-environment.md) | Per-checkout Compose project, ports, container UID, worktree teardown |

## Current Implementation Status

### Backend (Symfony)

#### ✅ User Authentication System
- **JSON Login**: Implemented in `security.yaml` with proper routes and handlers
- **Registration**: Implemented in `RegistrationController.php`
- **Password Reset**: Implemented in `ResetPasswordController.php` with email notifications
- **Password Policy**: Registration, admin API changes and operator console commands all use `PasswordValidator`
- **User Updates**: `UserUpdateService` validates profile, role, metadata-access, and password changes before touching the managed account, then persists and records admin/security audits in the correct order. `UserController` only authorizes, loads, and presents the result.
- **Auth Email Branding**: Verification, reset and password-change mail uses the configured `MAILER_FROM_NAME`
- **User Entity**: Defined in `User.php` with proper properties and relationships
- **Security**: Access control rules defined to secure API endpoints

#### ✅ Comic Management System
- **Comic Entity**: Defined in `Comic.php` with properties for title, file path, cover image, etc.
- **Comic Controllers**: `ComicController.php` owns listing and single-entry CRUD, `ComicBulkController.php` owns all-or-nothing multi-comic mutations, and `ComicProgressController.php` owns per-reader progress. Upload, metadata, page delivery, and cover routes retain their own focused controllers under the same `/api/comics` prefix.
- **File Storage**: Comics are stored in user-specific directories at `/uploads/comics/{user_id}/{comic_file.cbz}`
- **Cover Images**: Stored under `backend/public/uploads/comics/{user_id}/covers/{comic_id}/{cover_image}` and served only through `GET /api/comics/cover/{userId}/{comicId}/{filename}`
- **Chunked Upload**: Implemented chunked file upload system to handle large comic files (1MB chunks)
  - Initialization endpoint: `/api/comics/upload/init`
  - Chunk upload endpoint: `/api/comics/upload/chunk`
  - Completion endpoint: `/api/comics/upload/complete`

#### ✅ Reading Progress Tracking
- **ComicReadingProgress Entity**: Defined in `ComicReadingProgress.php` to track user reading progress
- **API Endpoints**: `ComicProgressController.php` saves and resets per-user reading progress, including revision ordering for concurrent reader saves

#### ✅ Tag System
- **Tag Entity**: Defined in `Tag.php` for categorizing comics
- **API Endpoints**: Available for managing tags and associating them with comics
- **Per-User Tags**: Tags are unique per user (creator), allowing different users to have tags with the same name

#### ✅ Comic Sharing System
- **ComicShare Entity**: Defined in `ComicShare.php`. A durable, revocable grant of read access to the owner's single copy — sharing never creates a second `Comic` row or a second file
- **ShareInvitationToken Entity**: Defined in `ShareInvitationToken.php`. Only a SHA-256 hash of each invitation link is stored. One link per invitation, good for a single claim within two months; accepting spends it and revokes every other token for that share, and resending mints a new one and retires the old link
- **ComicVoter**: `Security/Voter/ComicVoter.php` answers `COMIC_VIEW`, `COMIC_EDIT`, `COMIC_DELETE` and `COMIC_SHARE` for every endpoint that touches a comic
- **Share Controller**: `ShareController.php` under `/api/shares` — invite, resend, revoke, stop sharing, preview, accept, decline, remove, restore and tombstone cleanup. `GET /shared-by-me` is paged by comic with the same query parameters and `pagination` block as the admin tables, and `DELETE /{id}` lets the owner clear the record of a finished share (revoked, declined or lapsed — a live one is refused with a 409 so deleting can never stand in for revoking)
- **Public Identity**: Every account has a required, case-insensitively unique `username` and a rotatable `U-` user code. `UsernamePolicy.php` is the one definition of what a username may be, `UsernameGenerator.php` invents friendly ones, `UsernameService.php` claims and changes them, and `UserIdentityListener.php` issues both identifiers on `prePersist` so no account can exist without them. See [Public identity: username and `U-` code](#public-identity-username-and-u--code)
- **Sharing Codes**: `SharingCodeFormat.php` and `ShareCodeType.php` define one wire format in three flavours — `U-` identifies a person, `C-` carries exactly one comic, `G-` carries a package of 2–20. `SharingCodeService.php` issues and resolves user codes; `ShareClaimCodeService.php` mints and redeems content codes. See [Sharing codes](#sharing-codes)
- **Content Code Lifetime**: `ShareContentCodeLifetime.php` turns `SHARE_CONTENT_CODE_TTL_DAYS` (default 7) into an absolute expiry stamped on each code at minting time, and refuses to construct on a nonsense value so a bad deployment fails on the way up
- **Sharing Workflow**: `SharingWorkflowController.php` and `SharingWorkflowService.php` add `GET /api/shares/recent-recipients`, `GET /api/shares/folders/{id}/comics` and `POST /api/shares/invitations/bulk` — the one path that opens a direct share. Recipients come only from the caller's own share history and never from a user directory; bulk sharing is a permission gate in front of `ComicShareService::inviteMany()` and creates one ordinary `ComicShare` per comic. See [Privacy: registered users are never discoverable](#privacy-registered-users-are-never-discoverable)
- **Explicit Promotion**: `ExplicitContentPromoter.php` marks selected comics 18+ from inside the share flow, in the same unit of work as the share. It only ever promotes — an unticked box is the absence of a claim, not a claim that the comic is fine. It returns its audit records rather than writing them, so the service owning the transaction emits them only once the commit has made them true
- **Lookup Flood Guard**: `IdentifierLookupGuard.php` charges every attempt to turn an identifier into a person *before* the repository is asked, so an exhausted caller cannot still resolve the identifiers that happen to be real. See [Why this is not a user directory](#why-this-is-not-a-user-directory)
- **Tombstones**: Deleting a comic nulls the relationship and records `unavailableAt` plus a `tombstoneReason`, so recipients are told why a comic disappeared. They are recipient-only — the owner caused the deletion, has no comic left to manage, and the comic leaves their sharing list entirely
- **Email Notifications**: Shares commit first; `ShareInvitationNotification` is then queued carrying share ids and nothing else, and `ShareInvitationNotificationHandler` reloads the relationships and mints the links. `ComicShareInvitationMailer` alone renders and transports the single, grouped, and folder notices, while `ComicShareService` remains responsible for token and relationship state. A bulk share sends one grouped email, so twenty comics are not twenty messages. See [Notification is a notice, not a condition](#notification-is-a-notice-not-a-condition)
- **Cleanup Command**: `CleanupExpiredSharesCommand` deletes pending invitations that expired unanswered, content codes that have been dead for over a month, and shares revoked more than a month ago (`ComicShare::RETENTION_AFTER_REVOCATION`, measured from `revokedAt` on the same clock as dead codes). The work itself is in `ExpiredShareCleanupService`, because an administrator can run the same sweep from the admin page and a deletion rule that exists twice will eventually disagree with itself
- **Admin Sharing Codes**: `AdminShareCodeController.php` under `/api/admin/sharing-codes` — a paginated, filterable view of every issued content code, forced revocation, and a manual run of the retention sweep. It can never show a code, take back a redeemed comic, or delete a live record

#### ✅ Dropbox Integration System
- **DropboxController**: Handles OAuth flow, connection status, file listing, and individual comic import
- **Dropbox OAuth Flow**: Complete implementation with CSRF protection, token management, and proper scopes
- **Individual Import**: Users can import specific comics from their Dropbox with individual import buttons
- **Smart Sync Detection**: Robust duplicate detection using filename and title similarity matching
- **Automatic Tagging**: Intelligent conversion of folder names to tags (camelCase, snake_case, kebab-case support)
- **API Endpoints**: Status check, disconnect, file listing with import status, and individual import
- **Background Sync**: Console command for automated syncing with rate limiting (still available for bulk operations)

#### ✅ Utility Commands
- **CreateUserCommand**: Creates regular users after applying the shared password policy (`app:create-user`)
- **CreateAdminUserCommand**: Creates admin users after applying the shared password policy (`app:create-admin-user`)
- **ImportComicsCommand**: Imports comics from a directory (`app:import-comics`)
- **CleanupComicsCommand**: Cleans up orphaned comic files and cover images (`app:cleanup-comics`)
- **SetupUploadDirectoriesCommand**: Sets up necessary directories for uploads (`app:setup-upload-directories`)
- **TestApiEndpointsCommand**: Tests API endpoints for registration and login (`app:test-api-endpoints`)
- **DropboxSyncCommand**: Syncs comics from Dropbox for all connected users (`app:dropbox-sync`)
- **CleanupLogsCommand**: Deletes daily log files past their retention period (`app:cleanup-logs`)
- **CleanupPersonalDataCommand**: Removes expired audit rows, spent tokens and unverified accounts (`app:cleanup-personal-data`)
- **CleanupExpiredSharesCommand**: Removes unanswered invitations, long-dead sharing codes and long-revoked shares (`app:cleanup-expired-shares`)
- **CleanupContentReportsCommand**: Removes closed/rejected reports past retention, never one on legal hold (`app:cleanup-content-reports`)
- **ComicFormatsCheckCommand**: Reports which source formats this host can actually serve, and exits non-zero when an enabled one is unserviceable (`app:comic-formats:check`)
- **PruneComicPagesCommand**: Drops generated page derivatives from the cache (`app:comic-pages:prune`)
- **BackfillComicFileSizeCommand**: Fills `Comic.fileSize` for comics uploaded before quotas existed (`app:backfill-comic-file-size`)
- **MigrateDropboxTokensCommand**: Re-encrypts legacy plaintext Dropbox tokens under `APP_DATA_KEY` (`app:migrate-dropbox-tokens`)
- **ResetUserPasswordCommand**: Sets an account's password from the console after applying the shared password policy (`app:reset-user-password`)
- **TestEmailVerificationCommand**: Exercises the email-verification flow (`app:test-email-verification`)
- **TestMailCommand**: Sends one test message through the configured mailer (`app:test-mail`)

The four cleanup commands that a production instance **must** have scheduled —
`app:cleanup-logs`, `app:cleanup-personal-data`,
`app:cleanup-expired-shares` and `app:cleanup-content-reports` — are listed with
what breaks if they never run in
[README.md § Scheduled maintenance](README.md#scheduled-maintenance).

#### ✅ Security and Audit Logging
- **Dedicated channels**: `app_security` for refusals and suspected abuse, `app_audit` for successful state changes, both separate from `main`. They are not called `security`/`audit` because `security` is Symfony's own channel and its authenticator logs the submitted address there.
- **Daily files**: `var/log/app/YYYY-MM-DD.log`, plus `var/log/security/YYYY/MM/` and `var/log/audit/YYYY/MM/` for the long-retention streams
- **SensitiveDataProcessor**: recursively redacts credentials on every channel, and rewrites secrets embedded in strings (`?token=`, `Bearer …`) — a backstop, not a substitute for passing identifiers
- **SecurityAlertService**: administrator email alerts with per-source thresholds and a deduplication window; off by default, and a send failure can never break the operation that reported the event
- **Reads are never logged** — no comic view, page turn or cover fetch

Full guide, including retention, alert thresholds and the rules for adding an event: [docs/security-logging.md](docs/security-logging.md).

### Frontend (React)

#### ✅ User Interface
- **Landing Page**: Implemented in `Landing.jsx` with project introduction
- **Authentication Pages**: Login and registration implemented in `Login.jsx`
- **Password Reset**: Forgot password and reset password pages implemented in `ForgotPassword.jsx` and `ResetPassword.jsx`
- **Dashboard**: Comic library view implemented in `Dashboard.jsx`
- **Library Request Ownership**: `useLibrarySearch` reloads only when the active library location changes; completion of an unrelated folder-tree request cannot duplicate the main collection request. `TagProvider` owns one account-scoped cache and one in-flight request per tag context, so dashboard controls share the prefetch instead of issuing the same request twice and a previous account's tags are never exposed during an account change.
- **Settings Boundaries**: `UserSettings.jsx` only composes the page. Personal-tag CRUD, conversion-tool downloads, OAuth callback notices, and privacy/account deletion each live in their own focused component or hook with direct behavior tests.

#### ✅ Comic Reader
- **Reading Interface**: Core reading functionality implemented in `ComicReader.jsx`
- **Advanced Caching System**: Keeps decoded images loaded from protected `/api/comics/{id}/pages/{page}` URLs so browser HTTP caching and server authorization remain effective
- **Optimized Page Loading**: Uses a priority queue system to load pages in order of likelihood to be viewed next
- **Memory Management**: Evicts decoded pages outside an adaptive preload window based on viewport class, device memory and network data-saving hints
- **Network Optimization**: Prevents duplicate network requests by tracking in-progress loads
- **Responsive UI**: Immediately displays cached pages while loading new ones in the background

#### ✅ Admin Interface
- **Admin Dashboard**: Implemented in `AdminDashboard.jsx`, now includes a loading indicator during user authentication.
- **Admin Users Management**: Enhanced UI in `AdminUsersList.jsx` for managing user roles (e.g., ensuring `ROLE_USER` persistence, clearer role assignment).
- **Admin Comics List**: Improved tag display in `AdminComicsList.jsx` to correctly handle various tag data formats.
- **Admin Tags List**: UI refinements in `AdminTagsList.jsx` for tag creation and editing dialogs.

#### ✅ Comic Upload
- **Upload Comic**: Comic upload interface implemented in `UploadComic.jsx` with chunked upload support, progress tracking, and tag management

#### ✅ Comic Sharing
- **Sharing Page**: `Sharing.jsx` at `/sharing` — where shares are both started and managed, with "Shared with me" and "Shared by me" tabs for invitations, access and tombstones. "Shared by me" is paged by comic with the same pager the admin tables use, and each finished share (revoked, declined or lapsed) offers a **Delete** to clear its record before the retention sweep would
- **Share Comics Dialog**: `ShareComicsDialog.jsx` is the *one* share workflow, opened from a grid card, from a table selection and from the Sharing page alike. It offers **Direct** (name a person by username, `U-` code, address, or somebody shared with before) or **Code** (a `C-` for one comic, a `G-` for two to twenty, decided from the selection rather than asked), carries the 18+ decision inline, and lists no registered users and searches none. What differs between entry points is only what is already chosen when it opens
- **Sharing Codes Card**: `SharingCodesCard.jsx` on `/sharing` — the account's username and `U-` code with copy and **Replace** actions (the latter behind a confirmation, since the old code breaks everywhere at once), one redeem field that dispatches on the prefix, and the list of content codes handed out with the server's own `expiresAt` and a **Withdraw** action on each live one
- **Admin User Code Rotation**: `AdminUserDetails.jsx` can replace a user's `U-` code on their behalf, behind a confirmation. The new code is never shown to the administrator — the user reads it off their own Sharing page
- **Admin Sharing Codes Tab**: `AdminSharingCodesList.jsx` under **Admin → Sharing codes** — every issued content code with status/owner/date filters and pagination, a **Withdraw** action, and a **Run cleanup** button. Both destructive actions state what they will *not* touch before they run
- **Registration**: `Login.jsx` proposes a generated username with a **Generate another** button, because a public handle is the one field nobody arrives at a signup form having decided on. It can be edited, and the unique index rather than the availability check is what finally rules
- **Invitation Preview**: `ShareInvitation.jsx` at `/share/invitation/:token` loads the invitation through a safe `GET` and only accepts or declines on a button press
- **Pending Shares Alert**: `PendingSharesAlert.jsx`, now a one-line prompt on the dashboard rather than a card per invitation
- **Sharing Hooks**: `use-sharing.jsx` — `SharingProvider` holds the pending count for the header badge and the dashboard alert; `useSharingLists` loads both halves of the Sharing page and owns which page of "Shared by me" is being looked at, falling back to the last page that exists when the one on screen is deleted out from under it
- **Sharing Helpers**: `lib/sharing.js` holds the classification and wording rules, covered by `lib/sharing.test.js`
- **Collection Integration**: Accepted shares appear in the normal collection with a "Shared by …" badge, an `All | Mine | Shared with me` filter, and owner actions hidden

#### ✅ Dropbox Integration
- **Dropbox Import Page**: Complete UI in `DropboxSyncPage.jsx` for managing Dropbox connection and individual imports
- **Connection Status**: Fast local connection state with an explicit retry state when the status request fails
- **File Management**: Display of Dropbox files with accurate import status indicators
- **Individual Import**: UI for importing specific comics with individual import buttons and loading states
- **Smart Status Detection**: Accurately shows which files have been imported to prevent duplicates
- **Dashboard Integration**: Dedicated "Dropbox" tab in the main dashboard for imported comics

The frontend is built with:
- React with JavaScript (converted from TypeScript)
- Vite for fast development and building
- shadcn-ui components
- Tailwind CSS for styling
- React Router for navigation

## Comic Sharing System

### The model

> A comic has one owner and one physical file. Sharing grants revocable read
> access to that comic without copying it.

Everything below follows from that. There is no second `Comic` row, no second
CBZ, no copied cover and no copied tags. A recipient reads the owner's file
through the same endpoints the owner uses, and the owner can take that away
again at any time.

### Database Schema

#### ComicShare Entity

One durable relationship per comic and recipient. Re-inviting somebody reuses
the row, enforced by a unique index on `(comic_id, recipient_email_normalized)`.

- **comic / owner / recipientUser**: all nullable, all `ON DELETE SET NULL`, so
  the record survives to explain a disappearance instead of cascading away
- **recipientEmailNormalized**: trimmed and lowercased, so one address is one
  recipient for uniqueness and for every lookup
- **status**: `pending`, `accepted`, `declined` or `revoked`
- **createdAt / acceptedAt / declinedAt / revokedAt**: when each transition happened
- **recipientRemovedAt**: the recipient hid the comic from their own collection;
  access is untouched and restoring it is one click
- **expiresAt**: bounds an unanswered invitation. Cleared on acceptance — access
  does not expire, only the invitation did
- **comicTitleSnapshot / comicAuthorSnapshot / ownerNameSnapshot /
  explicitContentSnapshot**: refreshed whenever an invitation is issued, so a
  tombstone can still name what it was — and still know that what it names was
  18+, which is why deleting a comic cannot leak the title an unconfirmed age
  gate was holding back
- **senderResponsibilityAcceptedAt / adultConfirmedAt**: the two acknowledgements
  this relationship records, both server-generated. See
  [Explicit content and the 18+ gate](#explicit-content-and-the-18-gate)
- **recipientAliasName / recipientUserCode**: how a relationship begun by
  username or `U-` code names its recipient without exposing the address the
  sender never learned. The code records how it began and is not a live handle;
  current identity is resolved through `recipientUser`
- **notificationState / notifiedAt**: `pending`, `sent` or `failed`. Mail is a
  notice about a share rather than a condition of it, so this is where an owner
  is told the email did not arrive. See
  [Notification is a notice, not a condition](#notification-is-a-notice-not-a-condition)
- **unavailableAt / tombstoneReason**: `owner_deleted`, `owner_account_deleted`,
  `file_missing` or `administratively_removed`

A tombstone belongs to the recipient. It exists to explain a disappearance to
the people who lost access, so `findAllForOwner()` excludes it and the comic
leaves the owner's **Shared by me** list completely — they caused the deletion,
were warned about its reach beforehand, and have nothing left to manage. The
personal-data export uses `findAllForOwnerIncludingTombstones()` instead,
because an export describes what is stored rather than what a page shows.

For the same reason, re-inviting somebody after a deletion starts a *fresh*
relationship rather than resurrecting the tombstone: re-pointing it at a
different comic would rewrite the recipient's history and destroy the
explanation they were left with. Tombstones hold a null comic, which also keeps
them clear of the unique index.

#### ShareInvitationToken Entity

Kept separate from the access relationship so the two lifecycles do not fight:
resending mints a new token and invalidates the old link, while access that has
already been accepted survives both.

- **tokenHash**: SHA-256 of the plaintext. The plaintext exists only while the
  email is being written and in the email itself. Neither the bulk-invite nor
  resend API returns it to the owner, and nothing can reconstruct it afterwards
- **createdAt / expiresAt / usedAt / revokedAt**

A raw 256-bit token needs no work factor: there is no dictionary to slow an
attacker down through.

##### One link, one claim

`isUsable()` is the whole rule: unused, unrevoked, and not past `expiresAt`.
Every way a share can move on retires the links it had — accepting spends the one
that was used and revokes the rest, and declining, revoking, stopping sharing,
resending and tombstoning all revoke outstanding tokens. So a claimed link is
dead for *every* purpose afterwards, not only for the one that claimed it:
previewing, accepting, declining and confirming an age all resolve the same
token and all refuse.

**Previewing deliberately does not spend it.** Mail scanners, corporate link
checkers and preview bots follow links without a person behind them, so a token
that burned on a `GET` would be dead before the recipient ever saw it. That is
why the link is not what keeps anyone out: it only ever previews. Accepting
requires signing in as the intended recipient, and for an explicit comic the
preview reveals nothing to a link holder at all.

`INVITATION_TTL` is **two months**, and one constant sets both the token's expiry
and the pending share's, so a link and the invitation behind it can never
disagree about whether they are still live. Two months because answering is not
always quick — the recipient may have no account here yet — against the cost that
an escaped link stays live longer.

### Permissions

`ComicVoter` is the single place that decides access. Every endpoint serving any
part of a comic — metadata, cover, extracted page, reading position — asks it the
same question.

| Action | Owner | Admin | Accepted recipient |
|---|---|---|---|
| `COMIC_VIEW` (read, cover, pages, own progress) | Yes | Yes | Yes, once any 18+ gate is passed |
| `COMIC_EDIT` | Yes | Yes | No |
| `COMIC_DELETE` | Yes | Yes | No |
| `COMIC_SHARE` | Yes | No | No |
| Download the CBZ | Yes | No | No |

Downloading is owner-only and not routed through the voter: it is the backup
path for your own library, and handing a recipient the archive would create the
permanent second copy the model exists to avoid.

### Sharing Workflow

#### Inviting

There is one endpoint that opens a direct share, whether one comic or twenty
are going out: `POST /api/shares/invitations/bulk`. The single-comic invitation
endpoint was removed with the modal that used it — one path that opens a
relationship is enough.

1. The owner picks comics and names a recipient in `ShareComicsDialog`, and ticks
   **I understand** against the responsibility notice — unticked every time the
   dialog opens, and required before the share can be sent
2. The request creates or reopens one `ComicShare` per comic and **commits**
3. The notice is queued afterwards, and the owner is told the share exists
   whatever the mail server is doing

#### Notification is a notice, not a condition

Sharing used to send inside the transaction and roll back when the send failed.
That bought "no invitation nobody was told about" at the price of losing a
perfectly good share every time a mail server was briefly busy — and an SMTP
call is not a participant in a database transaction, so no ordering of that code
could have made both guarantees hold.

```text
owner confirms share
  -> ComicShare rows committed
  -> ShareInvitationNotification dispatched, carrying share ids and nothing else
  -> worker reloads the relationships
  -> worker mints the invitation tokens as it writes the mail
  -> one grouped email
```

Two properties follow from the payload being ids:

- **No plaintext bearer token is ever written to the queue**, retried through it,
  or left in the failure transport for an operator to read
- **A notice retried an hour later carries a link that still works**, because the
  link is minted at delivery time rather than at dispatch time

`ComicShare.notificationState` is `pending`, `sent` or `failed`, so an owner is
told the email did not arrive rather than wondering why nobody answered. Resend
stays **synchronous**, because somebody pressing it is standing in front of the
screen asking whether it went this time — but it does **not** return the link.
The link is a bearer capability belonging to the recipient, and it exists in one
place: the email.

**A queue that is down costs the notice, never the share.** Committing before
dispatching moved that failure rather than removing it, so the dispatch is
guarded: the shares are reported as created, their `notificationState` becomes
`failed`, and the owner is told to resend. Letting the exception out would hand
the owner a 500 for a share that exists, and their retry would meet its own
duplicates.

**The transport is `sync://` by default, and that is not a test-only setting.**
Nothing else in this application puts a message on a queue — the mailer routing
in `messenger.yaml` is commented out — so shipping a queued notice without also
shipping a worker would create shares and silently never tell anybody, which is
a worse failure than the one the queue was introduced to fix.

What the design needs is that the notice is dispatched *after* the commit, and
that holds either way: `sync://` runs the handler inline, a failed send marks
`notificationState` and is recoverable with Resend, and the share survives. What
a queue adds is automatic retry and a response that does not wait on SMTP.

`SHARE_NOTIFICATION_TRANSPORT_DSN` switches it, once
`messenger:consume share_notifications` is actually running — see
[SSH-deploy.md §7.3](SSH-deploy.md#73-symfony-messenger-consumer--optional).
Tests pin the transport to `sync://` so they exercise the handler whichever way
an installation is configured.

Sends and resends are limited per owner by the `share_invitation` rate limiter
(`config/packages/rate_limiter.yaml`, sliding window, 10 per hour). The
framework's limiter is lock-backed, so the check and the record are one atomic
step — counting recent invitations and then creating another is a read followed
by a write, and concurrent requests can all read the same figure and all decide
they are under the limit. The allowance is claimed immediately before an
invitation is issued, so a request rejected as a duplicate, by permissions, or
by validation does not spend it.

**One send is one claim.** The limiter counts messages, not relationships, so a
bulk share of twenty comics costs the same one allowance as a single invitation —
it is one email. That keeps what the limiter protects, how much mail one account
can put in somebody's inbox, exactly where it was before bulk sharing existed.

#### Sharing a folder

`POST /api/shares/invitations/bulk` takes **either** `comicIds` **or** a
`folderId`, never both — a request whose two halves disagree would still share
something, and which something would be decided by the server rather than by the
sender. Naming a folder shares its whole subtree, and
`GET /api/shares/folders/{id}/comics` previews the same resolution before
anybody is named.

A folder share is a **snapshot**, not a standing grant. It expands to one
ordinary `ComicShare` per comic, each accepted, expired and withdrawn on its
own; a comic added to the folder afterwards is not shared by it. Nothing about
the folder survives into the relationships, and the recipient never sees the
owner's tree — they file what they accept wherever they like.

| Rule | Value | Why |
|---|---|---|
| Comics per folder share | 200 (`MAX_FOLDER_COMICS`) | Somebody pointing at "DragonBall" means all of it; a cap of 20 would ask them to do by hand the thing they wanted one click for |
| Comics per hand-written list | 20 (`MAX_BULK_COMICS`) | Unchanged. The larger ceiling is only offered to a request the server resolves itself |
| Invitations listed in one email | 20 (`MAX_LISTED_INVITATIONS`) | Above this the notice is a summary with one link to `/sharing`; 200 buttons is not a message anybody reads, and Gmail clips past ~102KB |

Because a folder is a *view* rather than a container, it can hold comics
somebody else shared in. Those are filtered out by the same `COMIC_SHARE` voter
check every other route asks, and reported as **a count and never a list** — which
comic cannot be passed on is a fact about the library, not part of the share
being prepared. A folder holding nothing shareable is refused with one sentence
whether it is empty or borrowed, so the refusal says nothing about which.

The share **re-resolves the folder at send time**. The ids the dialog shows came
from a preview, so a comic filed out of the folder in between does not go — and
a hand-written `folderId` reaches nothing the preview would not have shown its
owner.

A summarised notice mints **no invitation links at all**: a capability that was
never rendered never entered the message, which is the same reason the queue
carries ids only. The recipient answers on their Sharing page, where the
invitations are waiting either way.

#### One dialog, three entry points

`ShareComicsDialog.jsx` is the only share workflow in the application. A grid
card, a table selection and the Sharing page all arrive at it, because three
dialogs that each grew their own idea of what a share is are three places for the
rules to drift apart — and the old grid modal could only reach somebody by an
address the sender had to know already.

What differs between the entry points is only what is already chosen when the
dialog opens:

| Opened from | Selection | Code option |
|---|---|---|
| A comic card's menu | that comic, locked | `C-` (a group needs two) |
| **Share selected** in the table | the existing selection, passed straight through | `C-` for one, `G-` for 2–20 |
| **Share folder** in the folder bar | everything the owner may share in that folder's subtree, locked | `C-`/`G-` up to 20, withdrawn above that |
| **Share comics** on `/sharing` | nothing; the dialog picks | whichever the picked count allows |

The `C-`/`G-` choice is **derived from the count rather than asked**, because
that is the whole difference between the two prefixes. A recipient is named by
username, `U-` code, address, or by picking somebody shared with before.

A table selection containing a comic somebody shared with *you* is **blocked and
explained, never quietly filtered**: a sender told "2 shared" while meaning 3 has
been told the wrong thing.

The dialog picks owned comics (`GET /api/comics?ownership=mine`, filtered again on
`canShare`) and posts one request:

| Endpoint | Returns |
|---|---|
| `GET /api/shares/recent-recipients` | up to 20 recipients this owner has shared with before, most recent first — registered ones by username |
| `POST /api/shares/invitations/bulk` | a per-comic result for up to 20 comics — `created`, `skipped`, `rate_limited` or `failed`. A comic the caller cannot share refuses the **whole batch** before this point |

`SharingWorkflowService` is only the permission gate: it resolves each id, asks
`ComicVoter::SHARE` about it, and hands what is left to
`ComicShareService::inviteMany()`. So the duplicate rules, the acknowledgement,
the tokens, the audit records and the rate limiting are the same code a single
invitation runs, and a bulk share cannot grant access a single one would refuse.

**Every comic still gets its own `ComicShare`**, its own token, its own status
and its own revocation. What is shared across a batch is the notice and the
allowance:

- **One email.** `templates/emails/share_comics.html.twig` lists the comics with
  a separate **Review invitation** link each, because each invitation is still
  answered on its own. A batch of one falls back to the ordinary single-comic
  template. The 18+ gate is applied per comic exactly as it is for a single
  invitation, so an explicit comic in a batch is announced without its title
- **A refused batch creates nothing.** An exhausted allowance, a missing
  acknowledgement or an address the sender may not invite is answered as one
  error with its real status, before any comic is touched
- **A failed send does not.** The shares are committed before the notice is
  queued, so a mail server having a bad minute costs a notification and not a
  relationship. See
  [Notification is a notice, not a condition](#notification-is-a-notice-not-a-condition)

**A selection is shared whole or not at all.** If any requested comic is
missing, somebody else's, restricted or already received from another owner, the
request is refused with one message and nothing is created — not even for the
comics that were fine. Sharing five of six and reporting the sixth in a
per-comic list tells a sender who asked for six that they got five, in a line
they have to go and read; the one that did not go is the one they will not
notice. Legitimate duplicates are different and remain idempotent: an existing
live share is `skipped`, not a refusal.

A comic that is missing and a comic belonging to somebody else produce the same
message. The picker only ever sends owned ids, but a hand-written request must
not turn the endpoint into a comic-id oracle.

**Exactly one way of naming a recipient per request.** `username`, `userCode`
and `email` are mutually exclusive and one is required; zero or more than one is
a `400`. A precedence order would answer a contradictory request by sharing with
*somebody*, chosen by the order the server happens to check in rather than by
the sender.

### Privacy: registered users are never discoverable

> Making sharing easier must never make registered users discoverable.

This is a security requirement, not a UI choice — a frontend that declines to
search is worthless if the API answers anyway.

- There is **no normal-user endpoint** that searches or lists users by email,
  username, display name, account id or any other identifier, and none may be
  added. `/api/users` is admin-only and stays that way
- **Exact resolution is not a search.** `POST /api/users/resolve-username` and
  `POST /api/shares/user-code/resolve` answer for one identifier, exactly as
  typed. There is no prefix match, no autocomplete and no listing, so the only
  way to learn a username is to be told it. Both return the public identity
  alone — username, display name, a label built from the two — and never an
  address, an id, or whether the account is verified
- **A username is guessable in a way a code is not.** Sixty bits of entropy is
  what makes `U-` resolution safe; a username is short, memorable and chosen, so
  the `username_lookup` allowance (30 misses an hour) is the control that
  actually does the work there rather than a backstop behind entropy. It is
  charged **per miss**, so sharing with people you know is free
- **Recent recipients are not a user search.** The query reads `ComicShare` rows
  whose `owner` is the caller and returns nothing but recipients the caller
  reached themselves. A registered recipient is listed by username; the hidden
  address never comes back through it
- **Incoming shares are not a contact list.** Somebody sharing a comic with you
  does not put their address in your recipient picker. The sender chose to reveal
  it for that one invitation; inferring a reciprocal relationship from it would
  disclose something the recipient was never entitled to
- **Inviting reveals nothing either way.** The response for an address that
  belongs to an account and one that does not is identical, so the invitation
  endpoint cannot be used to enumerate accounts. The UI behaves the same in both
  cases: the recipient may simply have to register before they can accept

### Public identity: username and `U-` code

> An email address is private and a display name is not unique, so a
> confirmation screen reading "Sharing with: Matthew" confirms nothing.

Every account therefore carries two public identifiers, both required, both
issued by `UserIdentityListener` on `prePersist` rather than by each of the six
places a `User` can be created:

```text
Display name: Matthew                  not unique, decorative
Username:     @SilverOtter4821         unique, the identity
User code:    U-7K3M-H91P-R2AX         unique, the address
Email:        matthew@example.com      private account data
```

Wherever a *registered* person is named — share confirmation, recent recipients,
Shared with me, Shared by me, redemption results, grouped notifications, owner
facing recipient labels — the username is what is shown. Email survives as a
direct-share **input**, because inviting somebody who has no account yet is still
an address, but once a recipient is linked to an account the public identity is
the username.

#### What a username may be

`UsernamePolicy` is the one definition, and `UsernameService` the one thing that
claims or changes one:

- 3–32 characters, letters, digits, `_` and `-`, starting with a letter or digit
- **unique regardless of case** — `user.username_canonical` holds the lowercase
  twin and carries the unique index, while `user.username` keeps the form the
  account chose. A confirmation showing `@SilverOtter` while the comics go to
  `@silverotter` confirms nothing
- a short literal list of **reserved names** (`admin`, `support`, `system`,
  `noreply`, …), compared canonically, because `@support` on a confirmation
  screen reads as the operator rather than as a stranger. A pattern rather than a
  list would also catch the ordinary names that merely contain one of them
- **never derived from the account.** No email local part, no display name, no
  address — a username is published to whoever the owner shares with, and the
  account data is not

`UsernameGenerator` invents them as adjective + noun + four digits
(`SilverOtter4821`) from short curated word lists, using `random_int()`. It does
**not** treat its own output as unique: collisions over short lists are ordinary,
so it retries, and the database index is what finally rules. That matters at both
ends — registration proposes a name with a **Generate another** button, and the
migration that adds the `NOT NULL` back-fills every pre-existing account the same
way.

`GET /api/users/username-available` exists for the registration UX only.
Registration still has to survive losing the race between the check and the
insert, because two people can pass the same check.

Changing a username is rate limited (`username_change`, 3 per 24 hours) and
audited. A handle other people have written down is an identity, and one that can
be changed freely can be changed *into*: taking a name somebody has just vacated
is the cheapest impersonation there is. `ComicShare` rows link by user id, so a
username change never disturbs a share.

### Sharing codes

Three codes, one format, and the type written on the front:

```text
U-XXXX-XXXX-XXXX   identifies a person; grants nothing
C-XXXX-XXXX-XXXX   exactly one comic
G-XXXX-XXXX-XXXX   a package of 2–20 comics
```

`SharingCodeFormat` is the only place that shape is decided, and `ShareCodeType`
the only place the prefixes are. Twelve characters of Crockford base32 — no `I`,
`L`, `O` or `U`, and the pairs people confuse are folded back on the way in — so
a code read off a screen and typed into a phone survives the trip. Twelve
characters is a little over 60 bits.

**The prefix sits outside those twelve, so it costs no entropy** and buys the one
thing entropy cannot: a code can be classified before any repository is asked
anything. Previously the twelve characters were indistinguishable and what a code
*meant* was decided by the field it was pasted into, which made every wrong paste
a lookup failure rather than an explanation. Now:

| Pasted where | Answer |
|---|---|
| `C-…` into the recipient box | *This is a comic code. Redeem it under Shared with me.* |
| `G-…` into the recipient box | *This is a group code. Redeem it under Shared with me.* |
| `U-…` into the redeem box | *This is a user code. Use it when sharing directly with another user.* |
| unknown or missing prefix | refused before any lookup |

`ShareCodeType::misuseGuidance()` is where that wording lives, so the frontend
and the backend cannot drift. Frontend validation is UX; the backend validates
prefix, length, separators and alphabet independently.

**There is no legacy-code compatibility layer, and none may be added.** Untyped
codes are not accepted anywhere. When the type column was introduced the live
content codes were withdrawn rather than migrated: their hashes covered the token
alone and now cover the type as well, so compatibility would have meant carrying
an untyped lookup for ever. Content codes are short-lived by design, so the
oldest live one on any installation was a day old.

#### The user code — "this is me, share with me"

One per account, on `user.user_code`, issued with the username at creation.
It is stored **in the clear**, unlike an invitation token, precisely because it
is an address rather than a capability — its owner has to be able to read it
back and hand it out again. It authenticates nobody, and the worst a stranger
holding it can do is offer you a comic you decline.

**Stable, but not permanent.** A code lives in chats, forums and group threads,
which is exactly the kind of place a thing escapes from, and an identifier its
owner cannot retire after that is one they are stuck with. Rotation is theirs to
trigger, and an administrator's on their behalf when they ask support for it.
Nothing rotates it on its own, because everybody holding the old one has to be
told the new one.

| Endpoint | Answers |
|---|---|
| `GET /api/shares/user-code` | this account's username, code and display label |
| `POST /api/shares/user-code/resolve` | the public identity behind a code — nothing else |
| `POST /api/shares/user-code/rotate` | retires the current code and returns a new one |
| `POST /api/users/{id}/user-code/rotate` | the same, admin-only, and does **not** return the new code |

Rotation retires the `U-` code alone. **The username is untouched**, because the
two identifiers exist for different reasons: the code is a handle that can leak
and be replaced, the username is the name the owner is known by and changing it
is the separate, audited, rate-limited act described above.

Rotation changes the identifier and nothing else. Every share already made
through the old code is a relationship, not an address: pending invitations stay
pending, accepted ones stay accepted, and nobody loses a comic. It is rate
limited (`sharing_code_rotation`) — not for load, but so a script or a stuck
retry cannot quietly make somebody uncontactable — and audited with ids only.
Neither the old code nor the new one is ever written to a log; a code somebody
rotated *because* it leaked is the last thing to write down.

**What rotation means for stored codes.** `comic_share.recipient_user_code`
records how a relationship began and goes stale the moment the recipient
rotates. Nothing treats it as a live handle. The owner's Sharing page and their
recent-recipient list both resolve the recipient's *current* identity through
`ComicShare::recipientUser` — which is why a share made by code links the
account immediately rather than waiting for acceptance. A recipient whose
account has gone keeps their name and loses the code rather than falling back to
the address, because falling back would hand over the one thing the code existed
to withhold. That lookup is the single place this feature joins `User`, and it
is allowed because the rows are already restricted to people the owner shares
with: it resolves a known correspondent, it does not search the directory.

Sharing by username or by code is the ordinary bulk invitation with the recipient
named differently: `POST /api/shares/invitations/bulk` takes `username` or
`userCode` in place of `email`, resolves it server-side, and addresses the
invitation to the account it found. **The sender never learns the address.**
`comic_share` carries `recipient_alias_name` and `recipient_user_code` for exactly
that reason, and the owner-facing serializer returns `recipientEmail: null` with a
`recipientLabel` in its place. Recent recipients list such a person by username
too — putting the withheld address back on the picker would undo the feature.

#### The content codes — "these are mine, come and get them"

`ShareClaimCode` backs both `C-` and `G-`, with `share_claim_code.type` deciding
which and the server enforcing the invariant that goes with it:

| Type | Comics | Enforced |
|---|---|---|
| `C-` | exactly 1 | `POST /api/shares/comic-codes` |
| `G-` | 2–20 | `POST /api/shares/group-codes` |

One table because they are the same capability with different cargo; two
endpoints and an explicit column because *how much a code carries* should be
legible from the code itself rather than discovered on redemption. The old
untyped claim code carried anywhere between one and twenty comics and said so
nowhere.

`share_claim_code.issued_comic_count` records the size of a `G-` package at issue
time, so **an arc cannot quietly shrink** underneath a code that was advertised as
fifteen issues. Do not mutate the comics inside a live group code: withdraw it and
issue another.

Both are capabilities, so both are treated like one:

- **Hashed for redemption**, like an invitation token. An encrypted copy is
  stored alongside the hash so the owner can reveal the code later. The reveal
  is rate-limited and audited; administrators cannot read the code
- **Typed in the hash.** The hash covers the type as well as the token, so a `C-`
  and a `G-` written with the same twelve characters are different codes and
  neither can be looked up as the other
- **Unique across all three kinds.** `SharingCodeService::allocateUniqueCode()` is
  the only place any of them is generated, and it checks `user.user_code` *and*
  `share_claim_code.code_hash` before a candidate is kept. Two unique indexes
  cannot enforce uniqueness across two tables, so each is authoritative inside
  its own table and this allocator is what upholds the invariant between them
- **Dead after `SHARE_CONTENT_CODE_TTL_DAYS`**, seven by default. See
  [Content-code lifetime](#content-code-lifetime)
- **Spent as it is used**, between 1 and 10 times, chosen when it is made, so the
  owner decides up front how far it may travel. A use means *a person*, not a
  request: `share_claim_code_redemption` records which account claimed which
  code, with a unique index on the pair, so one recipient submitting the same
  code ten times spends one use and a repeat is answered idempotently. Without
  it, one person could exhaust an offer advertised to ten, and the owner's
  "claimed 10 of 10" would be counting requests rather than the audience it
  names. The rows are never exposed to the owner — they are told how many people
  took the offer up, which is what they asked
- **Withdrawable at any point** before that, from the Sharing page. Withdrawing
  takes effect on the next redemption attempt and does not touch the shares the
  code already produced
- **Worth nothing without an account.** Redeeming requires being signed in

Redemption is one unit of work with a pessimistic write lock taken on the row
before the remaining uses are read. "Check the count, then decrement it" is a
read followed by a write, and two redemptions arriving together would otherwise
both see the last use — so a one-use code would let two people in, which is the
single guarantee the count exists to make.

**A group is judged whole before any of it is handed over.** Somebody redeeming a
fifteen-issue arc is taking up an offer of fifteen issues, and eleven of them
without a word is worse than none — so if any comic in the package has been
deleted, quarantined or restricted, the redemption fails **without consuming a
use** and the owner can withdraw and reissue. `isShareableBy()` exists as a
predicate for exactly this: a package has to be *asked* about rather than tried
and caught, because trying creates half of it.

**Redeeming a group costs one use, not one per comic.** A use is a person.

**A replay is not a second claim.** The account that already spent a use on a
code can submit it again and be re-told what it holds, even when that use was
the last one — the code is judged for *structural* death (withdrawn, expired,
package broken) before it is judged for exhaustion, and exhaustion only refuses
somebody who has no use of their own. Judging the count first hands a `404` to
the one person who definitely redeemed the code successfully, which is what a
double-click on a one-use code looks like from the inside.

**Overlap is ordinary.** A recipient who already holds part of a group keeps
those relationships and gains the missing ones, and the response distinguishes
what was newly added from what was already theirs.

**Retention.** A dead code — withdrawn, expired, used up or left with no comics —
is kept for **30 days past its expiry** and then deleted by
`app:cleanup-expired-shares`, alongside the expired invitations that command
already sweeps. That command has to be **scheduled on the server**; nothing runs
it on its own, and an instance without the cron keeps every dead code for ever
(see [SSH-deploy.md §7](SSH-deploy.md#7-background-jobs-cron--systemd-timers)).
An administrator can run the same sweep by hand from **Admin → Sharing codes**,
which is a fallback for a broken cron rather than a substitute for one. It cannot be redeemed again the moment it dies, so keeping it is
not a risk; but its owner is still asking how many people took it up and which
comics went with it, and that question outlives the code by rather more than a
day. `ShareClaimCode::RETENTION_AFTER_EXPIRY` is the one place that window is
stated. Only the code rows and their join rows go — the shares a code produced
are ordinary relationships and outlive it entirely.

| Endpoint | Does |
|---|---|
| `POST /api/shares/comic-codes` | mint a `C-` over exactly one comic the owner may share |
| `POST /api/shares/group-codes` | mint a `G-` over a package of 2–20 |
| `GET /api/shares/content-codes` | list codes handed out, live and dead — never the codes themselves |
| `GET /api/shares/content-codes/{id}/reveal` | let the owner read one code again; rate-limited, audited, and available for dead codes where an encrypted copy exists |
| `DELETE /api/shares/content-codes/{id}` | withdraw one |
| `POST /api/shares/content-codes/redeem` | redeem either kind; the prefix decides |

One redeem endpoint for both, because the person pasting a code should not have
to classify it first — the prefix already did. Creation is split in two because
the comic-count invariant differs and the server has to enforce it, and a single
endpoint taking a type would be the same two rules behind one door.

The comic count is refused **on the raw array, before a single id is parsed**.
Checking after the loop would mean a request carrying thousands of ids did
thousands of lookups on its way to a `400`.

Redemption goes through `ComicShareService::claimFromCode()`, not through a
second copy of the share lifecycle. **One service owns what a `ComicShare` is
and how it changes**, whatever transport created it — a transport that grew its
own transitions would drift from the canonical rules, and the acknowledgement
timestamp being recreated at redemption time is exactly the bug that follows.
Claiming emits `SHARE_CREATED`, tagged `via: claim_code`, alongside the
aggregate `SHARE_CLAIM_CODE_REDEEMED`. `SHARE_ACCEPTED` is emitted when the
share is actually accepted: during redemption for non-explicit comics, or after
age confirmation and a later accept for explicit comics.

**Redemption does not hand the owner the redeemer's address.** The owner put a
code into the world and a stranger picked it up; returning that person's email
would turn a sharing feature into address collection. The redeeming account is
linked internally for lifecycle and authorization, and the owner sees the
username — `ComicShare::hideRecipientBehindSharingCode()` is what makes the
owner-facing serializer withhold the address. This is the one respect in which a
content-code share differs from a direct email share, where the sender already
knows the address because they typed it.

It differs from an emailed invitation in three deliberate ways: no token and no
email, because the recipient is right there; redeeming a non-explicit comic
counts as accepting, because typing a code somebody gave you is an affirmative
act; and the sender's acknowledgement is **inherited from the code, not stamped
now** — the owner acknowledged responsibility when they created it, possibly
hours earlier, and `ComicShare::senderResponsibilityAcceptedAt` is the canonical
evidence of when they did. Explicit comics stay pending until the recipient
confirms their age and then accepts them from the Sharing page.

The one rule redemption cannot wave through is the age gate. **An explicit comic
is left pending**, decided before the share is accepted rather than undone
afterwards, so there is no moment where an unconfirmed recipient holds an
accepted share. Everything downstream is the ordinary model: the same
`ComicShare`, the same revocation, the same tombstones. Withdrawing a code does
not touch the shares it already produced; those are ordinary relationships now.

A code whose comics have all been deleted stops being redeemable on its own —
the join table cascades on both sides and `isRedeemable()` requires at least one
comic — so there is no second piece of state to keep in step with a deletion.

#### Why this is not a user directory

Resolving a code is the only place in the application where an identifier
somebody typed is turned into a person, so it is the only enumeration surface
sharing has, and it is built to be a bad one:

- **60 bits of entropy** over an alphabet with no ambiguous characters
- **One generic answer** for every code that does not resolve — malformed, spent,
  expired, revoked or imaginary alike. Telling them apart would say whether a
  guess had ever been real
- **Two allowances, in that order.** `IdentifierLookupGuard` charges an
  `identifier_lookup` allowance for **every** attempt, *before* the repository is
  touched — username resolution, `U-` resolution and `C-`/`G-` redemption alike.
  Behind it, the tighter `sharing_code_lookup` and `username_lookup` allowances
  are charged only for lookups that find nothing, so pasting a real code or
  sharing with somebody you know never meets them. Exhausting either raises a
  `security.share.*_enumeration_attempt` event.

  **The order is the whole point.** A miss-only allowance charged after the
  lookup stops being a control the moment it runs out: a real identifier still
  resolves and an imaginary one is refused, and the difference between those two
  answers is exactly the fact the allowance was protecting. The lookup has to
  become *unreachable*, not merely uncharged, which is why the preflight
  allowance is loose enough that ordinary sharing never notices it and strict
  enough that nobody works through a keyspace behind it
- **Public identity only** on success. Username, display name, and a label built
  from the two. Not the address, not the id, not whether the account is verified,
  active or an administrator. Somebody holding a code is entitled to know they
  reached the right person; everything past that is the account's own
- **A wrong prefix is refused before any lookup**, so it is cheap — but still
  rate limited, because a free high-frequency endpoint is worth having even to an
  attacker who cannot learn anything from one call

Minting content codes has its own `share_claim_code` allowance, because it sends
no mail and the invitation limiter would never see it.

#### Content-code lifetime

`SHARE_CONTENT_CODE_TTL_DAYS` governs how long a `C-` or a `G-` stays
redeemable. It replaced a hardcoded 24 hours, which was invisible to whoever was
handed the code.

```dotenv
# backend/.env.example
SHARE_CONTENT_CODE_TTL_DAYS=7
```

- **Default 7**, wired through `config/services.yaml` into
  `ShareContentCodeLifetime`, which is the single source of truth
- **One setting for both kinds.** They are the same capability with different
  cargo; an installation where comic codes outlived group codes would be
  describing a distinction its users have no way to see
- **Validated at construction**, 1–365. A deployment with a nonsense value fails
  on the way up rather than quietly minting codes that expired before they were
  handed out. 365 is not a security boundary — the operator owns the
  installation — but a typo should not silently produce permanent codes
- **Applied at minting time and written onto the row** as an absolute moment.
  Changing the setting governs codes issued from then on and never reaches back:
  an owner who told somebody "this works until Friday" must not find that an
  operator moved Friday
- **`U-` codes are out of scope.** A user code grants nothing, so there is
  nothing for an expiry to contain; it lives until its owner rotates it

**The frontend never computes expiry.** Creation and list responses carry
`expiresAt` and the UI renders that value, so a display cannot disagree with the
row or go stale when an operator changes the setting.

#### Operating them

Content codes are capabilities that leave the building, so **Admin → Sharing
codes** exists to see what is outstanding and stop one without going to the
database.

| Endpoint | Does |
|---|---|
| `GET /api/admin/sharing-codes` | one page of issued codes, filtered by status, owner, or created/expiry range |
| `POST /api/admin/sharing-codes/{id}/revoke` | withdraw somebody else's code |
| `POST /api/admin/sharing-codes/cleanup` | run the retention sweep by hand |

The table is paginated because it grows continuously between sweeps, and the
status filters (`active`, `expired`, `exhausted`, `withdrawn`,
`comics_removed`) are expressed as predicates over the row and the clock rather
than read from a stored column — a second column saying so would be one more
thing to keep in step.

Three things this surface deliberately cannot do:

- **show a code.** Redemption uses the stored hash and an encrypted copy may
  exist for the owner to reveal, but the admin API never exposes that copy
- **take back a comic.** Withdrawing closes the way in and never the access
  already granted, exactly as it does when the owner withdraws their own code.
  Removing a share is moderation — a different decision, on a different screen
- **delete a live record.** The button runs `ExpiredShareCleanupService`, the
  same service the scheduled command runs, so it can only remove what the
  nightly job would have removed anyway

Revocation goes through `ShareClaimCodeService`, so the admin path cannot grow
its own idea of what withdrawing means, and both it and the manual sweep are
audited with the acting administrator, the target and the counts. The scheduled
command is deliberately *not* audited: a cron job reporting its own quiet runs
is noise, and what it removed is visible in what is no longer there. A person
deleting records from other people's accounts is a different matter.

User codes are not managed here. Their lifecycle is rotation, which lives on the
admin **user** page beside the account it identifies.

#### Still out of scope

Consent-based **sharing contacts** — where an accepted recipient lets the sender
remember them by name without an address — remain future work. They need their
own design for consent, removal, blocking, account deletion and export, and must
not be implemented implicitly on top of recent recipients.

#### Answering
1. The email carries a single **Review invitation** link, good for one claim
   within two months
2. `GET /api/shares/invitations/{token}` returns sender and expiry — plus cover,
   title, author and page count for a comic that is not classified 18+ — and
   changes nothing. It does not spend the token, because mail scanners and
   link-preview services follow links without a person behind them
3. `POST .../accept` or `.../decline` requires a button press. A signed-in
   recipient can also answer from `/sharing` without the token, since they have
   already identified themselves more strongly than the token could
4. Accepting sets the status, **spends the link and revokes every other
   outstanding token for that share**, and the comic appears in the recipient's
   normal collection. The spent link cannot perform another action; reopening
   it may instead report that the invitation was already accepted

#### Losing access
- **Revoke one recipient** (`POST /api/shares/{id}/revoke`) or **stop sharing
  with everyone** (`DELETE /api/shares/comics/{comicId}`) — effective on the
  next request
- **Deleting the comic** tombstones every live share inside the same transaction
  as the removal, so the access records and the comic cannot disagree. The owner
  is warned first, with the number of people affected — from a card, and from
  the table view's bulk deletion
- **Deleting the owner's account** tombstones with `owner_account_deleted` and
  anonymises the owner snapshot, so recipients keep the explanation without the
  erased account keeping a name

#### Recipient housekeeping
- **Remove from my collection** hides the comic and keeps the access record
- **Restore** puts it back, for as long as the owner still shares it
- **Remove all dead shares** (`DELETE /api/shares/tombstones`) clears tombstones
  and other dead ends, individually or in bulk, and never touches a live share.
  The confirmation states the count

### Explicit content and the 18+ gate

> A comic is 18+ because its owner said so. Nothing else says so on their behalf.

`Comic.explicitContent` is a deliberate, comic-level classification, independent
of every tag — including the library-hiding ones. Hiding a comic from your own
library is a shelving preference and says nothing about what is inside it, so
inferring an age rating from it would put an 18+ warning on a comic somebody
merely wanted out of the way. Imports and uploads start non-explicit, existing
comics migrated as non-explicit, and tag edits never touch the flag.

#### Marking 18+ from inside the share flow

The moment somebody decides a comic is adult is almost always the moment they are
about to hand it to somebody else, so `ShareComicsDialog` carries the decision:

```text
[ ] These comics contain 18+ / explicit content
```

Ticking it sets `Comic.explicitContent` on every selected *owned* comic through
`ExplicitContentPromoter`, for a direct share, a `C-` and a `G-` alike. Making
somebody cancel, find the comic, edit it and come back is how a comic goes out
unmarked.

Two properties are load-bearing:

- **One unit of work.** The promotion and the share it belongs to land together
  or neither does. An ordinary share created because the reclassification failed
  would be exactly the accident this exists to prevent — which is why the
  promoter does not flush on its own
- **Promotion only.** An unticked box is the absence of a claim, never a claim
  that the comic is fine. A comic that is already 18+ is shown as already 18+ and
  cannot be silently demoted by sharing it; clearing the flag stays an
  intentional edit on the comic itself

Only the owner may reclassify, and the promoter checks that again rather than
inheriting it from the share authorization. Promoting a comic that is **already
shared** re-gates its existing recipients under the ordinary rule below: a
recipient must not keep unrestricted access merely because their share predates
the correction.

#### The two acknowledgements

Both live on the same `ComicShare` row, so one record is the whole audit trail
for one sharing relationship. Neither timestamp is ever read from the request —
an audit trail the audited party can write is not one.

| Field | Written when | Required |
|---|---|---|
| `senderResponsibilityAcceptedAt` | the invitation is created | every new share |
| `adultConfirmedAt` | the recipient declares they are 18+ | explicit comics only |

`POST /api/shares/invitations/bulk` rejects a body whose
`senderResponsibilityAccepted` is not literally `true`, with
`400 share_responsibility_acknowledgement_required`, and the content-code
endpoints apply the same rule — a missing key or a truthy string is not somebody
having read the notice and ticked the box. Resending preserves both
timestamps — it is the same relationship reaching the same person. Re-inviting
somebody after a decline, revoke or lapse reuses the row but takes a fresh
sender acknowledgement and clears any age declaration: that is a new offer of
the comic as it is now.

#### What an unconfirmed recipient may see

Nothing that identifies the comic. Pending-share, invitation-preview and
recipient serializers all return `null` for `comicId`, `comicTitle`,
`comicAuthor`, `pageCount` and `coverImagePath`, alongside `explicitContent`,
`requiresAdultConfirmation` and `adultConfirmed`. The comic id is redacted with
the rest because it is the key to every endpoint that serves a cover, a page or
an archive. The invitation email names no explicit comic either — an inbox is
previewed on lock screens and read by scanners.

`GET /api/shares/invitations/{token}` needs **both** halves before it reveals
anything: the caller must be identified as the recipient *and* that share must
carry a confirmation. An age declaration is made by one person about themselves,
so it is not a property of the link and cannot unlock the link — this endpoint is
public, and a forwarded email, a scanner or a proxy log holds the same token the
recipient does. It also withholds `adultConfirmed` from everyone else, because
whether the invited person has declared their age is a fact about them.

The client shows a neutral placeholder, never the real cover blurred: blurring
still sends the bytes, and the point of the gate is that they do not leave the
server.

#### Where the gate is enforced

`ComicShareRepository::readableQueryBuilder()` is the single definition of "a
share that lets this user read this comic", and it requires
`explicitContent = false OR adultConfirmedAt IS NOT NULL`. `findAccessFor()`,
`findAccessIndexedByComic()` and `findVisibleCollectionShares()` all build on
it, so `COMIC_VIEW` answers no for an unconfirmed explicit share — and with it
the metadata, cover, page and progress endpoints, not merely the screen that
asks the question. `ComicShareService::accept()` and `acceptShare()` refuse
separately, with `403 adult_confirmation_required`, so a direct API call cannot
put an unconfirmed explicit comic into somebody's collection.

`POST /api/shares/{id}/confirm-adult` and
`POST /api/shares/invitations/{token}/confirm-adult` record the declaration for
the intended, authenticated recipient. They are idempotent: a repeat keeps the
original timestamp, because confirming twice is a retry and not a new
declaration. Declining never requires confirming.

#### Re-gating

Marking an already-shared comic explicit **fails closed**: every live share loses
its `adultConfirmedAt` in the same unit of work as the reclassification. Those
recipients agreed to read something that was not classified 18+, and an old
silence is not a declaration about the comic as it is now. The relationship, its
status and the recipient's place in their own collection all survive — reading
stops, the share does not — and one confirmation restores it. Unmarking the
comic restores access immediately, because there is no longer anything to
confirm about.

#### Cleanup Process
`app:cleanup-expired-shares` deletes pending invitations whose expiry has passed.
Only pending relationships are in scope: an accepted share has no expiry, and a
declined or revoked one is history somebody may still be reading. An expired
invitation is deleted rather than kept because it holds the email address of
somebody who may never have had an account here.

### File Storage

#### Original Comics
- Stored in user-specific directories: `/uploads/comics/{user_id}/{filename}`
- A shared comic has exactly one of these, under its owner's id. Recipients read
  it in place through `/api/comics/{id}/pages/{page}` and
  `/api/comics/cover/{ownerId}/{comicId}/{filename}`, both gated by `COMIC_VIEW`

#### Dropbox-Imported Comics
- Staged in the system temporary directory, then stored like every other source
  at `backend/public/uploads/comics/{user_id}/{generated_filename}`
- Original filenames are preserved from Dropbox
- Tagged with "Dropbox" for easy identification and filtering
- One-way import: files are downloaded from Dropbox to the server

#### Public Cover Images
- Removed. Sharing no longer copies a cover into a world-readable directory;
  covers are served from the authorised cover endpoint, which asks the voter
  whether this viewer may see the comic at all

## Dropbox Integration System

### Overview

The Dropbox integration provides one-way import of enabled comic source formats
from users' Dropbox accounts to the server.

### Configuration

#### Environment Variables

The Dropbox integration is fully configurable via environment variables. Keep
real credentials in the ignored `backend/.env.local`; the committed
`backend/.env` contains safe development defaults:

```env
# =============================================================================
# DROPBOX INTEGRATION CONFIGURATION
# =============================================================================
# Dropbox App Credentials (get from https://www.dropbox.com/developers/apps)
DROPBOX_APP_KEY=your_dropbox_app_key_here
DROPBOX_APP_SECRET=your_dropbox_app_secret_here

# Dropbox OAuth Redirect URI (must match exactly in Dropbox app settings)
DROPBOX_REDIRECT_URI=http://localhost:8080/api/dropbox/callback

# Dropbox App Folder Configuration
# With App folder access, "/" is the root Dropbox assigns to this app.
# With Full Dropbox access, use "/" for the whole account or an explicit path.
DROPBOX_APP_FOLDER=/

# Dropbox Import Batch Configuration
# Maximum number of files to sync per user per sync operation (prevents overload)
DROPBOX_SYNC_LIMIT=10

# Dropbox Rate Limiting (requests per minute to prevent API limits)
DROPBOX_RATE_LIMIT=60
```

All shipped environments default `DROPBOX_APP_FOLDER` to `/`. With App folder
access, Dropbox maps that API root to the app-specific folder it created; the
visible `/Apps/<app-name>` path must not be repeated in the API configuration.
Leaving either credential empty disables the optional integration. The status
API publishes that outcome, the interface explains it without offering a dead
OAuth action, and the connect endpoint also fails closed with HTTP 503.

#### Services Configuration

The configuration is injected via `config/services.yaml`:

```yaml
parameters:
    dropbox_app_folder: '%env(DROPBOX_APP_FOLDER)%'
    dropbox_sync_limit: '%env(int:DROPBOX_SYNC_LIMIT)%'
    dropbox_rate_limit: '%env(int:DROPBOX_RATE_LIMIT)%'

services:
    App\Service\DropboxConfiguration:
        arguments:
            $appKey: '%env(DROPBOX_APP_KEY)%'
            $appSecret: '%env(DROPBOX_APP_SECRET)%'

    App\Controller\DropboxController:
        arguments:
            $dropboxRedirectUri: '%env(DROPBOX_REDIRECT_URI)%'
            $dropboxSyncLimit: '%dropbox_sync_limit%'

    App\Command\DropboxSyncCommand:
        arguments:
            $defaultSyncLimit: '%dropbox_sync_limit%'
```

#### Dropbox App Setup Requirements

**Required Permissions:**
- `files.content.read` - Required for downloading CBZ files
- `files.metadata.read` - Required for listing the import folder and its subfolders

**App Configuration:**
- **Access Type**: "App folder" (recommended) or "Full Dropbox"
- **Redirect URI**: Must match `DROPBOX_REDIRECT_URI` exactly
- **Import Root**: Keep `DROPBOX_APP_FOLDER=/` for App folder access. Dropbox
  creates and names the app folder, then exposes it to the API as `/`; this
  setting does not name or create that folder. With Full Dropbox access, it is
  an ordinary Dropbox path.

#### Configuration Benefits

- **Environment-Specific**: Different settings for dev/staging/prod
- **Centralized**: Single source of truth for all Dropbox settings
- **Type-Safe**: Integer casting for numeric values
- **Maintainable**: Change once, affects all components
- **Secure**: Credentials stored in environment variables, not code

### Integration Workflow

#### Connection Process
1. User clicks "Connect to Dropbox" on the Dropbox Import page
2. System redirects to Dropbox OAuth authorization
3. User authorizes the application
4. Dropbox redirects back with authorization code
5. System exchanges code for access and refresh tokens
6. Tokens are stored in the user's database record

#### Import Process
1. **Manual Import**: Users can trigger an import from the Dropbox Import page
2. **Automatic Import**: The background command can be scheduled via cron
3. **File Discovery**: Recursively scans enabled comic source files in the configured Dropbox root and all subfolders
4. **Tag Generation**: Automatically creates tags from folder structure using intelligent naming conversion
5. **Duplicate Check**: Compares with existing comics to avoid duplicates
6. **Download**: Stages each source in the system temporary directory
7. **Import**: Creates comic entries with "Dropbox" tag plus folder-based tags and metadata

#### File Organization
```
uploads/comics/
├── {user_id}/
│   ├── generated-source-name.cbz # Manual and Dropbox imports share this layout
│   └── covers/
│       └── {comic_id}/
│           └── generated-cover-name.jpg
```

### API Endpoints

- `GET /api/dropbox/connect` - Initiate OAuth flow
- `GET /api/dropbox/callback` - Handle OAuth callback
- `GET /api/dropbox/status` - Return configured and locally stored connection state without waiting on Dropbox
- `POST /api/dropbox/disconnect` - Remove Dropbox connection
- `GET /api/dropbox/files` - List enabled comic source files in Dropbox with import status
- `POST /api/dropbox/import` - Import one listed file
- `POST /api/dropbox/sync` - Trigger a manual import

### Background Sync Command

The `app:dropbox-sync` command provides automated syncing capabilities with configurable defaults:

Both access-token and refresh-token-only connections are selected. The refresh
token is the durable credential, and the client mints a replacement access
token when a scheduled import needs one. `dropboxLastSyncedAt` advances only
after a run finishes with no connection or file errors, so a failed attempt is
never presented as the latest successful import.

```bash
# Basic usage (uses DROPBOX_SYNC_LIMIT from .env, default: 10 files per user)
php bin/console app:dropbox-sync

# Custom limit (overrides environment default)
php bin/console app:dropbox-sync --limit=5

# Specific user only
php bin/console app:dropbox-sync --user-id=123

# Dry run (see what would be synced without actually syncing)
php bin/console app:dropbox-sync --dry-run

# Combine options
php bin/console app:dropbox-sync --user-id=123 --limit=20 --dry-run
```

#### Command Configuration

The command respects these environment variables:

- **`DROPBOX_SYNC_LIMIT`**: Default number of files to sync per user (default: 10)
- **`DROPBOX_APP_FOLDER`**: `/` for App folder access; for Full Dropbox access,
  `/` scans the account root and an explicit path limits imports to that folder
- **`DROPBOX_RATE_LIMIT`**: API rate limiting (default: 60 requests per minute)

#### Rate Limiting & Performance

- **Configurable Limits**: Prevents server overload during automated syncing
- **Per-User Limits**: Each user is limited to the configured number of files per sync
- **API Rate Limiting**: Respects Dropbox API limits to prevent throttling
- **Memory Efficient**: Processes files one at a time to minimize memory usage

#### Cron Integration

Can be scheduled to run automatically with various strategies:

```bash
# Import with environment default limit (10 files per user)
0 0 * * * cd /path/to/project && php bin/console app:dropbox-sync

# Import with custom limit
0 0 * * * cd /path/to/project && php bin/console app:dropbox-sync --limit=5

# Import every 6 hours with rate limiting
0 */6 * * * cd /path/to/project && php bin/console app:dropbox-sync --limit=3

# Import for a specific high-priority user more frequently
*/30 * * * * cd /path/to/project && php bin/console app:dropbox-sync --user-id=1 --limit=1
```

### Frontend Integration

- **Dropbox Import Page**: Complete management interface at `/dropbox-sync`
- **Connection Status**: Local status renders immediately; a failed status request gets a retry action and is never misreported as an unconfigured server
- **File Listing**: Loads independently with its own progress state, responsive actions, and import status indicators
- **Manual Import**: One-click import with progress feedback
- **Dashboard Integration**: Dedicated "Dropbox" tab for imported comics
- **Navigation**: Header includes a link to the Dropbox Import page

### Automatic Tagging System

The Dropbox integration includes an intelligent tagging system that automatically creates tags based on folder structure:

#### Tag Generation Rules

1. **Folder Path Parsing**: Each folder in the path becomes a separate tag
2. **Name Formatting**: Folder names are converted to readable tag names
3. **Case Handling**: Supports multiple naming conventions

#### Supported Naming Conventions

| Folder Name | Generated Tag | Description |
|-------------|---------------|-------------|
| `superHero` | "Super Hero" | camelCase → Title Case |
| `space_opera` | "Space Opera" | snake_case → Title Case |
| `sci-fi` | "Sci Fi" | kebab-case → Title Case |
| `MANGA` | "Manga" | ALL CAPS → Title Case |
| `ActionAdventure` | "Action Adventure" | PascalCase → Title Case |

#### Examples

```
Dropbox Structure → Generated Tags
(with App folder access, Dropbox creates this folder and exposes it as API root `/`)

App folder root (`/`)
├── Superman.cbz → ["Dropbox"]
├── superHero/
│   └── Batman.cbz → ["Dropbox", "Super Hero"]
├── Manga/
│   ├── naruto.cbz → ["Dropbox", "Manga"]
│   └── Anime/
│       └── blackCat.cbz → ["Dropbox", "Manga", "Anime"]
└── sci-fi/
    └── space_opera/
        └── Foundation.cbz → ["Dropbox", "Sci Fi", "Space Opera"]

With Full Dropbox access and `DROPBOX_APP_FOLDER=/MyComics`:

MyComics/
├── Superman.cbz → ["Dropbox"]
├── Marvel/
│   └── Spider-Man.cbz → ["Dropbox", "Marvel"]
└── DC_Comics/
    └── batman_begins.cbz → ["Dropbox", "DC Comics"]
```

#### Implementation Details

- **Recursive Scanning**: The system scans all subfolders recursively
- **Path Extraction**: Uses `dirname()` to extract folder path from file location
- **Tag Deduplication**: Ensures no duplicate tags are created
- **Preservation**: Original folder structure is preserved in file paths
- **Performance**: Efficient single-pass processing during sync

### Security Considerations

- **OAuth 2.0**: Secure token-based authentication with Dropbox
- **CSRF Protection**: State parameter validation during OAuth flow
- **Token Storage**: Encrypted storage of access and refresh tokens
- **App Folder Access**: Limited to app-specific folder in user's Dropbox
- **User Isolation**: Each user's files are stored in separate directories

### Troubleshooting

#### Common Issues

**1. Permission Denied Error**
```
Your app (ID: XXXXXXX) is not permitted to access this endpoint because it does not have the required scope 'files.metadata.read'
```
**Solution**: Enable required scopes in Dropbox App Console:
- Go to https://www.dropbox.com/developers/apps
- Select your app → Permissions tab
- Enable the scopes requested by `DropboxController`: `files.content.read`,
  `files.metadata.read`

**2. Redirect URI Mismatch**
```
redirect_uri_mismatch: The redirect URI does not match the one configured for the app
```
**Solution**: Ensure `DROPBOX_REDIRECT_URI` exactly matches the URI in Dropbox App Console

**3. Cache/Autowiring Issues**
```
Cannot autowire service "App\Command\DropboxSyncCommand"
```
**Solution**: Clear cache and ensure services.yaml is properly configured:
```bash
php bin/console cache:clear
docker compose restart php
```

**4. File Permission Issues**
```
Permission denied when creating directories
```
**Solution**: Fix file permissions:
```bash
chown -R www-data:www-data var/cache var/log public/uploads
chmod -R 775 var/cache var/log public/uploads
```

**5. Missing Spatie Dropbox Package**
```
ClassNotFoundError: Attempted to load class "Client" from namespace "Spatie\Dropbox"
```
**Solution**: Install the package:
```bash
composer require spatie/dropbox-api
composer dump-autoload
```

#### Debug Tips

**Enable Debug Mode**: Set `APP_ENV=dev` in `.env` for detailed error messages

**Login rate limiting**: `POST /api/login` allows five attempts per fifteen
minutes. It is controlled by `LOGIN_RATE_LIMIT_ENABLED`, which is on unless set
to `0` — the committed `backend/.env` sets `0` because development signs the
same accounts in repeatedly from one Docker IP. It is deliberately its own
setting rather than a reading of `APP_ENV`: a staging host is reachable from the
internet whatever it calls its environment. If a login appears to hang locally,
clear the bucket with
`docker compose exec php php bin/console cache:pool:clear cache.rate_limiter`.

Protected frontend routes retain the complete local path, query string and
fragment when they send a signed-out visitor to `/login`, then return there
after authentication. Redirect values are restricted to same-origin paths;
absolute, protocol-relative, backslash and control-character forms fall back to
`/dashboard`.

**Check Logs**: Monitor Symfony logs for detailed error information:
```bash
tail -f var/log/dev.log
```

**Test API Connection**: Use the status endpoint to verify configuration:
```bash
curl -X GET http://localhost:8080/api/dropbox/status \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

**Dry Run Sync**: Test sync without actually downloading files:
```bash
php bin/console app:dropbox-sync --dry-run --user-id=1
```

## Architecture Details

### Directory Structure

```
./
├── frontend/           # React frontend application
│   ├── src/            # React source code
│   │   ├── components/ # UI components, incl. reader/, library/ and ads/
│   │   ├── hooks/      # Custom React hooks including authentication
│   │   ├── pages/      # Page components
│   │   └── lib/        # Utility functions
│   ├── scripts/        # Build-time checks (SEO, audit, conversion tools)
│   ├── public/         # Static assets for frontend
│   └── package.json    # Frontend dependencies
├── backend/            # Symfony backend application
│   ├── src/            # Symfony source code
│   │   ├── ComicSource/# Per-format page providers
│   │   ├── Command/    # CLI commands
│   │   ├── Controller/ # API controllers
│   │   ├── Entity/     # Database entities
│   │   ├── Monolog/    # Log handlers and redaction
│   │   ├── Repository/ # Doctrine repositories
│   │   ├── Security/   # Authentication handlers and voters
│   │   └── Service/    # Application services
│   ├── migrations/     # Doctrine migrations
│   ├── templates/      # Twig email templates
│   └── tests/          # PHPUnit unit and functional tests
├── docs/               # Per-feature documentation
├── scripts/            # Release, deployment, backup, and server scripts
├── docker/             # Docker configuration files
├── docker-compose.yml  # Docker Compose configuration for all services
└── .env                # Main environment variables file
```

### Database Schema

The four entities below are the core of the model. The sharing entities
(`ComicShare`, `ShareInvitationToken`, `ShareClaimCode`,
`ShareClaimCodeRedemption`) are described under
[Comic Sharing System](#comic-sharing-system); the rest — `AdminAuditLog`,
`ComicFormatConfiguration`, `ContentReport`, `EmailVerificationToken`,
`LibraryFolder`, `LibraryFolderItem`, `MetadataProviderConfiguration`,
`PendingFileDeletion`, `ResetPasswordToken`, `UserMetadataCredential`,
`UserWarning` — are documented in their own feature pages under `docs/`.
`backend/src/Entity/` is the authoritative list.

#### User Entity
- `id`: Primary key
- `email`: User's email (unique) — private account data, and still the
  authentication identifier
- `username`: The account's public identity (unique, required)
- `usernameCanonical`: Its lowercase twin, which carries the unique index — see
  [What a username may be](#what-a-username-may-be)
- `userCode`: The rotatable `U-` recipient code (unique, required)
- `password`: Hashed password
- `roles`: Array of user roles (ROLE_USER, ROLE_ADMIN)
- `name`: Display name (optional, **not** unique — never used alone to confirm a
  share recipient)
- `dropboxAccessToken`: Dropbox OAuth access token (nullable)
- `dropboxRefreshToken`: Dropbox OAuth refresh token (nullable)
- `createdAt`: Timestamp of user creation
- `updatedAt`: Timestamp of last update
- Relationships:
  - One-to-Many with Comic (owner)
  - One-to-Many with ComicReadingProgress
  - One-to-Many with Tag (creator)

#### Comic Entity

The canonical source record. `Comic.php` is the authoritative field list; the
groups below say what each part is for.

- Identity and file: `id`, `title`, `filePath` (the canonical source, in any
  enabled format — not necessarily a CBZ), `sourceType` (the `ComicSourceType`
  enum that decides which page provider reads it), `originalFilename`,
  `fileSize` (counted against the owner's quota), `coverImagePath`, `pageCount`
- Timestamps: `uploadedAt`, `updatedAt`
- Descriptive metadata, from `ComicInfo.xml`, a provider, or the filename:
  `description`, `author`, `publisher`, `series`, `issueNumber`, `issueCount`,
  `volume`, `publishedAt`, `languageCode`, `ageRating`, `readingDirection`,
  `creators`, `pageMetadata`, `classification`
- Enrichment provenance: `metadataProvider`, `metadataExternalId`,
  `metadataFetchedAt` — see [metadata-enrichment.md](docs/metadata-enrichment.md)
- Import origin: `dropboxPath`
- Moderation and access: `explicitContent` (the 18+ gate),
  `sharingRestrictedAt`, `quarantinedAt` — see
  [content-reporting.md](docs/content-reporting.md)
- Relationships:
  - Many-to-One with User (owner)
  - One-to-Many with ComicReadingProgress
  - One-to-Many with ComicShare
  - Many-to-Many with Tag

#### ComicReadingProgress Entity
- `id`: Primary key
- `currentPage`: Current page number
- `lastReadAt`: Timestamp of last reading
- Relationships:
  - Many-to-One with User
  - Many-to-One with Comic

#### Tag Entity
- `id`: Primary key
- `name`: Tag name
- `createdAt`: Timestamp of tag creation
- Relationships:
  - Many-to-One with User (creator)
  - Many-to-Many with Comic

### API Endpoints

A representative subset, not an inventory: the application serves over a hundred
API routes. `php bin/console debug:router` is the authoritative list, and the
sharing, metadata, library-folder, admin and content-report endpoints are
documented in their own pages under `docs/`.

#### Authentication
- `POST /api/login` - Login with email and password
- `POST /api/register` - Register a new user
- `POST /api/logout` - Logout the current user
- `GET /api/login_check` - Check if the user is authenticated
- `GET /api/me` - The session probe. Public, because every public page asks it
  whether a session exists: signed out is answered as `{"user": null}` with a
  `200` rather than a `401`, so being logged out is not reported to the browser
  as a failed request.
- `POST /api/me` - The authenticated keep-alive. Still behind
  `IS_AUTHENTICATED_FULLY`; refreshes the session and returns the user.
- `POST /api/forgot-password` - Request a password reset email
- `GET /api/reset-password/validate/{token}` - Validate a password reset token
- `POST /api/reset-password/reset/{token}` - Reset password with a valid token

#### Comics
- `GET /api/comics` - Get all comics for the current user
- `GET /api/comics/{id}` - Get a specific comic by ID
- `POST /api/comics` - Upload a new comic (multipart/form-data with file, title, and optional fields)
- `PUT/PATCH /api/comics/{id}` - Update a comic's information
- `DELETE /api/comics/{id}` - Delete a comic
- `GET /api/comics/{id}/pages/{page}` - Get a specific page from a comic
- `GET /api/comics/cover/{ownerId}/{comicId}/{filename}` - Get an authorised cover image
- `POST /api/comics/{id}/progress` - Update reading progress for a comic

#### Tags
- `GET /api/tags` - Get all tags
- `POST /api/tags` - Create a new tag
- `PUT/PATCH /api/tags/{id}` - Update a tag
- `DELETE /api/tags/{id}` - Delete a tag

#### Library folders
- `GET /api/library/folders` - The caller's folder tree
- `POST /api/library/folders` - Create a folder, optionally under a parent
- `PATCH /api/library/folders/{id}` - Rename or move a folder
- `DELETE /api/library/folders/{id}` - Delete a folder; a two-step confirmation
  when it is not empty
- `POST /api/library/folders/move-comics` - Place up to 500 comics in a folder,
  or back at the root

#### User Management (Admin only)
- `GET /api/users` - Get all users (admin only)
- `GET /api/users/{id}` - Get a specific user
- `PUT/PATCH /api/users/{id}` - Update a user
- `DELETE /api/users/{id}` - Delete a user

### File Storage Organization

#### Comics
Comics are stored in user-specific directories to ensure proper separation of user content:
```
/uploads/comics/{user_id}/{comic_file.cbz}
```

For example:
- `/uploads/comics/1/my_comic.cbz` - Comic owned by user with ID 1
- `/uploads/comics/2/another_comic.cbz` - Comic owned by user with ID 2

#### Cover Images
Cover images are stored under the owner and comic:
```
backend/public/uploads/comics/{user_id}/covers/{comic_id}/{cover_image}
```

They are not served as public upload paths. `ComicSerializer` emits
`/api/comics/cover/{ownerId}/{comicId}/{filename}`, and `ComicPageController`
checks `COMIC_VIEW` before reading the file.

### Email Testing with Mailpit

The application uses Mailpit for email testing during development. Mailpit captures all outgoing emails and provides a web interface to view them without actually sending them to real email addresses.

- **SMTP Server**: Available at `mailpit:1025` inside the Docker network
- **Web UI**: Available at http://localhost:8025 for viewing captured emails
- **Usage**: When testing the password reset functionality, check the Mailpit UI to see the reset emails

#### Email Delivery Configuration

Symphony's Messenger component is used for handling emails. By default, Symfony would queue emails for asynchronous delivery, but we've modified this for development to make emails send immediately:

1. **Development Configuration** (current setup):
   - In `config/packages/messenger.yaml`, the email routing is commented out:
     ```yaml
     routing:
         # Comment out this line to send emails synchronously
         # Symfony\Component\Mailer\Messenger\SendEmailMessage: async
         Symfony\Component\Notifier\Message\ChatMessage: async
         Symfony\Component\Notifier\Message\SmsMessage: async
     ```
   - This makes all emails send immediately (synchronously) without requiring a message consumer
   - Emails appear in Mailpit right away
   - This is ideal for development and testing

2. **Queuing mail in production** (optional, and still not the default):
   - Uncomment the mailer routing line:
     ```yaml
     routing:
         Symfony\Component\Mailer\Messenger\SendEmailMessage: async
         Symfony\Component\Notifier\Message\ChatMessage: async
         Symfony\Component\Notifier\Message\SmsMessage: async
     ```
   - This queues mail in the database (`messenger_messages` table), and you
     must then run a consumer or nothing is ever delivered:
     ```bash
     php bin/console messenger:consume async
     ```
   - Keep it alive with a systemd service or supervisor process. Do not enable
     this without one: an installation that queues mail with no worker sends
     nothing and reports no error.

#### The one transport this application actually routes to

`share_notifications` carries `App\Message\ShareInvitationNotification` — the
notice telling somebody about a share they have already been given. It is
`sync://` by default, set through `SHARE_NOTIFICATION_TRANSPORT_DSN`.

That default is deliberate. Nothing else in this application routes to a queue,
so an installation that switched to a queued notice without also gaining a
worker would create shares and silently never tell anybody — a worse failure
than the one a queue fixes. The property the design relies on holds either way:
the notice is dispatched **after** the shares commit, so a mail server having a
bad minute costs a notification and never a share, and Resend recovers it.

The message carries share ids and nothing else. The handler reloads the
relationships and mints the invitation links as it writes the mail, so no
plaintext invitation token is ever written to the queue, retried through it, or
left in the failure transport for an operator to read.

Switch it to `doctrine://default?auto_setup=0` once
`messenger:consume share_notifications` runs as a service — see
[SSH-deploy.md §7.3](SSH-deploy.md#73-symfony-messenger-consumer--optional).
Tests pin both transports to `sync://` so the handler runs inline regardless.

#### Debugging Email Issues

If emails aren't appearing in Mailpit:

1. Check if emails are being queued in the database:
   ```bash
   docker compose exec php bin/console messenger:stats
   ```

2. If there are queued messages but no consumer is running:
   ```bash
   docker compose exec php bin/console messenger:consume async --time-limit=3600
   ```

3. Check for failed messages:
   ```bash
   docker compose exec php bin/console messenger:failed:show
   ```

4. Test email sending directly:
   ```bash
   docker compose exec php bin/console app:test-mail --to=test@example.com
   ```

### Testing the Current Implementation

### Test Users
Test users are created for development and testing purposes. Their credentials are stored in `passwords.txt` (which is in `.gitignore`):
- Admin user: `testadmin@example.com` with password `AdminPass123!`
- Regular user: `testuser1@example.com` with password `UserPass123!`
- Regular user: `testuser2@example.com` with password `UserPass123!`

### Automated Test Suites

```sh
# Backend — PHPUnit, needs the database container up
docker compose exec php php vendor/bin/phpunit

# Frontend — Vitest
cd frontend && npm test
```

The frontend suite is split into two Vitest projects, by file extension, because
the two kinds of test genuinely differ:

| Pattern | Project | Environment | For |
|---|---|---|---|
| `src/**/*.test.js` | `unit` | `node` | pure logic — helpers, classification and wording rules |
| `src/**/*.test.jsx` | `dom` | `jsdom` | a rendered component, driven through Testing Library |

Standing a DOM up for the helper tests would cost seconds per file to give them
something none of them touch, so the extension decides. `src/test/setup.js` runs
for the `dom` project only. It registers `@testing-library/jest-dom`, unmounts
between tests — auto-cleanup does not register itself while Vitest's globals are
off, and they deliberately are — and stubs the browser APIs Radix calls during a
normal mount that jsdom does not implement: `matchMedia`, `ResizeObserver`,
`IntersectionObserver`, `scrollIntoView` and the pointer-capture methods. Without
them a dialog or checkbox throws before a single assertion runs, and the failure
reads like a bug in the component rather than a gap in the environment.

Component tests mock the modules at the edges — `@/lib/api`, the toast, auth,
sharing and library hooks — and render the real component with the real UI
primitives, so what is asserted is what a user would see. Copy is asserted
against the constants exported from `lib/sharing.js` rather than against
paraphrases, which is what stops the responsibility notice or the 18+ warning
being watered down in a component without a test noticing.

### Testing Commands
You can test the current implementation using the following commands:

```sh
# Test API endpoints (registration and login)
docker compose exec php bin/console app:test-api-endpoints

# Import comics from a directory
docker compose exec php bin/console app:import-comics /path/to/comics testuser1@example.com

# Clean up unused comics and cover images (dry run)
docker compose exec php bin/console app:cleanup-comics --dry-run
```

### Manual API Testing
You can also test the API endpoints manually using tools like Postman or curl:

```sh
# Login
curl -X POST http://localhost:8080/api/login -H "Content-Type: application/json" -d '{"email":"testadmin@example.com","password":"AdminPass123!"}'

# Register
curl -X POST http://localhost:8080/api/register -H "Content-Type: application/json" -d '{"email":"newuser@example.com","password":"NewPassword123!"}'

# Get Comics (requires authentication cookie from login)
curl -X GET http://localhost:8080/api/comics -H "Content-Type: application/json" -b cookies.txt
```

## Subsystem notes

### The comic reader

The reader is documented for its users in [docs/reader.md](docs/reader.md), which
is the page to read first — it covers page sizing, reading modes and direction,
touch and mouse rules, the page navigator, and the extension contract that keeps
navigation on logical source page numbers.

What matters here is where the code lives, because the reader is deliberately
not one component:

- **Renderers** — `components/reader/SinglePageReader.jsx`,
  `SpreadPageReader.jsx` and `ContinuousPageReader.jsx`. Continuous is the
  default. All three consume the same logical navigation and never persist a
  synthetic spread, viewport number or scroll percentage.
- **Preloading** — `hooks/use-preload-window.js` decides how many pages are held
  ready from what the device can afford (roughly five on a desktop, three on a
  tablet, two on a phone, less again on a slow or data-saving connection). One
  window governs both what is fetched early and what is released; there is no
  setting and no fixed number.
- **Input** — `lib/reader-gestures.js` with `hooks/use-reader-gestures.js` for
  touch, `use-reader-mouse-pan.js` for the mouse, `use-reader-transform.js` for
  the zoom transform, and `use-reader-navigation.js` for page movement.
- **Layout and context** — `use-page-geometry.js`, `use-page-variant.js`,
  `use-viewport-profile.js` and `use-reader-chrome.js`.
- **Preferences** — `use-reader-preferences.jsx` against the versioned envelope
  validated by `backend/src/Reader/ReaderPreferences.php`.

Pages are requested at one of a fixed set of sizes rather than at whatever the
uploader exported, and are converted and cached server-side — see
[docs/page-derivatives.md](docs/page-derivatives.md). The reader holds decoded
images only inside the preload window; it does not build data URLs, and there is
no debug panel.


### Email System Implementation

#### ✅ Email Configuration
- **Mailpit Integration**: Configured for email testing in development
- **Email Templates**: HTML email templates implemented for:
  - Password reset requests
  - Password change notifications
- **Synchronous Delivery**: Configured for immediate delivery in development
- **Asynchronous Support**: Ready for production with Messenger component

#### ✅ Email Testing with Mailpit
- **SMTP Server**: Available at `mailpit:1025` inside the Docker network
- **Web UI**: Available at http://localhost:8025 for viewing captured emails
- **Test Command**: `app:test-mail` command available for testing email delivery
- **Debug Tools**: Messenger commands for diagnosing email delivery issues:
  ```bash
  # Check message queue status
  docker compose exec php bin/console messenger:stats
  
  # Process queued messages (for async mode)
  docker compose exec php bin/console messenger:consume async
  
  # Check for failed messages
  docker compose exec php bin/console messenger:failed:show
  ```

## Getting Started (Development)

### Prerequisites
- Docker and Docker Compose
- Git

### Setup
1. Clone the repository
2. Write this checkout's `.env` — Compose project name, ports, and the UID the
   containers run as. Once per clone or worktree; see
   [docs/local-docker-environment.md](docs/local-docker-environment.md):
   ```sh
   scripts/dev-env.sh
   ```
3. Start the Docker containers:
   ```sh
   docker compose up -d --build
   ```
4. Install backend dependencies and create the schema:
   ```sh
   docker compose exec php composer install
   docker compose exec php php bin/console doctrine:database:create --if-not-exists
   docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
   ```
5. Set up the upload directories:
   ```sh
   docker compose exec php bin/console app:setup-upload-directories
   ```
6. Create test users:
   ```sh
   docker compose exec php bin/console app:create-admin-user testadmin@example.com AdminPass123!
   docker compose exec php bin/console app:create-user testuser1@example.com UserPass123!
   ```
7. Import comics (optional):
   ```sh
   docker compose exec php bin/console app:import-comics /path/to/comics testuser1@example.com
   ```

Only CBZ is enabled on a new installation. To read CBR, CB7, CBT or PDF, open
**Admin → Formats** and enable what
`docker compose exec php php bin/console app:comic-formats:check` reports as
available — see [docs/comic-formats.md](docs/comic-formats.md).

### Frontend Development with Live Reload

The frontend React application is now served directly by a dedicated Vite development server running inside the `frontend_dev` Docker service. This provides Hot Module Replacement (HMR) for a significantly improved development experience.

**Key Details:**
*   **Access URL:** To view and interact with the live-reloading frontend, open your browser to **`http://localhost:3001`**.
*   **Live Reload / HMR:** When you make changes to files within the `./frontend/src` directory on your host machine, the Vite server inside the `frontend_dev` container will automatically detect these changes, rebuild the necessary parts of the application, and push updates to your browser, often without a full page refresh.
*   **Deterministic Dependency Installation:** The `frontend_dev` service automatically runs `npm ci` when it starts, installing exactly what `package-lock.json` records without rewriting the tracked lockfile. Run `npm install` on the host only when intentionally changing dependencies and their lockfile.
*   **Role of `nginx` service (Port 8080):** The original `nginx` service (accessible at `http://localhost:8080` or your `${NGINX_PORT}`) continues to be responsible for:
    *   Proxying API requests (e.g., `/api/...`) to the backend PHP service. The Vite dev server on port 3001 is configured to route its API calls to this Nginx service.
    *   Serving a static build of the frontend if you were to build it for production (e.g., via `npm run build` results). For development, port 3001 is primary.

To start developing the frontend:
1.  Ensure all Docker services are running with `docker compose up -d`.
2.  Navigate to `http://localhost:3001` in your browser.
3.  Begin editing files in the `./frontend` directory.

## Design constraints worth knowing

These are decisions that keep being rediscovered, not a backlog.

1. **Comic sources are attacker-controlled.** Anything read out of an archive,
   a PDF object, XML or a filename is untrusted: bound it before allocating,
   never let it reach a filesystem path or a subprocess argument without a
   whitelist or an enum, and fail to a working page rather than a stack trace.
   Limits and the reasoning are in
   [docs/comic-formats.md](docs/comic-formats.md).

2. **Storage is the filesystem, deliberately.** Canonical sources live under
   `backend/public/uploads/comics/{user_id}/` and are served only through
   ownership-aware endpoints; generated pages live outside the web root in
   `backend/var/page-cache/`. A backup that omits either the files or
   `APP_DATA_KEY` is not a backup.

3. **Authentication is stateful sessions, on purpose.** Same-origin cookies with
   CSRF checks, not JWTs: there is no third-party consumer of this API, and a
   revocable server-side session is what lets a password change invalidate the
   sessions opened before it.

4. **Access is the voter's decision, everywhere.** `ComicVoter` answers
   `COMIC_VIEW`, `COMIC_EDIT`, `COMIC_DELETE`, `COMIC_SHARE` and `COMIC_KNOW`.
   No endpoint reimplements ownership; see
   [docs/comic-access.md](docs/comic-access.md).

5. **Every change ships with tests.** See `AGENTS.md` — this is a hard rule, and
   the suites under [Automated Test Suites](#automated-test-suites) plus the CI
   gates in [README.md](README.md#continuous-integration) are what enforce it.

## Troubleshooting

### Docker and Windows Line Endings

When running the project on Windows, you may encounter issues with line endings in shell scripts and PHP files. This is because Windows uses CRLF (\r\n) line endings, while Linux uses LF (\n) line endings.

**Symptoms:**
- Error messages like `/usr/bin/env: 'php\r': No such file or directory`
- Scripts failing to execute with `not found` errors

**Solutions:**
1. Fix line endings in the console script:
   ```sh
   docker compose exec php sed -i 's/\r$//' /var/www/html/bin/console
   ```

2. Configure Git to handle line endings properly:
   ```sh
   git config --global core.autocrlf input
   ```

### Hot Reload Issues with Docker on Windows

The frontend development server may not detect file changes properly when running in Docker on Windows.

**Symptoms:**
- Changes to frontend files are not reflected in the browser
- No file change detection messages in the frontend_dev container logs

**Solutions:**
`frontend_dev` already carries the settings this needs — `CHOKIDAR_USEPOLLING`
and `WATCHPACK_POLLING` for polling-based detection, `--host 0.0.0.0 --force`
on the Vite command, and an anonymous volume over `/app/node_modules` so the
container's dependency tree is not masked by the host's. If changes still go
unnoticed, confirm the container is mounting *this* checkout rather than
another one's:

```sh
docker inspect "$(docker compose ps -q frontend_dev)" --format '{{range .Mounts}}{{.Source}}{{"\n"}}{{end}}'
```

See [docs/local-docker-environment.md](docs/local-docker-environment.md).

### Permission errors on generated files

**Symptoms:**
- `composer install`, `cache:clear` or `git clean` fails with "Permission denied"
- Files under `backend/var/`, `backend/vendor/` or `frontend/coverage/` are owned by `root` or by uid 33
- A test fails on code that does not match your working tree

**Solutions:**
Containers run as your host UID/GID, so this should not recur. To repair a
checkout that predates that change, and to confirm each checkout owns its own
stack:

```sh
scripts/dev-env.sh          # per-checkout project name, ports, UID
scripts/fix-ownership.sh    # reclaim root-owned files, no sudo needed
```

Both are documented in
[docs/local-docker-environment.md](docs/local-docker-environment.md).

## Production Deployment

### CI does not deploy

`.github/workflows/build-frontend.yml` is named **Validate Application** and is
exactly that. It builds, lints, tests and audits both halves of the application
on pull requests into `main`, `develop`, `feature/**`, `docs/**`, `fix/**` and
`ci/**`, and on pushes to `main` and `develop`. It uploads the frontend build as
an artifact and stops there.

This is deliberate, and the workflow says so at the bottom of the file: frontend
and backend changes can depend on each other, so they ship together through the
backup-gated release scripts rather than one half being FTP'd on merge. An
earlier version of this project did deploy the frontend automatically, and
`deploy.md` still carries the lessons from what that cost.

### How a release actually goes out

Two supported paths, both driven from `scripts/` and both gated on a verified
backup:

| Path | Guide | Use when |
|---|---|---|
| SSH + Git | [SSH-deploy.md](SSH-deploy.md) | The server has SSH and Git access — a VPS |
| FTP/FTPS packages | [deploy.md](deploy.md) | Shared hosting with no shell |

The scripts are `build-release.sh`, `deploy-ssh.sh`, `deploy-ftp.sh` and
`post-deploy.sh`, with `scripts/server/` holding the install and backup helpers.
They build the React application, install optimized production Composer
dependencies, leave the host's `backend/.env.local` alone, and exclude
`public/uploads/` from every transfer.

Deployment configuration lives in `scripts/.env.deploy`, which is gitignored and
never committed. The variables it holds — `DEPLOY_CONFIG_MODE`, `PROD_*`, `SSH_*`, `FTP_*` and
`POST_DEPLOY_TOKEN` — are documented in the two guides above rather than
duplicated here, because they are the thing most likely to drift.

Runtime configuration is a deployment-mode decision. The default
`DEPLOY_CONFIG_MODE=server-local` ships no dotenv file at all and reads the
host's ignored `backend/.env.local`, so advertising is switched on there and
`PROD_ADSENSE_*` in `scripts/.env.deploy` is not consulted. The opt-in
`compiled` mode runs `composer dump-env prod` and bakes `PROD_*` into
`backend/.env.local.php`, which Symfony then reads *instead of* `.env.local` —
later edits to that file change nothing until the compiled one is regenerated or
removed. See the production checklist in
[docs/advertising.md](docs/advertising.md).

### Before every release

1. Verify a current database backup.
2. Verify a current `backend/public/uploads/` backup.
3. Confirm the backed-up `APP_DATA_KEY` matches production.
4. Confirm the release checkout is clean, committed, and matches `origin/main`.
5. Build and deploy frontend and backend as one release.
6. Apply Doctrine migrations and any documented data-upgrade commands.
7. Run `php bin/console app:comic-formats:check` on the server.
8. Complete authenticated smoke tests.

Never deploy only `frontend/dist`.

### Branching

- Branch ongoing feature, fix, and documentation work from fetched
  `origin/develop`, and target `develop` with its pull request. Never commit to
  either protected branch directly.
- `develop` is the integration source of truth: work that wants manual testing
  on a real deployment lands there first and reaches `main` as one deliberate
  release merge.
- `main` reflects the production state; urgent production hotfixes branch from
  it and are brought back into `develop` through a separate pull request.

### Scheduled maintenance

Nothing in the application schedules itself, and retention settings are policy
only — a command has to run for anything to be deleted. A production instance
needs `app:cleanup-logs`, `app:cleanup-personal-data` and
`app:cleanup-expired-shares` daily, plus `app:cleanup-content-reports`, and
`app:dropbox-sync` if the instance uses Dropbox imports. Crontab examples are in
[SSH-deploy.md §7](SSH-deploy.md#7-background-jobs-cron--systemd-timers);
content-report retention details are in
[docs/content-reporting.md](docs/content-reporting.md#configuration).
