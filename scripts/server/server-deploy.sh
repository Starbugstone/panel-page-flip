#!/usr/bin/env bash
# =============================================================================
# scripts/server/server-deploy.sh
# -----------------------------------------------------------------------------
# Runs ON the production server. Drives the post-pull build:
#   0. install the production public/.htaccess and remove public/error.php
#   1. composer install --no-dev --optimize-autoloader
#   2. preserve and read backend/.env.local (or use an existing compiled env)
#   3. (optional) build the React frontend if Node is available, OR
#                copy a pre-uploaded backend/public/dist if you built locally
#   4. doctrine:migrations:migrate --no-interaction
#   5. cache:clear --env=prod && cache:warmup --env=prod
#   6. fix permissions on var/cache and var/log
#   7. (optional) reload PHP-FPM via the hook command
#
# Two ways to invoke:
#   * From your laptop:    ./scripts/deploy-ssh.sh
#     (which calls this remotely after `git pull`)
#   * On the server:       sudo -u deploy ./scripts/server/server-deploy.sh
#
# Configuration is read from environment variables (typically passed by
# deploy-ssh.sh).
#
# Required env vars on the server:
#   APP_DIR              absolute path to project root (must contain backend/)
#   BACKUP_COMMAND       command that backs up DB + uploads and fails on error
#                        (use scripts/server/backup-comics.sh)
#
# Optional env vars:
#   APP_URL              public same-origin URL; required unless
#                        SKIP_FRONTEND=1
#   WEB_USER             www-data | nginx | http (default www-data)
#   WEB_GROUP            web user's group (default = WEB_USER)
#   SKIP_FRONTEND=1      don't run npm build, assume backend/public/ already has
#                        index.html + assets/ uploaded another way
#   SKIP_COMPOSER=1      skip composer install (PHP-only redeploys)
#   POST_DEPLOY_HOOK     shell command run after cache:warmup
#   ASSET_RETENTION_DAYS days a superseded frontend chunk stays available
#                        (default 14; see scripts/asset-retention.sh)
# =============================================================================

set -euo pipefail

# ---- defaults ---------------------------------------------------------------
: "${APP_DIR:?APP_DIR must be set (e.g. /var/www/comics)}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-$WEB_USER}"
SKIP_FRONTEND="${SKIP_FRONTEND:-0}"
SKIP_COMPOSER="${SKIP_COMPOSER:-0}"
POST_DEPLOY_HOOK="${POST_DEPLOY_HOOK:-}"
BACKUP_COMMAND="${BACKUP_COMMAND:-}"

log()  { printf "\033[1;36m[server]\033[0m %s\n" "$*"; }
warn() { printf "\033[1;33m[warn]\033[0m   %s\n" "$*"; }
fail() { printf "\033[1;31m[fail]\033[0m   %s\n" "$*" >&2; exit 1; }

[ -d "$APP_DIR/backend" ] || fail "$APP_DIR/backend does not exist."
# ---- check the secret env file is present -----------------------------------
if [ ! -f "$APP_DIR/backend/.env.local" ] && [ ! -f "$APP_DIR/backend/.env.prod.local" ] && [ ! -f "$APP_DIR/backend/.env.local.php" ]; then
    fail "No backend/.env.local, backend/.env.prod.local or backend/.env.local.php found.
       Copy your prod env values to $APP_DIR/backend/.env.local before
       deploying for the first time. See SSH-deploy.md section 4.4."
fi

[ -n "$BACKUP_COMMAND" ] || fail "BACKUP_COMMAND must back up the production database and uploads before deployment."
log "Running pre-deploy backup"
eval "$BACKUP_COMMAND"

# =============================================================================
# 0) Production public-file policy
# =============================================================================
public_dir="$APP_DIR/backend/public"
# Through a temporary name, so Apache never reads a half-written file and an
# existing symlink is replaced instead of being written through.
log "Installing production public/.htaccess"
cp "$APP_DIR/scripts/deploy/htaccess.dist" "$public_dir/.htaccess.next"
mv -f "$public_dir/.htaccess.next" "$public_dir/.htaccess"
# No longer in the repository, but git only removes a copy it was tracking.
rm -f "$public_dir/error.php"

# Removes what the previous release needed once ASSET_RETENTION_DAYS have passed.
prune_superseded_assets() {
    local ledger="$APP_DIR/backend/var/asset-retention.tsv"
    local work name
    work="$(mktemp -d)"
    # shellcheck disable=SC2064 # Expanded now: $work is local to this function.
    trap "rm -rf '$work'" RETURN
    ls -1A "$APP_DIR/frontend/dist/assets" > "$work/live"
    ls -1A "$public_dir/assets" > "$work/present"
    bash "$APP_DIR/scripts/asset-retention.sh" "$work/live" "$work/present" "$ledger" "$work/ledger.next" "$work/expired" || return 1
    while IFS= read -r name; do
        rm -f -- "$public_dir/assets/$name" || return 1
    done < "$work/expired"
    mkdir -p "$(dirname "$ledger")"
    cp "$work/ledger.next" "$ledger"
    log "Superseded assets deleted after ${ASSET_RETENTION_DAYS:-14} days: $(wc -l < "$work/expired")"
}

# =============================================================================
# 1) Composer
# =============================================================================
if [ "$SKIP_COMPOSER" != "1" ]; then
    log "composer install --no-dev"
    cd "$APP_DIR/backend"
    APP_ENV=prod APP_DEBUG=0 composer install \
        --no-dev --optimize-autoloader --classmap-authoritative \
        --no-interaction --no-progress

    if [ -f .env.local.php ]; then
        warn ".env.local.php is active; Symfony will ignore later .env.local edits until the compiled file is regenerated or removed."
    else
        log "Using editable server-local dotenv configuration (no composer dump-env)"
    fi
else
    warn "Skipping composer install (SKIP_COMPOSER=1)"
fi

# =============================================================================
# 2) Frontend
# =============================================================================
if [ "$SKIP_FRONTEND" != "1" ]; then
    : "${APP_URL:?APP_URL must be set when building the frontend (e.g. https://comics.example.com)}"
    if [ -d "$APP_DIR/frontend" ] && command -v npm >/dev/null 2>&1; then
        log "npm ci && npm run build"
        cd "$APP_DIR/frontend"
        npm ci --no-audit --no-fund
        rm -rf dist
        APP_URL="$APP_URL" npm run build
        APP_URL="$APP_URL" npm run check:seo

        # Beside the old build, not in place of it: pages already open keep asking
        # for the old chunks at their original URLs. Assets first, because the
        # index.html copied next refers to them. assets.previous is what earlier
        # versions of this script left behind, and nothing can reach it.
        log "Installing frontend build while preserving uploads and previous assets"
        rm -rf "$public_dir/assets.next" "$public_dir/assets.previous"
        mkdir -p "$public_dir/assets"
        cp -a "$APP_DIR/frontend/dist/assets/." "$public_dir/assets/"
        find "$APP_DIR/frontend/dist" -maxdepth 1 -type f -exec cp -a {} "$public_dir/" \;
        # Tidying up must not fail the deployment; it can try again next time.
        prune_superseded_assets || warn "Could not prune superseded assets; they stay on the server until the next deploy."
    else
        warn "Frontend skipped: $APP_DIR/frontend missing or npm not installed."
        warn "Make sure backend/public/index.html + assets/ are already present."
    fi
else
    log "Skipping frontend build (SKIP_FRONTEND=1)"
fi

# =============================================================================
# 3) Migrations
# =============================================================================
log "doctrine:migrations:migrate"
cd "$APP_DIR/backend"
APP_ENV=prod APP_DEBUG=0 php bin/console doctrine:migrations:migrate \
    --no-interaction --allow-no-migration --env=prod

log "Encrypting legacy Dropbox tokens and backfilling file sizes"
APP_ENV=prod APP_DEBUG=0 php bin/console app:migrate-dropbox-tokens --env=prod
APP_ENV=prod APP_DEBUG=0 php bin/console app:backfill-comic-file-size --env=prod

# =============================================================================
# 4) Cache
# =============================================================================
log "cache:clear + cache:warmup"
APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear --env=prod --no-debug
APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup --env=prod --no-debug

# =============================================================================
# 5) Permissions
# =============================================================================
log "Fixing permissions on var/ for $WEB_USER:$WEB_GROUP"
mkdir -p "$APP_DIR/backend/var/cache" "$APP_DIR/backend/var/log"
# Generated comic pages. Kept out of public/ because access to them is checked
# per request. If this is not writable the application still serves every page,
# it just regenerates each one every time, so create it here rather than leaving
# a silently slow installation behind.
mkdir -p "$APP_DIR/backend/var/page-cache"
mkdir -p "$APP_DIR/backend/public/uploads"

# Try without sudo first (when the deploy user IS the web user); fall back to sudo.
if chown -R "$WEB_USER:$WEB_GROUP" \
        "$APP_DIR/backend/var" \
        "$APP_DIR/backend/public/uploads" 2>/dev/null; then
    :
elif command -v sudo >/dev/null 2>&1; then
    sudo chown -R "$WEB_USER:$WEB_GROUP" \
        "$APP_DIR/backend/var" \
        "$APP_DIR/backend/public/uploads"
else
    warn "Could not chown var/ — neither direct write nor sudo available."
    warn "If you see permission errors at runtime, fix this manually."
fi

# Use 'g+w' rather than 0777 so deploy user + web user can both write.
chmod -R u+rwX,g+rwX "$APP_DIR/backend/var" 2>/dev/null || true
chmod -R u+rwX,g+rwX "$APP_DIR/backend/public/uploads" 2>/dev/null || true

# =============================================================================
# 6) Post-deploy hook (php-fpm reload, etc.)
# =============================================================================
if [ -n "$POST_DEPLOY_HOOK" ]; then
    log "Running post-deploy hook: $POST_DEPLOY_HOOK"
    eval "$POST_DEPLOY_HOOK"
fi

log "Deploy finished at $(date -u +%FT%TZ)"
