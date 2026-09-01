# O2Switch continuous deployment

`develop` and `main` share one validation and deployment graph in
`.github/workflows/build-frontend.yml`:

```text
frontend validation + backend validation
                  |
                  v
       develop -> staging
          main -> production
```

Pull requests only validate. A push deploys only after both validation jobs
succeed. Manual runs default to `validation-only`; selecting `staging` or
`production` still requires the workflow ref to be `develop` or `main`
respectively.

The workflow is present before the hosting account is configured, but it fails
closed: missing environment configuration stops it before the firewall is
changed, and a missing checkout identity stops it before backup or Git changes.

## Safety model

One workflow run builds the frontend and writes `deployment-commit.txt` into
the `frontend-build` artifact. Deployment requires all three identities to
match:

```text
artifact commit = server checkout commit = github.sha
```

The server transaction is ordered as follows:

1. validate the environment identity, clean checkout, artifact, SSH/Git
   connectivity, and target branch;
2. back up the database and uploads, stopping immediately on failure;
3. fetch the expected branch and prove the requested SHA is one of its commits;
4. check out that exact SHA without touching ignored runtime configuration,
   uploads, or the host-owned `.htaccess`;
5. install the prebuilt frontend, then production Composer dependencies;
6. boot Symfony with `APP_ENV=prod` and `APP_DEBUG=0`, check the database, run
   migrations and data upgrades, and clear/warm the cache;
7. verify the final Git SHA, frontend entry point, hashed asset, and public HTTP
   response.

The GitHub `APP_URL` must exactly match the server-held effective runtime value.
The shipped backup helper follows Symfony's configuration precedence too: a
compiled dotenv is authoritative, otherwise `.env.prod.local` overrides
`.env.local`. This prevents the backup gate from dumping a different database
than the application will migrate.

The frontend install never reads or changes `public/uploads`. It switches
`index.html` last and keeps the immediately previous build's hashed files under
their original `/assets/...` URLs. GitHub does not run Node on O2Switch.

Push workflows are serialized per branch and are not cancelled when superseded.
GitHub may replace an older *pending* run with the newest pending run, but it
does not stop a transaction that may already be backing up or migrating. Both
the runner and server compare the validated SHA with the current deployment-
branch head; a rerun that has already been superseded fails before backup or
live-checkout mutation instead of rolling the environment backward.

The workflow checks O2Switch's whitelist before adding the runner. If that IP
already exists, deployment stops rather than claiming ownership of an operator
entry. Once the workflow owns an exception, cleanup always attempts both the
`in` and `out` removal calls for that exact address. It never performs a global
whitelist cleanup.

## GitHub Environments

Create two GitHub Environments named exactly `staging` and `production`.
Production must not require routine approval if `main` is expected to deploy
automatically; branch/ruleset protection is the approval gate.

Restrict each Environment's deployment branches as a second, host-side policy:
allow only `develop` for `staging` and only `main` for `production`. The workflow
also refuses any other ref before starting the deployment job, so a manual run
from a feature branch cannot receive either Environment's secrets.

Add these encrypted secrets to both environments, using a dedicated deployment
identity rather than a developer's credentials:

| Secret | Purpose |
|---|---|
| `CPANEL_SERVER` | cPanel hostname, without a scheme or port |
| `CPANEL_USERNAME` | cPanel account name |
| `CPANEL_API_TOKEN` | dedicated cPanel API token |
| `O2_SSH_PRIVATE_KEY` | dedicated GitHub-to-O2Switch private key |
| `O2_SSH_KNOWN_HOSTS` | reviewed O2Switch host-key line; do not generate it trust-on-first-use in CI |
| `O2_SSH_HOST` | SSH hostname |
| `O2_SSH_USER` | least-privileged deployment account |
| `O2_POST_DEPLOY_HOOK` | optional hook; normally empty |

Only `staging` also needs `STAGING_BASIC_AUTH_USERNAME` and
`STAGING_BASIC_AUTH_PASSWORD`. Use a dedicated smoke-test Directory Privacy
account, not a Panel Page Flip login.

Add these environment variables:

| Variable | Required value |
|---|---|
| `APP_URL` | `https://dev.comics.starbugstone.com` for staging; `https://comics.starbugstone.com` for production |
| `O2_REMOTE_PATH` | absolute path to that environment's separate checkout |
| `O2_BACKUP_COMMAND` | absolute path to a backup executable; defaults to the checkout's `scripts/server/backup-comics.sh` when empty |
| `O2_SSH_PORT` | normally `22` |
| `O2_WEB_USER` / `O2_WEB_GROUP` | O2Switch account/runtime owner; defaults to the SSH user |

Do not place `DATABASE_URL`, `APP_SECRET`, `APP_DATA_KEY`, mail credentials,
Dropbox credentials, or other application runtime secrets in GitHub. They stay
only in the appropriate O2Switch checkout's ignored `backend/.env.local` or
`backend/.env.prod.local`.

## One-time O2Switch checklist

Complete and verify staging before configuring production automation.

### API and SSH

- Create a dedicated token in cPanel **Manage API Tokens** and store it
  immediately. Test `SshWhitelist/list` with the token and `status == 1`.
- Verify token authentication while cPanel 2FA remains enabled. If it fails,
  stop and ask O2Switch for the supported account configuration; do not disable
  2FA as a shortcut.
- Generate a dedicated SSH keypair. Authorize only its public key on O2Switch;
  put only its private key in GitHub.
- Obtain the SSH host key through an authenticated/reviewed channel and store
  the complete known-hosts line in `O2_SSH_KNOWN_HOSTS`.
- Verify both O2Switch checkouts can fetch this GitHub repository without a
  prompt. A private repository should use a separate read-only GitHub deploy
  key from O2Switch to GitHub.

### Separate checkouts and identity

Create non-overlapping application directories, for example:

```text
/home/ACCOUNT/apps/panel-page-flip-production
/home/ACCOUNT/apps/panel-page-flip-staging
```

In each checkout create the host-owned identity marker once:

```bash
printf 'production\n' > .panel-page-flip-environment  # production checkout only
printf 'staging\n' > .panel-page-flip-environment     # staging checkout only
chmod 600 .panel-page-flip-environment
```

The transaction compares this marker with the selected GitHub Environment
before it takes a backup. A crossed `O2_REMOTE_PATH` therefore fails rather
than deploying staging over production or production over staging.

The public `.htaccess` is also host-owned because cPanel Directory Privacy adds
authentication directives to it. On a new checkout, copy
`scripts/deploy/htaccess.dist` to `backend/public/.htaccess` *before* enabling
Directory Privacy. On an existing checkout, merge/reapply the shipped routing
and security rules without deleting cPanel's authentication block. Automated
deployments preserve this file across exact-SHA checkouts.

### Staging isolation

- Create a staging-only database and preferably a database user that has no
  privileges on production.
- Give staging its own dotenv file, `APP_SECRET`, `APP_DATA_KEY`, uploads,
  `backend/var`, logs, cache, URL/CORS configuration, and third-party test
  credentials. Never symlink writable staging paths to production.
- Set `APP_ENV=prod`, `APP_DEBUG=0`, `ADSENSE_ENABLED=false`, and
  `STAGING_ISOLATION_CONFIRMED=1`.
- Prefer `MAILER_DSN=null://null`. A reviewed sink/test mailbox instead requires
  `STAGING_MAIL_SAFETY_CONFIRMED=1`; this flag records the explicit review and
  is not permission to reuse production mail credentials.
- Keep production webhooks and tokens out of staging unless a specific test has
  been reviewed. Never copy unsanitized production personal data into staging.

### Staging access control

- Point `dev.comics.starbugstone.com` at the staging checkout's
  `backend/public`, issue HTTPS, then protect that whole directory with cPanel
  **Directory Privacy**.
- Create individual developer accounts where practical and a dedicated smoke
  account for GitHub.
- Confirm anonymous requests to `/` and `/api/config` both receive `401` before
  the application runs. Check login, registration, assets, reader and admin
  routes as well.
- Confirm an authenticated request can load the application. Deployment also
  replaces staging `robots.txt` with `Disallow: /` and changes the HTML robots
  metadata to `noindex, nofollow, noarchive`; these are defence in depth, not
  access control.

### Backup and initial deployment

- Configure an executable that atomically fails if either the database dump or
  uploads backup fails. Verify its storage is outside the web root, has enough
  capacity, and can restore both stores together with the matching
  `APP_DATA_KEY`.
- Run the backup command manually for each environment and inspect its output.
- Deploy staging manually once, run migrations, and test login, admin, upload,
  reader, email isolation, advertising disablement and third-party isolation.
- Run the workflow on `develop` with `validation-only`, then merge a harmless
  tested change to prove automatic staging deployment and firewall cleanup.
- Configure production only after staging has completed this exercise. Take
  and verify a fresh production backup before enabling its secrets.

## Normal operation and failures

No cPanel/GitWeb action is needed after setup. A successful push to `develop`
deploys staging; a successful push to `main` deploys production. A validation,
whitelist, backup, artifact, Composer, migration, cache or smoke failure leaves
the workflow red. Firewall cleanup failure also leaves it red so an operator can
remove the exact stale runner IP manually.

The SHA-specific temporary artifact directory is outside the live checkout and
is removed on success or failure. If SSH connectivity is lost before cleanup,
the log prints its exact path for manual removal.

## Rollback and emergency fallback

Prefer `git revert` on the deployment branch. The revert is validated, produces
its own matching frontend artifact, takes a new backup, and follows the normal
serialized deployment path. This restores known-good code without pretending
that a database migration can safely be reversed.

Before any rollback, review every migration introduced by the bad release.
Restore a database/uploads backup only as an explicit recovery operation with a
maintenance window and the matching `APP_DATA_KEY`; the deployment scripts do
not perform automatic database rollback.

If GitHub Actions is unavailable, `scripts/deploy-ssh.sh` remains the emergency
manual path. It uploads the same current server transaction helper, takes the
backup before fetching Git, and then runs the same Composer, migration, asset
and cache steps. `--branch=...` is an explicit operator override and should not
be used as a routine production release mechanism.

## References

- [O2Switch SSH whitelist and CI API](https://faq.o2switch.fr/cpanel/outils/exception-parefeu/)
- [O2Switch cPanel API tokens](https://faq.o2switch.fr/cpanel/securite/token-api-cpanel/)
- [O2Switch Directory Privacy](https://faq.o2switch.fr/cpanel/fichiers/protection-repertoire-web/)
- [Manual SSH deployment](../SSH-deploy.md)
