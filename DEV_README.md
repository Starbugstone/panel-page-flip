# CBZ Comic Reader - Developer Documentation

## Project Overview

CBZ Comic Reader is a web application that allows users to read comic books in CBZ format. The application features a secure login system, a comic selection interface, and a reading progress tracker that remembers where you left off.

This document provides detailed information for developers working on the project, including current implementation status, architecture details, and next steps.

## Current Implementation Status

### Backend (Symfony)

#### ✅ User Authentication System
- **JSON Login**: Implemented in `security.yaml` with proper routes and handlers
- **Registration**: Implemented in `RegistrationController.php`
- **Password Reset**: Implemented in `ResetPasswordController.php` with email notifications
- **User Entity**: Defined in `User.php` with proper properties and relationships
- **Security**: Access control rules defined to secure API endpoints

#### ✅ Comic Management System
- **Comic Entity**: Defined in `Comic.php` with properties for title, file path, cover image, etc.
- **Comic Controller**: Implemented in `ComicController.php` with endpoints for CRUD operations. Includes refined permission checks for operations like comic deletion (owner/admin only).
- **File Storage**: Comics are stored in user-specific directories at `/uploads/comics/{user_id}/{comic_file.cbz}`
- **Cover Images**: Stored in comic-specific directories at `/uploads/comics/covers/{comic_id}/{cover_image.jpg}`
- **Chunked Upload**: Implemented chunked file upload system to handle large comic files (1MB chunks)
  - Initialization endpoint: `/api/comics/upload/init`
  - Chunk upload endpoint: `/api/comics/upload/chunk`
  - Completion endpoint: `/api/comics/upload/complete`

#### ✅ Reading Progress Tracking
- **ComicReadingProgress Entity**: Defined in `ComicReadingProgress.php` to track user reading progress
- **API Endpoints**: Available for saving and retrieving reading progress

#### ✅ Tag System
- **Tag Entity**: Defined in `Tag.php` for categorizing comics
- **API Endpoints**: Available for managing tags and associating them with comics
- **Per-User Tags**: Tags are unique per user (creator), allowing different users to have tags with the same name

#### ✅ Comic Sharing System
- **ComicShare Entity**: Defined in `ComicShare.php`. A durable, revocable grant of read access to the owner's single copy — sharing never creates a second `Comic` row or a second file
- **ShareInvitationToken Entity**: Defined in `ShareInvitationToken.php`. Only a SHA-256 hash of each invitation link is stored. One link per invitation, good for a single claim within two months; accepting spends it and revokes every other token for that share, and resending mints a new one and retires the old link
- **ComicVoter**: `Security/Voter/ComicVoter.php` answers `COMIC_VIEW`, `COMIC_EDIT`, `COMIC_DELETE` and `COMIC_SHARE` for every endpoint that touches a comic
- **Share Controller**: `ShareController.php` under `/api/shares` — invite, resend, revoke, stop sharing, preview, accept, decline, remove, restore and tombstone cleanup
- **Tombstones**: Deleting a comic nulls the relationship and records `unavailableAt` plus a `tombstoneReason`, so recipients are told why a comic disappeared. They are recipient-only — the owner caused the deletion, has no comic left to manage, and the comic leaves their sharing list entirely
- **Email Notifications**: One "Review invitation" link per email; the link only previews, because mail scanners follow links on the recipient's behalf
- **Cleanup Command**: `CleanupExpiredSharesCommand` deletes pending invitations that expired unanswered

#### ✅ Dropbox Integration System
- **DropboxController**: Handles OAuth flow, connection status, file listing, and individual comic import
- **Dropbox OAuth Flow**: Complete implementation with CSRF protection, token management, and proper scopes
- **Individual Import**: Users can import specific comics from their Dropbox with individual import buttons
- **Smart Sync Detection**: Robust duplicate detection using filename and title similarity matching
- **Automatic Tagging**: Intelligent conversion of folder names to tags (camelCase, snake_case, kebab-case support)
- **API Endpoints**: Status check, disconnect, file listing with sync status, and individual import
- **Background Sync**: Console command for automated syncing with rate limiting (still available for bulk operations)

#### ✅ Utility Commands
- **CreateUserCommand**: Creates regular users (`app:create-user`)
- **CreateAdminUserCommand**: Creates admin users (`app:create-admin-user`)
- **ImportComicsCommand**: Imports comics from a directory (`app:import-comics`)
- **CleanupComicsCommand**: Cleans up orphaned comic files and cover images (`app:cleanup-comics`)
- **SetupUploadDirectoriesCommand**: Sets up necessary directories for uploads (`app:setup-upload-directories`)
- **GenerateSampleDataCommand**: Generates sample data for testing (`app:generate-sample-data`)
- **TestApiEndpointsCommand**: Tests API endpoints for registration and login (`app:test-api-endpoints`)
- **DropboxSyncCommand**: Syncs comics from Dropbox for all connected users (`app:dropbox-sync`)
- **CleanupLogsCommand**: Deletes daily log files past their retention period (`app:cleanup-logs`)

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

#### ✅ Comic Reader
- **Reading Interface**: Core reading functionality implemented in `ComicReader.jsx`
- **Advanced Caching System**: Implemented a robust caching system that stores comic pages as data URLs to prevent unnecessary network requests
- **Optimized Page Loading**: Uses a priority queue system to load pages in order of likelihood to be viewed next
- **Memory Management**: Automatically cleans up cached pages outside the viewing window (±5 pages) to prevent memory overflow
- **Network Optimization**: Prevents duplicate network requests by tracking in-progress loads
- **Responsive UI**: Immediately displays cached pages while loading new ones in the background
- **Debug Panel**: Provides real-time visibility into cache state and loading processes

#### ✅ Admin Interface
- **Admin Dashboard**: Implemented in `AdminDashboard.jsx`, now includes a loading indicator during user authentication.
- **Admin Users Management**: Enhanced UI in `AdminUsersList.jsx` for managing user roles (e.g., ensuring `ROLE_USER` persistence, clearer role assignment).
- **Admin Comics List**: Improved tag display in `AdminComicsList.jsx` to correctly handle various tag data formats.
- **Admin Tags List**: UI refinements in `AdminTagsList.jsx` for tag creation and editing dialogs.

#### ✅ Comic Upload
- **Upload Comic**: Comic upload interface implemented in `UploadComic.jsx` with chunked upload support, progress tracking, and tag management

#### ✅ Comic Sharing
- **Sharing Page**: `Sharing.jsx` at `/sharing`, with "Shared with me" and "Shared by me" tabs — the management surface for invitations, access and tombstones
- **Share Comic Modal**: `ShareComicModal.jsx` invites a recipient and shows the invitation link once, since only its hash is stored
- **Invitation Preview**: `ShareInvitation.jsx` at `/share/invitation/:token` loads the invitation through a safe `GET` and only accepts or declines on a button press
- **Pending Shares Alert**: `PendingSharesAlert.jsx`, now a one-line prompt on the dashboard rather than a card per invitation
- **Sharing Hooks**: `use-sharing.jsx` — `SharingProvider` holds the pending count for the header badge and the dashboard alert; `useSharingLists` loads both halves of the Sharing page
- **Sharing Helpers**: `lib/sharing.js` holds the classification and wording rules, covered by `lib/sharing.test.js`
- **Collection Integration**: Accepted shares appear in the normal collection with a "Shared by …" badge, an `All | Mine | Shared with me` filter, and owner actions hidden

#### ✅ Dropbox Integration
- **Dropbox Sync Page**: Complete UI in `DropboxSyncPage.jsx` for managing Dropbox connection and individual imports
- **Connection Status**: Real-time detection of Dropbox connection status with proper OAuth scopes
- **File Management**: Display of Dropbox files with accurate sync status indicators
- **Individual Import**: UI for importing specific comics with individual import buttons and loading states
- **Smart Status Detection**: Accurately shows which files have been imported to prevent duplicates
- **Dashboard Integration**: Dedicated "Dropbox" tab in the main dashboard for synced comics

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

- **tokenHash**: SHA-256 of the plaintext. The plaintext exists once, in the
  email, and is returned to the owner once at creation so they can pass the link
  on themselves. Nothing can reconstruct it afterwards
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
1. The owner enters a recipient email in `ShareComicModal` and ticks
   **I understand** against the responsibility notice — unticked every time the
   modal opens, and required before **Send invitation** is live
2. `POST /api/shares/comics/{comicId}/invitations` creates or reopens the
   `ComicShare`, mints a token and emails the link — all one unit of work, so a
   send that fails rolls the invitation back rather than showing the owner a
   recipient who was never contacted
3. The link is `{appUrl}/share/invitation/{token}` and is returned once in
   the response for the owner to copy

Sends and resends are limited per owner by the `share_invitation` rate limiter
(`config/packages/rate_limiter.yaml`, sliding window, 10 per hour). The
framework's limiter is lock-backed, so the check and the record are one atomic
step — counting recent invitations and then creating another is a read followed
by a write, and concurrent requests can all read the same figure and all decide
they are under the limit. The allowance is claimed immediately before an
invitation is issued, so a request rejected as a duplicate, by permissions, or
by validation does not spend it.

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
   normal collection. From then on the link refuses every use — preview, accept,
   decline and age confirmation alike

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

#### The two acknowledgements

Both live on the same `ComicShare` row, so one record is the whole audit trail
for one sharing relationship. Neither timestamp is ever read from the request —
an audit trail the audited party can write is not one.

| Field | Written when | Required |
|---|---|---|
| `senderResponsibilityAcceptedAt` | the invitation is created | every new share |
| `adultConfirmedAt` | the recipient declares they are 18+ | explicit comics only |

`POST /api/shares/comics/{comicId}/invitations` rejects a body whose
`senderResponsibilityAccepted` is not literally `true`, with
`400 share_responsibility_acknowledgement_required`. Resending preserves both
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

#### Dropbox Synced Comics
- Stored in user-specific Dropbox subdirectories: `/uploads/comics/{user_id}/dropbox/{filename}`
- Original filenames are preserved from Dropbox
- Tagged with "Dropbox" for easy identification and filtering
- One-way sync: files are downloaded from Dropbox to server

#### Public Cover Images
- Removed. Sharing no longer copies a cover into a world-readable directory;
  covers are served from the authorised cover endpoint, which asks the voter
  whether this viewer may see the comic at all
- **Theme Persistence**: Switched from `localStorage` to client-side cookies for storing theme preferences (light/dark mode), managed by `ThemeProvider.jsx` and a new utility module `frontend/src/lib/cookies.js`. Includes a migration step from `localStorage`.
- **Authentication Hook (`use-auth.jsx`)**: The `checkAuth` function updated to use `/api/users/me` for fetching comprehensive authenticated user details, including roles. The hook also includes a `refreshSession` method that forces an immediate session check.
- **Session Management**: Consolidated session management to use a single endpoint (`/api/users/me`) for both session validation and session keep-alive functionality. The endpoint accepts both GET (for session checks) and POST (for explicit session refreshing) methods.
- **Cookie Utility (`frontend/src/lib/cookies.js`)**: New module added with helper functions for managing browser cookies.

## Dropbox Integration System

### Overview

The Dropbox integration provides seamless synchronization of CBZ comic files from users' Dropbox accounts to the server. This is implemented as a one-way sync (Dropbox → Server) to allow users to easily share comics after they're synced.

### Configuration

#### Environment Variables

The Dropbox integration is fully configurable via environment variables in `backend/.env`:

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
# For app-scoped Dropbox apps, this should be set to "/" (root of the app's scope)
# Users must create the "Applications/StarbugStoneComics" folder in their Dropbox
# but from the app's perspective, this folder becomes the root ("/")
DROPBOX_APP_FOLDER=/

# Dropbox Sync Configuration
# Maximum number of files to sync per user per sync operation (prevents overload)
DROPBOX_SYNC_LIMIT=10

# Dropbox Rate Limiting (requests per minute to prevent API limits)
DROPBOX_RATE_LIMIT=60
```

#### Services Configuration

The configuration is injected via `config/services.yaml`:

```yaml
parameters:
    dropbox_app_folder: '%env(DROPBOX_APP_FOLDER)%'
    dropbox_sync_limit: '%env(int:DROPBOX_SYNC_LIMIT)%'
    dropbox_rate_limit: '%env(int:DROPBOX_RATE_LIMIT)%'

services:
    App\Controller\DropboxController:
        arguments:
            $dropboxAppKey: '%env(DROPBOX_APP_KEY)%'
            $dropboxAppSecret: '%env(DROPBOX_APP_SECRET)%'
            $dropboxRedirectUri: '%env(DROPBOX_REDIRECT_URI)%'
            $dropboxSyncLimit: '%dropbox_sync_limit%'

    App\Command\DropboxSyncCommand:
        arguments:
            $defaultSyncLimit: '%dropbox_sync_limit%'
```

#### Dropbox App Setup Requirements

**Required Permissions:**
- `files.metadata.read` - Required for listing files and folders
- `files.content.read` - Required for downloading CBZ files
- `files.content.write` - Optional, for future upload features

**App Configuration:**
- **Access Type**: "App folder" (recommended) or "Full Dropbox"
- **Redirect URI**: Must match `DROPBOX_REDIRECT_URI` exactly
- **App Folder Name**: Should match the folder name in `DROPBOX_APP_FOLDER`

#### Configuration Benefits

- **Environment-Specific**: Different settings for dev/staging/prod
- **Centralized**: Single source of truth for all Dropbox settings
- **Type-Safe**: Integer casting for numeric values
- **Maintainable**: Change once, affects all components
- **Secure**: Credentials stored in environment variables, not code

### Integration Workflow

#### Connection Process
1. User clicks "Connect to Dropbox" in the Dropbox Sync page
2. System redirects to Dropbox OAuth authorization
3. User authorizes the application
4. Dropbox redirects back with authorization code
5. System exchanges code for access and refresh tokens
6. Tokens are stored in the user's database record

#### Sync Process
1. **Manual Sync**: Users can trigger sync from the Dropbox Sync page
2. **Automatic Sync**: Background command can be scheduled via cron
3. **File Discovery**: Recursively scans CBZ files in user's Dropbox app folder and all subfolders
4. **Tag Generation**: Automatically creates tags from folder structure using intelligent naming conversion
5. **Duplicate Check**: Compares with existing comics to avoid duplicates
6. **Download**: Downloads new CBZ files to user's dropbox subdirectory
7. **Import**: Creates comic entries with "Dropbox" tag plus folder-based tags and metadata

#### File Organization
```
uploads/comics/
├── {user_id}/
│   ├── dropbox/           # Dropbox synced files
│   │   ├── comic1.cbz
│   │   └── comic2.cbz
│   ├── regular_upload.cbz # Manual uploads
│   └── covers/            # Cover images
└── covers/                # Global covers directory
```

### API Endpoints

- `GET /api/dropbox/connect` - Initiate OAuth flow
- `GET /api/dropbox/callback` - Handle OAuth callback
- `GET /api/dropbox/status` - Check connection status and user info
- `POST /api/dropbox/disconnect` - Remove Dropbox connection
- `GET /api/dropbox/files` - List CBZ files in Dropbox with sync status
- `POST /api/dropbox/sync` - Trigger manual sync

### Background Sync Command

The `app:dropbox-sync` command provides automated syncing capabilities with configurable defaults:

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
- **`DROPBOX_APP_FOLDER`**: Should be "/" for app-scoped Dropbox apps (users create Applications/StarbugStoneComics folder)
- **`DROPBOX_RATE_LIMIT`**: API rate limiting (default: 60 requests per minute)

#### Rate Limiting & Performance

- **Configurable Limits**: Prevents server overload during automated syncing
- **Per-User Limits**: Each user is limited to the configured number of files per sync
- **API Rate Limiting**: Respects Dropbox API limits to prevent throttling
- **Memory Efficient**: Processes files one at a time to minimize memory usage

#### Cron Integration

Can be scheduled to run automatically with various strategies:

```bash
# Sync with environment default limit (10 files per user)
0 0 * * * cd /path/to/project && php bin/console app:dropbox-sync

# Sync with custom limit
0 0 * * * cd /path/to/project && php bin/console app:dropbox-sync --limit=5

# Sync every 6 hours with rate limiting
0 */6 * * * cd /path/to/project && php bin/console app:dropbox-sync --limit=3

# Sync specific high-priority user more frequently
*/30 * * * * cd /path/to/project && php bin/console app:dropbox-sync --user-id=1 --limit=1
```

### Frontend Integration

- **Dropbox Sync Page**: Complete management interface at `/dropbox-sync`
- **Connection Status**: Real-time status detection and user info display
- **File Listing**: Shows Dropbox files with sync status indicators
- **Manual Sync**: One-click sync with progress feedback
- **Dashboard Integration**: Dedicated "Dropbox" tab for synced comics
- **Navigation**: Header includes link to Dropbox sync page

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
(users create Applications/StarbugStoneComics folder in their Dropbox)

Applications/StarbugStoneComics/
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

With custom DROPBOX_APP_FOLDER=/Applications/MyComics:

Applications/MyComics/
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
- Enable: `files.metadata.read`, `files.content.read`, `files.content.write`

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
├── frontend/           # React frontend application (to be implemented)
│   ├── src/            # React source code
│   │   ├── components/ # UI components
│   │   ├── hooks/      # Custom React hooks including authentication
│   │   ├── pages/      # Page components
│   │   └── lib/        # Utility functions
│   ├── public/         # Static assets for frontend
│   └── package.json    # Frontend dependencies
├── backend/            # Symfony backend application
│   ├── src/            # Symfony source code
│   │   ├── Controller/ # API controllers
│   │   ├── Entity/     # Database entities
│   │   ├── Security/   # Authentication handlers
│   │   └── Command/    # CLI commands
│   └── ...             # Other Symfony files and folders
├── docker/             # Docker configuration files
├── docker-compose.yml  # Docker Compose configuration for all services
└── .env                # Main environment variables file
```

### Database Schema

#### User Entity
- `id`: Primary key
- `email`: User's email (unique)
- `password`: Hashed password
- `roles`: Array of user roles (ROLE_USER, ROLE_ADMIN)
- `name`: User's name (optional)
- `dropboxAccessToken`: Dropbox OAuth access token (nullable)
- `dropboxRefreshToken`: Dropbox OAuth refresh token (nullable)
- `createdAt`: Timestamp of user creation
- `updatedAt`: Timestamp of last update
- Relationships:
  - One-to-Many with Comic (owner)
  - One-to-Many with ComicReadingProgress
  - One-to-Many with Tag (creator)

#### Comic Entity
- `id`: Primary key
- `title`: Comic title
- `filePath`: Path to the CBZ file
- `coverImagePath`: Path to the cover image
- `pageCount`: Number of pages in the comic
- `description`: Comic description (optional)
- `createdAt`: Timestamp of comic creation
- `updatedAt`: Timestamp of last update
- Relationships:
  - Many-to-One with User (owner)
  - One-to-Many with ComicReadingProgress
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

#### Authentication
- `POST /api/login` - Login with email and password
- `POST /api/register` - Register a new user
- `POST /api/logout` - Logout the current user
- `GET /api/login_check` - Check if the user is authenticated
- `GET /api/users/me` - Get the current authenticated user's information, including roles. Primarily used by the frontend to check authentication status and retrieve user details.
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
- `POST /api/comics/{id}/progress` - Update reading progress for a comic

#### Tags
- `GET /api/tags` - Get all tags
- `POST /api/tags` - Create a new tag
- `PUT/PATCH /api/tags/{id}` - Update a tag
- `DELETE /api/tags/{id}` - Delete a tag

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
Cover images are stored in comic-specific directories for better organization:
```
/uploads/comics/covers/{comic_id}/{cover_image.jpg}
```

For example:
- `/uploads/comics/covers/1/cover.jpg` - Cover image for comic with ID 1
- `/uploads/comics/covers/2/cover.jpg` - Cover image for comic with ID 2

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

2. **Production Configuration** (to be implemented):
   - For production, uncomment the email routing line:
     ```yaml
     routing:
         Symfony\Component\Mailer\Messenger\SendEmailMessage: async
         Symfony\Component\Notifier\Message\ChatMessage: async
         Symfony\Component\Notifier\Message\SmsMessage: async
     ```
   - This queues emails in the database (`messenger_messages` table)
   - You must run a Messenger consumer to process the queue:
     ```bash
     # Run a consumer as a background service
     php bin/console messenger:consume async
     ```
   - In production, set up a systemd service or supervisor process to keep the consumer running
   - This approach is more resilient and prevents email sending from blocking web requests

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

# Generate sample data for testing
docker compose exec php bin/console app:generate-sample-data --force

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

## Recent Updates

### Comic Reader Caching Improvements

The comic reader component has been significantly optimized to improve performance and user experience:

1. **Data URL Caching**: Comic pages are now stored as data URLs in memory to prevent unnecessary network requests
   - Pages are converted to base64-encoded data URLs when loaded
   - This prevents the browser from making new HTTP requests for previously loaded images
   - Fallback to Image object caching if data URL conversion fails

2. **Loading State Tracking**:
   - Added a loading tracker to prevent duplicate requests for the same page
   - Each page load is tracked with a Promise to ensure we don't start multiple loads for the same page

3. **Memory Management**:
   - Cache window limited to ±5 pages around the current page
   - Pages outside this window are automatically removed from cache
   - This prevents memory issues when reading large comics

4. **Optimized Navigation**:
   - Page state is updated immediately when navigating to a cached page
   - No loading indicator shown for cached pages, creating a seamless experience
   - Priority loading queue ensures the most likely-to-be-viewed pages load first

5. **Debug Information**:
   - Added comprehensive debug panel to monitor cache state
   - Removed console logs to clean up browser console

### Frontend Improvements

#### 1. Authentication Pages
- ✅ **Login Page**: Implemented with email and password fields
- ✅ **Registration Page**: Implemented with email, password fields
- ✅ **Password Reset Flow**: Fully implemented with:
  - Forgot password request form
  - Email delivery with frontend reset links
  - Token validation
  - Password reset form
  - Success notifications and redirects
  - Security notification emails
- ✅ **Authentication State Management**: Implemented using React Context

#### 2. Comic Library Interface
- **Comic List Page**: Grid or list view of user's comics with cover images
- **Comic Details Page**: Detailed view of a comic with metadata and reading progress
- **Upload Comic Form**: Form for uploading new comics with title and tags

#### 3. Comic Reader Interface
- **Reader Page**: Page for reading comics with navigation controls
- **Page Navigation**: Controls for moving between pages
- **Reading Progress**: Automatic saving of reading progress
- **Fullscreen Mode**: Toggle for fullscreen reading

#### 4. Tag Management
- **Tag List**: Interface for viewing and managing tags
- **Add/Edit Tag Form**: Form for creating and editing tags
- **Tag Assignment**: Interface for assigning tags to comics

#### 5. User Profile
- **Profile Page**: Page for viewing and editing user profile
- ✅ **Password Change**: Implemented through password reset functionality

#### 6. Dark Mode
- **Theme Toggle**: Button for switching between light and dark themes
- **Theme Implementation**: CSS variables or Tailwind dark mode

### Backend Enhancements

#### 1. CBZ Reader Implementation
- Implement a proper CBZ reader to extract and process comic pages
- Improve cover image extraction to always use the first page
- Optimize image processing for better performance

#### 2. Search and Filtering
- Implement search functionality for comics
- Add filtering by tags, upload date, reading progress, etc.

#### 3. Performance Optimizations
- Implement caching for frequently accessed data
- Optimize database queries for better performance
- Add pagination for large collections

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
2. Start the Docker containers:
   ```sh
   docker compose up -d
   ```
3. Set up the upload directories:
   ```sh
   docker compose exec php bin/console app:setup-upload-directories
   ```
4. Create test users:
   ```sh
   docker compose exec php bin/console app:create-admin-user testadmin@example.com AdminPass123!
   docker compose exec php bin/console app:create-user testuser1@example.com UserPass123!
   ```
5. Import comics (optional):
   ```sh
   docker compose exec php bin/console app:import-comics /path/to/comics testuser1@example.com
   ```

### Frontend Development with Live Reload

The frontend React application is now served directly by a dedicated Vite development server running inside the `frontend_dev` Docker service. This provides Hot Module Replacement (HMR) for a significantly improved development experience.

**Key Details:**
*   **Access URL:** To view and interact with the live-reloading frontend, open your browser to **`http://localhost:3001`**.
*   **Live Reload / HMR:** When you make changes to files within the `./frontend/src` directory on your host machine, the Vite server inside the `frontend_dev` container will automatically detect these changes, rebuild the necessary parts of the application, and push updates to your browser, often without a full page refresh.
*   **Automatic Dependency Installation:** The `frontend_dev` service automatically runs `npm install` when it starts, ensuring all frontend dependencies are up-to-date based on `package.json` and `package-lock.json`. You generally do not need to run `npm install` manually in the `frontend` directory unless you are specifically managing dependencies before restarting the Docker services.
*   **Role of `nginx` service (Port 8080):** The original `nginx` service (accessible at `http://localhost:8080` or your `${NGINX_PORT}`) continues to be responsible for:
    *   Proxying API requests (e.g., `/api/...`) to the backend PHP service. The Vite dev server on port 3001 is configured to route its API calls to this Nginx service.
    *   Serving a static build of the frontend if you were to build it for production (e.g., via `npm run build` results). For development, port 3001 is primary.

To start developing the frontend:
1.  Ensure all Docker services are running with `docker compose up -d`.
2.  Navigate to `http://localhost:3001` in your browser.
3.  Begin editing files in the `./frontend` directory.

## Recommended Next Steps

1. **Start Frontend Implementation**:
   - Create the comic library browsing interface

2. **Implement CBZ Reader**:
   - Develop the comic reading interface
   - Implement page navigation controls
   - Connect with the backend for reading progress tracking

3. **Add Tag Management**:
   - Create the tag management interface
   - Implement tag assignment to comics
   - Add filtering by tags

4. **Implement Search and Filtering**:
   - Add search functionality
   - Implement filtering options
   - Create a user-friendly search interface

5. **Add User Profile Management**:
   - Create the profile page
   - Implement password change functionality
   - Add user preferences

6. **Implement Dark Mode**:
   - Add theme toggle
   - Implement dark mode styles
   - Save user theme preference

## Known Issues and Considerations

1. **CBZ Reader Implementation**: The current implementation extracts the first image found in the CBZ file as the cover image. A proper CBZ reader should be implemented to always use the first page as the cover.

2. **File Storage**: The current implementation stores files in the filesystem. For production, consider using a more robust storage solution like AWS S3 or similar.

3. **Authentication**: The current implementation uses stateful authentication with sessions. For a more modern approach, consider implementing JWT-based authentication.

4. **Error Handling**: The current implementation has basic error handling. More comprehensive error handling should be implemented for production.

5. **Testing**: The current implementation has minimal testing. More comprehensive testing should be added for production.

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
Update the `frontend_dev` service in `docker-compose.yml` with the following configuration:

```yaml
frontend_dev:
  image: node:${NODE_VERSION:-18}-alpine
  container_name: ${COMPOSE_PROJECT_NAME:-cbz_reader}_frontend_dev
  volumes:
    - ./frontend:/app
    - /app/node_modules
  working_dir: /app
  command: sh -c "npm install && npm run dev -- --host 0.0.0.0 --force"
  ports:
    - "3001:3000"
  networks:
    - app_network
  depends_on:
    - nginx
  environment:
    - NODE_ENV=development
    - CHOKIDAR_USEPOLLING=true
    - WATCHPACK_POLLING=true
```

Key changes:
- Added volume mount for `/app/node_modules` to prevent it from being overwritten
- Enabled file polling with `CHOKIDAR_USEPOLLING` and `WATCHPACK_POLLING` environment variables
- Added `--host 0.0.0.0` and `--force` flags to the Vite dev command

## Production Deployment

### Current Deployment Strategy

The project uses GitHub Actions for automated deployment with a focus on safety and efficiency.

#### Deployment Workflow

**File**: `.github/workflows/build-frontend.yml`

**Trigger**: Automatic deployment when Pull Requests from `develop` to `main` are merged (not on direct pushes to main)

**Process**:
1. **Frontend Build**: React app built with Vite for production
2. **Frontend Deployment**: Built assets deployed via FTP to `backend/public/`
3. **Backend Deployment**: Currently manual (see TODO below)

#### Safety Features Implemented

After experiencing a critical deployment failure that deleted user uploads and backend files, the following safety measures are now in place:

- **Safe Mode Only**: `delete: false` - Never deletes anything on the server
- **Force Upload**: `force-upload: true` - Ensures frontend assets are updated
- **No Dangerous Options**: Removed `dangerous-clean-slate` which ignores exclude patterns
- **Protected Directories**: User uploads and backend files are never touched by deployment

#### Current Limitations & Planned Improvements

**Backend Deployment TODO**: Currently requires manual SSH to production server:

```bash
cd /path/to/project
git pull origin main
composer install --no-dev --optimize-autoloader
php bin/console cache:clear --env=prod
php bin/console doctrine:migrations:migrate --no-interaction
```

**Planned SSH Automation**: The workflow includes comprehensive TODO comments for implementing SSH-based backend deployment, which would be much more efficient than FTP uploading the entire backend codebase.

#### Deployment Lessons Learned

1. **Never use `dangerous-clean-slate: true`** in production environments with mixed content
2. **Always test deployment workflows** in staging environments first
3. **Exclude patterns are ignored** by dangerous clean slate options
4. **SSH + git pull is more efficient** than FTP uploading entire codebases
5. **Separate frontend and backend deployment** strategies for better control
6. **Always maintain backups** of user uploads and critical files

#### Emergency Recovery

An emergency restore workflow (`.github/workflows/emergency-backend-restore.yml`) is available for manual triggering to restore critical backend files in case of deployment issues.

#### Required GitHub Secrets

- `FTP_SERVER`: Production server hostname
- `FTP_USERNAME`: FTP username for deployment  
- `FTP_PASSWORD`: FTP password for deployment
- `SSH_HOST`: (Future) SSH hostname for backend deployment
- `SSH_USERNAME`: (Future) SSH username
- `SSH_PASSWORD`: (Future) SSH password or private key

### Development vs Production Workflow

- **Development**: Direct commits to `develop` branch
- **Production**: Pull Requests from `develop` to `main` trigger deployment
- **No Direct Main Pushes**: Main branch reflects exact production state

## Conclusion

The CBZ Comic Reader project has a solid backend foundation with user authentication, comic management, and reading progress tracking. The deployment strategy has been hardened after learning from critical failures, prioritizing safety over convenience.

The next major steps are:
1. Implement SSH automation for backend deployment
2. Complete any remaining frontend features
3. Add comprehensive monitoring and alerting for production

By following the recommended next steps and deployment practices, you can maintain a stable and secure comic reader application.
