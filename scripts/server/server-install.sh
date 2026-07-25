#!/usr/bin/env bash
# =============================================================================
# scripts/server/server-install.sh
# -----------------------------------------------------------------------------
# Runs ON the production server, ONCE, to set up a fresh checkout.
# Use this only for the very first deploy. Subsequent deploys use server-deploy.sh.
#
# What it does:
#   1. Verifies prerequisites (php >=8.2, composer, node, git, mysql client)
#   2. Creates the app directory tree
#   3. Bootstraps backend/.env.prod.local from a template if missing
#   4. Calls server-deploy.sh to do the actual build
#   5. Prints next steps (nginx/apache config, certbot, first admin user)
#
# Usage on the server:
#   sudo mkdir -p /var/www/comics && sudo chown $USER:$USER /var/www/comics
#   git clone git@github.com:youruser/yourrepo.git /var/www/comics
#   cd /var/www/comics
#   APP_DIR=/var/www/comics ./scripts/server/server-install.sh
# =============================================================================

set -euo pipefail

: "${APP_DIR:?APP_DIR must be set (e.g. /var/www/comics)}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-$WEB_USER}"

log()  { printf "\033[1;36m[install]\033[0m %s\n" "$*"; }
warn() { printf "\033[1;33m[warn]\033[0m    %s\n" "$*"; }
fail() { printf "\033[1;31m[fail]\033[0m    %s\n" "$*" >&2; exit 1; }

# =============================================================================
# Preflight
# =============================================================================
log "Checking prerequisites"

require_bin() {
    command -v "$1" >/dev/null 2>&1 || fail "Missing '$1'. Install it first."
}

require_bin git
require_bin php
require_bin composer

PHP_MAJ_MIN=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
case "$PHP_MAJ_MIN" in
    8.2|8.3|8.4) log "PHP $PHP_MAJ_MIN detected" ;;
    *) fail "PHP 8.2+ required, found $PHP_MAJ_MIN" ;;
esac

# Required PHP extensions for Symfony 6.4 + this project.
for ext in pdo_mysql intl mbstring zip xsl gd opcache; do
    php -m | grep -qi "^${ext}$" || fail "PHP extension '$ext' not loaded."
done

if ! command -v node >/dev/null 2>&1; then
    warn "Node.js not found. You'll need to either install it on the server"
    warn "or build the frontend locally and 'rsync' the dist into backend/public/."
fi

# =============================================================================
# Filesystem layout
# =============================================================================
[ -d "$APP_DIR/backend" ] || fail "$APP_DIR/backend missing — clone the repo first."

cd "$APP_DIR"

mkdir -p backend/var/cache backend/var/log
mkdir -p backend/public/uploads

# =============================================================================
# Bootstrap .env.prod.local
# =============================================================================
ENV_FILE="$APP_DIR/backend/.env.prod.local"
if [ ! -f "$ENV_FILE" ] && [ ! -f "$APP_DIR/backend/.env.local.php" ]; then
    log "Creating $ENV_FILE template — EDIT IT BEFORE CONTINUING"
    cat > "$ENV_FILE" <<'EOF'
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=REPLACE_WITH_openssl_rand_-hex_32

DATABASE_URL=mysql://USER:PASSWORD@127.0.0.1:3306/cbz_reader?serverVersion=8.0&charset=utf8mb4

CORS_ALLOW_ORIGIN=^https://comics\.yourdomain\.com$
FRONTEND_URL=https://comics.yourdomain.com
FRONTEND_SCHEME=https
FRONTEND_HOST=comics.yourdomain.com
FRONTEND_PORT=443

MAILER_DSN=smtp://user:pass@smtp.yourdomain.com:587
MAILER_TRANSPORT=smtp
MAILER_FROM_ADDRESS=noreply@yourdomain.com
MAILER_FROM_NAME="Comic Reader"

MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0
MAX_CONCURRENT_UPLOADS=3

DROPBOX_APP_KEY=
DROPBOX_APP_SECRET=
DROPBOX_REDIRECT_URI=https://comics.yourdomain.com/api/dropbox/callback
DROPBOX_APP_FOLDER=/
DROPBOX_SYNC_LIMIT=10
DROPBOX_RATE_LIMIT=60

# Token used by /_post-deploy.php (only relevant if you ALSO expose that endpoint).
DEPLOY_TOKEN=REPLACE_WITH_openssl_rand_-hex_32
EOF
    chmod 600 "$ENV_FILE"
    cat <<MSG

==============================================================================
Stop. Edit the env file now:

   $ENV_FILE

Then re-run this installer:

   APP_DIR=$APP_DIR ./scripts/server/server-install.sh

==============================================================================
MSG
    exit 0
fi

# =============================================================================
# First build
# =============================================================================
log "Running first build via server-deploy.sh"
APP_DIR="$APP_DIR" \
WEB_USER="$WEB_USER" \
WEB_GROUP="$WEB_GROUP" \
"$APP_DIR/scripts/server/server-deploy.sh"

# =============================================================================
# Next steps
# =============================================================================
cat <<NEXT

==============================================================================
First-time install complete.

Next steps (do them once):

1. Web server config
   - Nginx example: see SSH-deploy.md section 6.
   - Apache: drop scripts/deploy/htaccess.dist into backend/public/.htaccess.
   - Document root must be: $APP_DIR/backend/public

2. SSL certificate
   - sudo certbot --nginx -d comics.yourdomain.com   (or --apache)

3. Create the first admin user
   cd $APP_DIR/backend
   php bin/console app:create-admin-user --env=prod
   (or use the seeder: php bin/console doctrine:fixtures:load --append --env=prod)

4. (Optional) Daily Dropbox sync — install a systemd timer or cron job:
   0 */2 * * * cd $APP_DIR/backend && php bin/console app:sync-dropbox-comics --env=prod >/var/log/comics-dropbox.log 2>&1

5. From your laptop, set up scripts/.env.deploy and from now on deploy with:
   ./scripts/deploy-ssh.sh

==============================================================================
NEXT
