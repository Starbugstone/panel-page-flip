#!/usr/bin/env bash
# Bring up the full stack and seed it so a browser driver has something to drive.
# Idempotent: safe to re-run after a rebuild.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$REPO_ROOT"

# The Docker credential helper is broken under WSL here; an empty config skips it.
export DOCKER_CONFIG="${DOCKER_CONFIG:-/tmp/ppf-dockercfg}"
export DOCKER_BUILDKIT=0 COMPOSE_DOCKER_CLI_BUILD=0
mkdir -p "$DOCKER_CONFIG"
[ -f "$DOCKER_CONFIG/config.json" ] || printf '{}' > "$DOCKER_CONFIG/config.json"

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

# Without .env this checkout would fall back to Compose's directory-derived
# project name and the default ports, which is how two checkouts end up sharing
# containers. dev-env.sh is idempotent, so running it here costs nothing.
[ -f "$REPO_ROOT/.env" ] || "$REPO_ROOT/scripts/dev-env.sh"

say "Starting containers"
docker compose up -d --build database php nginx mailpit

say "Waiting for MySQL"
for _ in $(seq 1 60); do
  if docker compose exec -T database sh -lc 'mysqladmin ping -h 127.0.0.1 -uroot -p"$MYSQL_ROOT_PASSWORD" --silent' >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

say "Installing backend dependencies"
docker compose exec -T php composer install --no-interaction --no-progress >/dev/null

say "Applying migrations"
# --allow-no-migration so a database already at the latest version is not an error.
docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration 2>&1 | tail -2

say "Creating upload directories"
docker compose exec -T php php bin/console app:setup-upload-directories >/dev/null 2>&1 || true
# Console commands and PHP-FPM are the same user now (the host developer's UID,
# see docker-compose.yml), so the staging directory a console command creates is
# already writable by the request that uses it. setup.sh creates them on boot;
# this only covers a directory an upgrade added since the container started.
docker compose exec -T php sh -lc '
  mkdir -p /tmp/comic_uploads public/uploads var/page-cache
  chmod -R u+rwX,g+rwX /tmp/comic_uploads public/uploads var/page-cache
'

say "Creating test accounts"
docker compose exec -T php php bin/console app:create-user navtest@example.com 'NavTest123!' >/dev/null 2>&1 || true
docker compose exec -T php php bin/console app:create-admin-user navadmin@example.com 'NavAdmin123!' >/dev/null 2>&1 || true
# Unverified accounts cannot log in, and there is no console command for it.
docker compose exec -T database sh -lc \
  'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "UPDATE ${MYSQL_DATABASE}.user SET is_email_verified=1 WHERE email LIKE \"nav%\";"' 2>/dev/null

# Repeated driver runs trip the login limiter (5 attempts / 15 minutes), which
# surfaces as a 429 and a login that never completes. Clearing it costs nothing
# and is not something to sit out for a quarter of an hour.
say "Clearing the login rate limiter"
docker compose exec -T php php bin/console cache:pool:clear cache.rate_limiter >/dev/null 2>&1 || true

say "Generating fixtures"
docker compose cp "$REPO_ROOT/.claude/skills/browser-test/scripts/make-fixtures.php" php:/tmp/make-fixtures.php >/dev/null
docker compose exec -T php php /tmp/make-fixtures.php
mkdir -p "$REPO_ROOT/var/browser-test/fixtures"
docker compose cp php:/tmp/fixtures/. "$REPO_ROOT/var/browser-test/fixtures/" >/dev/null

# Ports are per checkout (scripts/dev-env.sh), so read them back rather than
# printing the numbers the main repo happens to use.
port() { grep -m1 "^$1=" "$REPO_ROOT/.env" 2>/dev/null | cut -d= -f2- || true; }

cat <<EOF

Ready.

  App        http://localhost:$(port NGINX_PORT)
  Mailpit    http://localhost:$(port MAILPIT_UI_PORT)
  Adminer    http://localhost:$(port ADMINER_PORT)

  user       navtest@example.com  / NavTest123!
  admin      navadmin@example.com / NavAdmin123!

  Drivers that read credentials from the environment (bulk-upload.mjs,
  reader-mat-and-spread.mjs) need them exported first:

    export PPF_USER_EMAIL=navtest@example.com PPF_USER_PASSWORD='<the password above>'

  fixtures   var/browser-test/fixtures/

Drive it with:
  .claude/skills/browser-test/scripts/drive.sh .claude/skills/browser-test/scripts/drive.mjs
EOF
