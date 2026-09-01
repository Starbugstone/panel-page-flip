# Panel Page Flip

Supported comic sources are CBZ, CBR, CB7, CBT, and PDF. Self-hosters should see [comic format runtime requirements](docs/comic-formats.md).

Panel Page Flip is a self-hosted web application for managing and reading CBZ, CBR, CB7, CBT, and PDF comic collections. It combines a responsive comic reader with per-user libraries, reading progress, sharing, bulk uploads, tagging, Dropbox imports, and administrative tools.

**Live site:** [comics.starbugstone.com](https://comics.starbugstone.com/)  
**Issues:** [GitHub issue tracker](https://github.com/Starbugstone/panel-page-flip/issues)

## Features

- Secure session-based authentication with email verification and password recovery
- Private, per-user comic libraries with grid and table views
- Protected comic-page streaming across supported source formats, fullscreen reading, keyboard navigation, and saved progress
- Single and bulk chunked uploads with progress reporting
- Search, custom tags, bulk tagging, and recoverable file cleanup
- Private library folders that organise a collection without touching a file on disk — see [library folders](docs/library-folders.md)
- Comic sharing that grants revocable read access without copying files, with a dedicated Sharing page and re-readable `C-`/`G-` codes — see [who may reach a comic](docs/comic-access.md)
- One-way Dropbox imports with duplicate detection and folder-based tags
- Responsive light and dark themes
- Administration for users, comics, shares, tags, Dropbox connections, cleanup, and audit history
- Administrator notices warning one account about their activity, a comic, or something they shared, shown on their next visit and optionally emailed — see [administrator notices](docs/administrator-notices.md)
- Per-user storage usage against the enforced quota, visible to each account in its library sidebar and settings page as well as in the admin user list — see [storage accounting and the per-user quota](docs/storage-quota.md)
- Optional Google AdSense, off unless an operator turns it on, confined by an allowlist to pages that render no uploaded comic content, with consent and the bulk-upload Offerwall owned by Google's supported account-side products — see [advertising, consent, and AdSense Offerwall](docs/advertising.md)
- Optional Google Analytics 4, off unless an operator turns it on, blocked behind Google Privacy & Messaging basic consent mode and restricted to sanitized application-owned route categories — see [privacy-first Google Analytics](docs/analytics.md)
- Optional Google social sign-in with safe account linking, passwordless onboarding, and provider-neutral identity storage — see [social sign-in setup and security model](docs/social-sign-in.md)

## Technology

| Layer | Stack |
| --- | --- |
| Frontend | React 19, Vite 8, React Router 7, TanStack Query, Tailwind CSS, Radix UI |
| Backend | PHP 8.2, Symfony 6.4, Doctrine ORM |
| Data | MySQL 8 and filesystem-backed canonical comic-source storage |
| Development | Docker Compose, Nginx, PHP-FPM, Mailpit, Adminer |
| Testing | Vitest, PHPUnit, Symfony functional tests |

Nginx serves the production React build and forwards `/api` requests to Symfony. The backend owns authentication, authorization, metadata, reading progress, archive processing, integrations, and file access. Uploaded comics remain outside the frontend build in `backend/public/uploads/`.

## Local development

### Requirements

- Git
- Docker with Docker Compose

Node.js 22 and PHP/Composer are only required when running tooling outside Docker.

### Start the application

```bash
git clone https://github.com/Starbugstone/panel-page-flip.git
cd panel-page-flip
docker compose up -d --build
docker compose exec php composer install
docker compose exec php php bin/console doctrine:database:create --if-not-exists
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console app:setup-upload-directories
```

Create an administrator for the first login:

```bash
docker compose exec php php bin/console app:create-admin-user admin@example.com 'ChangeMe-Strong-Password-123!'
```

The email and password are required positional arguments. Command-created users are marked as email-verified.

Development services:

| Service | URL |
| --- | --- |
| Vite frontend with hot reload | <http://localhost:3001> |
| Nginx production-style frontend and API | <http://localhost:8080> |
| Adminer | <http://localhost:8081> |
| Mailpit | <http://localhost:8025> |

The Vite server proxies `/api` to Nginx. Frontend changes are hot-reloaded; Symfony source is bind-mounted into the PHP container.

Stop the environment with:

```bash
docker compose down
```

Add `-v` only when you intentionally want to delete the local MySQL volume.

### Local configuration

Docker service names, versions, ports, development database credentials, and the public `APP_URL` are configured in the root `.env`. Docker passes that same `APP_URL` to Symfony, the Vite/SEO build, and the nginx image build, so it is changed in one place.

Use `backend/.env.local` for machine-specific values and secrets:

```dotenv
APP_SECRET=replace-with-a-random-value
APP_DATA_KEY=replace-with-a-persistent-random-value
DATABASE_URL="mysql://cbz_user:cbz_password@database:3306/cbz_reader?serverVersion=8.0&charset=utf8mb4"
MAILER_DSN=smtp://mailpit:1025
```

Only when running Symfony directly outside Docker, override `APP_URL` in `backend/.env.local`; Docker development reads it from the root `.env`.

Generate suitable local secrets with `openssl rand -hex 32` for `APP_SECRET` and `openssl rand -base64 32` for `APP_DATA_KEY`.

`backend/.env.example` documents every variable the application reads, with example values throughout. Use it as the reference when filling in `backend/.env.local` for development or production; deployments preserve this ignored host-local file.

Important configuration variables:

- `APP_SECRET` — Symfony application secret
- `APP_DATA_KEY` — encrypts persisted integration credentials; do not rotate it without migrating existing data
- `DATABASE_URL` — Doctrine connection string
- `APP_URL` — the one public same-origin URL used in email/OAuth links and generated SEO metadata
- `CORS_ALLOW_ORIGIN` — allowed browser origins
- `MAILER_DSN`, `MAILER_FROM_ADDRESS`, `MAILER_FROM_NAME` — email delivery
- `PRIVACY_OPERATOR`, `PRIVACY_EMAIL` — public data-controller name and privacy contact
- `MAX_CONCURRENT_UPLOADS` — concurrent upload HTTP requests shared across a batch; keep below the PHP-FPM worker count (the Docker default is 4 requests for 5 workers)
- `MAX_PARALLEL_FILE_UPLOADS` — how many comics a bulk upload sends at once (2 when omitted). Those files share the budget above, so this decides how many comics move together, not how much load reaches PHP-FPM
- `UPLOAD_USER_QUOTA_BYTES` — default canonical comic storage per account in bytes (10 GiB when omitted); administrators can override it per user, and `0` deliberately means unlimited
- `DROPBOX_APP_KEY`, `DROPBOX_APP_SECRET`, `DROPBOX_REDIRECT_URI` — optional Dropbox OAuth settings
- `OAUTH_GOOGLE_CLIENT_ID`, `OAUTH_GOOGLE_CLIENT_SECRET` — optional Google social sign-in; both empty disables it. Register the exact `APP_URL` callback described in [`docs/social-sign-in.md`](docs/social-sign-in.md)
- `DROPBOX_APP_FOLDER`, `DROPBOX_SYNC_LIMIT`, `DROPBOX_RATE_LIMIT` — optional Dropbox import settings
- `ADSENSE_ENABLED`, `ADSENSE_CLIENT` — optional Google AdSense, off by default.
  Advertising runs only when both are set and the client is a publisher id in
  Google's form (`ca-pub-` and sixteen digits); anything else logs a warning and
  leaves it off. There is deliberately no third setting — ad formats, page
  exclusions and the Offerwall live in the AdSense account, not here. Enabling
  advertising-enabled HTML automatically receives Google's supported strict,
  nonce-based Content-Security-Policy. Account configuration and verification
  are documented in [`docs/advertising.md`](docs/advertising.md).
- `GOOGLE_ANALYTICS_ENABLED`, `GOOGLE_ANALYTICS_MEASUREMENT_ID` — optional
  GA4 audience measurement, off by default. Analytics also requires a valid
  `ADSENSE_CLIENT` for Google's certified Privacy & Messaging CMP, but
  `ADSENSE_ENABLED` may stay false. No Analytics tag or measurement request is
  made until analytics consent is granted; see [`docs/analytics.md`](docs/analytics.md).
- `METRON_SHARED_ENABLED` — whether this server may spend its own Metron account
  on behalf of every user. Off unless set; a user's personal Metron token is
  unaffected by it.
- `COMIC_VINE_SHARED_ENABLED` — whether this server may spend its own Comic Vine
  key. On by default, since a self-hosted library is inside Comic Vine's
  non-commercial terms; turn it off if yours stops being. An administrator can
  also switch it off from Admin → Metadata.

  Neither can be overridden from inside the application. Both govern the
  credentials the *server* owns — a user who adds their own provider token in
  their settings uses that instead, unless an administrator has turned personal
  tokens off. Comics are still described by their own `ComicInfo.xml` and
  filenames whatever these say. See
  [`docs/metadata-enrichment.md`](docs/metadata-enrichment.md).

Never commit `.env.local`, `.env.prod.local`, `.env.local.php`, `scripts/.env.deploy`, credentials, or production keys.
Before making the site public, set `PRIVACY_OPERATOR` to the operator's real
legal identity and verify that `PRIVACY_EMAIL` is a monitored address. The
generic development default is not a substitute for identifying the controller.

### Privacy retention

Run all four cleanup commands at least daily in production:

```bash
docker compose exec php php bin/console app:cleanup-personal-data
docker compose exec php php bin/console app:cleanup-expired-shares
docker compose exec php php bin/console app:cleanup-content-reports
docker compose exec php php bin/console app:cleanup-logs
```

The personal-data cleanup removes administrator audit records after 12 months,
unverified non-admin accounts after 30 days, and expired email-verification and
password-reset tokens. The share cleanup permanently removes invitations that
expired without being answered. The log cleanup deletes daily log files past
their retention period — 30 days for application logs, a year for security and
audit records — and is the only thing that does: nothing deletes them on its own.
The content-report cleanup removes closed and rejected reports past their
retention period, but never open cases or cases on legal hold.
Configure the web server separately to rotate and delete access logs after the
shortest period needed for security operations.

### Security and audit logging

Security-relevant events are written to dedicated daily files under
`backend/var/log/security/` and `backend/var/log/audit/`, and serious ones can
email administrators.

```dotenv
SECURITY_ALERTS_ENABLED=0
SECURITY_ALERT_EMAILS=
APP_LOG_RETENTION_DAYS=30
SECURITY_LOG_RETENTION_DAYS=365
AUDIT_LOG_RETENTION_DAYS=365
```

Alerts are off by default and are rate-limited per event and per source when
enabled. See [docs/security-logging.md](docs/security-logging.md) for the file
layout, the retention rules, which events alert, how to silence them during
maintenance, and the rules for adding a new event — in particular, that
identifiers go in a log record and secrets, addresses and comic titles do not.

## Common commands

```bash
# Create a regular user
docker compose exec php php bin/console app:create-user user@example.com 'ChangeMe-Strong-Password-123!'

# Create an administrator
docker compose exec php php bin/console app:create-admin-user admin@example.com 'ChangeMe-Strong-Password-123!'

# Import enabled comic source files from a directory visible inside the PHP container
docker compose exec php php bin/console app:import-comics /path/to/comics user@example.com

# Preview orphan cleanup without removing source files
docker compose exec php php bin/console app:cleanup-comics --dry-run

# Remove sharing invitations that expired unanswered, and sharing codes that
# have been dead for over a month
docker compose exec php php bin/console app:cleanup-expired-shares

# Clear the Symfony cache
docker compose exec php php bin/console cache:clear
```

`app:cleanup-comics` moves orphaned files to recoverable quarantine storage. Run its dry-run mode first.

### Scheduled maintenance

Nothing in the application schedules itself. Retention periods in `.env.local`
are **policy only** — they say how long something is kept, and a command has to
run for anything to be deleted. A production instance needs these four:

| Command | Cadence | If it never runs |
|---|---|---|
| `app:cleanup-logs` | daily | Log directories grow without limit; `*_LOG_RETENTION_DAYS` has no effect |
| `app:cleanup-personal-data` | daily | Old audit rows, spent tokens, unverified accounts and uncollected export files are kept indefinitely |
| `app:cleanup-expired-shares` | daily | Unanswered invitations keep the addresses of people who never had an account here, and dead sharing codes are never removed |
| `app:cleanup-content-reports` | daily | Closed and rejected reports past retention are kept indefinitely; open cases and cases on legal hold are never selected |

`app:dropbox-sync` is additionally needed only if the instance uses Dropbox
imports. Crontab examples, and how to check the schedule is actually firing, are
in [SSH-deploy.md §7](SSH-deploy.md#7-background-jobs-cron--systemd-timers).

## Testing and quality checks

### Frontend

Run inside the existing Node 22 development container:

```bash
docker compose exec frontend_dev npm test
docker compose exec frontend_dev npm run lint
docker compose exec frontend_dev npm run build
docker compose exec frontend_dev npm run audit:production
docker compose exec frontend_dev npm run check:routes
docker compose exec frontend_dev npm run check:seo
```

Alternatively, run `npm ci` and the same scripts from `frontend/` with a local Node.js 22 installation.

`lint` runs with `--max-warnings=0`, so a warning fails it. The `check:` scripts
guard artefacts that are committed rather than rebuilt on the way to production
— the nginx route manifest, the conversion-tool downloads and their checksums,
the generated sitemap, robots and canonical metadata, and the crawlable
landing copy inside the built `index.html`. `check:seo` reads
`APP_URL`, so run it after a build made with the same value CI uses.

**`check:tools` and `check:csp` must be run from the host, not from
`frontend_dev`:**

```bash
npm run check:tools --prefix frontend
npm run check:csp --prefix frontend
```

The container mounts only `scripts/generate-nginx-routes.mjs`, never the whole
`scripts/` directory, because that directory can hold `scripts/.env.deploy` with
production credentials. `check:tools` needs `scripts/comic-conversion/`, and
`check:csp` needs `scripts/generate-csp.mjs`, so both must run on the host.
Inside the container `check:tools` reports a missing source file — and
`src/lib/conversion-tools.test.js`, which shells out to the same check, fails
there for the same reason — while `check:csp` reports its generator missing.
All three pass on the host and in CI, where the whole repository is present.

### Backend

Create and migrate the isolated test database once:

```bash
docker compose exec database sh -lc \
  'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS ${MYSQL_DATABASE}_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON ${MYSQL_DATABASE}_test.* TO '\''${MYSQL_USER}'\''@'\''%'\''; FLUSH PRIVILEGES;"'
docker compose exec php php bin/console doctrine:migrations:migrate --env=test --no-interaction
```

Then run:

```bash
docker compose exec php php bin/phpunit
docker compose exec php composer analyse
docker compose exec php composer cs:check
docker compose exec php composer validate --strict
docker compose exec php composer audit --locked --no-dev
docker compose exec php php bin/console lint:container --env=test
docker compose exec php php bin/console doctrine:schema:validate --env=test
```

`composer analyse` is PHPStan and `composer cs:check` is PHP-CS-Fixer in dry-run
mode; both are CI gates, not optional local tooling. See
[development tooling](docs/development-tooling.md) for the PHPStan baseline
policy.

### Continuous integration

`.github/workflows/build-frontend.yml` ("Validate Application") runs everything
above on pull requests into `main`, `develop`, `feature/**`, `docs/**`, `fix/**`
and `ci/**`, and on pushes to `main` and `develop`. A weekly
`security-audit.yml` re-runs the frontend and backend dependency audits on their
own schedule.

CI validates releases and deliberately does not deploy them: frontend and
backend must ship together through the backup-gated release scripts described
under [Production deployment](#production-deployment).

## Dropbox integration

Dropbox support is optional and operates as a one-way import from Dropbox into the user's server-side library.

1. Create a scoped Dropbox app, preferably with **App folder** access.
2. Enable `files.content.read`, `files.content.write`, and `account_info.read`.
3. Add the exact callback URL used by the application, for example `http://localhost:8080/api/dropbox/callback`.
4. Configure the `DROPBOX_*` variables in `backend/.env.local`.
5. Connect the account from the Dropbox page in the application.

Users can import individual files from the interface. Folder names become tags, and previously imported files are detected to avoid duplicates.

For scheduled imports:

```bash
# Preview imports for every connected user
docker compose exec php php bin/console app:dropbox-sync --dry-run

# Import up to five files per user
docker compose exec php php bin/console app:dropbox-sync --limit=5
```

Use `--user-id=<id>` to restrict a run to one user.

## Data and backups

The application has two persistent data stores:

- MySQL data, stored in the Docker `db_data` volume during local development
- Uploaded comics, covers, and related files under `backend/public/uploads/`

A usable backup must include both stores. Production backups must also preserve `APP_DATA_KEY`; encrypted Dropbox credentials cannot be recovered without it.

Before every production upgrade:

1. Verify a current database backup.
2. Verify a current `backend/public/uploads/` backup.
3. Confirm the backed-up `APP_DATA_KEY` matches production.
4. Build and deploy the frontend and backend as one release.
5. Apply Doctrine migrations and the documented data-upgrade commands.
6. Complete authenticated smoke tests.

## Production deployment

Production releases are backup-gated and intentionally separate from CI:

- [SSH deployment guide](SSH-deploy.md) — recommended for a server with SSH and Git access
- [FTP/FTPS deployment guide](deploy.md) — packaged releases for shared hosting

The release tooling builds the React application, installs optimized production Composer dependencies, preserves the server's `backend/.env.local`, and excludes user uploads from deployment. The default server-local mode does not run `composer dump-env`; compiled dotenv remains an explicit opt-in for portable releases.

Do not deploy only `frontend/dist`: frontend and backend changes may depend on each other.

Deploying with advertising or Analytics enabled has steps of its own, most of
them in the Google accounts rather than in this repository. In the default deployment mode,
set the feature values in the host's
`backend/.env.local`; they do not need to be duplicated in
`scripts/.env.deploy`. See [`docs/advertising.md`](docs/advertising.md) and
[`docs/analytics.md`](docs/analytics.md).

Google social sign-in also requires account-side setup. Configure each
installation's exact callback URI and keep its client secret in the host's
ignored `backend/.env.local`; see [`docs/social-sign-in.md`](docs/social-sign-in.md).

## Project layout

```text
.
├── backend/                 Symfony application, migrations, and tests
│   ├── config/
│   ├── migrations/
│   ├── public/
│   ├── src/
│   │   ├── Command/
│   │   ├── Controller/
│   │   ├── Entity/
│   │   ├── Repository/
│   │   ├── Security/
│   │   └── Service/
│   └── tests/
├── docker/                  PHP and Nginx development images
├── docs/                    Per-feature documentation
├── frontend/                React application and frontend tests
│   └── src/
│       ├── components/
│       ├── hooks/
│       ├── lib/
│       └── pages/
├── scripts/                 Release, deployment, backup, and server scripts
├── docker-compose.yml
├── deploy.md
└── SSH-deploy.md
```

## Security notes

- All application data and comic endpoints require an authenticated session unless explicitly public.
- Administration endpoints require `ROLE_ADMIN`; backend checks remain authoritative.
- Comic files and covers are served through ownership-aware backend endpoints.
- Uploads are validated and cleanup uses quarantine before permanent deletion.
- API protections include CSRF checks, rate limiting, password hashing, and encrypted integration credentials.
- Production must use HTTPS, unique secrets, restricted filesystem permissions, and a real SMTP service.

Report security-sensitive issues privately to the repository owner rather than opening a public issue.

## License

This project is open source under the [MIT License](LICENSE).
