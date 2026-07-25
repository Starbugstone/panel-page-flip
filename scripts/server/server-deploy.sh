#!/usr/bin/env bash
# =============================================================================
# scripts/server/server-deploy.sh
# -----------------------------------------------------------------------------
# Runs ON the production server. Drives the post-pull build:
#   1. composer install --no-dev --optimize-autoloader
#   2. composer dump-env prod              (consolidates .env.prod.local)
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
# deploy-ssh.sh) OR from /etc/comics-deploy.env if you set up a static config.
#
# Required env vars on the server:
#   APP_DIR              absolute path to project root (must contain backend/)
#   WEB_USER             www-data | nginx | http (default www-data)
#   WEB_GROUP            web user's group (default = WEB_USER)
#
# Optional env vars:
#   SKIP_FRONTEND=1      don't run npm build, assume backend/public/ already has
#                        index.html + assets/ uploaded another way
#   SKIP_COMPOSER=1      skip composer install (PHP-only redeploys)
#   POST_DEPLOY_HOOK     shell command run after cache:warmup
# =============================================================================

set -euo pipefail

# ---- defaults ---------------------------------------------------------------
: "${APP_DIR:?APP_DIR must be set (e.g. /var/www/comics)}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-$WEB_USER}"
SKIP_FRONTEND="${SKIP_FRONTEND:-0}"
SKIP_COMPOSER="${SKIP_COMPOSER:-0}"
POST_DEPLOY_HOOK="${POST_DEPLOY_HOOK:-}"

log()  { printf "\033[1;36m[server]\033[0m %s\n" "$*"; }
warn() { printf "\033[1;33m[warn]\033[0m   %s\n" "$*"; }
fail() { printf "\033[1;31m[fail]\033[0m   %s\n" "$*" >&2; exit 1; }

[ -d "$APP_DIR/backend" ] || fail "$APP_DIR/backend does not exist."

# ---- check the secret env file is present -----------------------------------
if [ ! -f "$APP_DIR/backend/.env.prod.local" ] && [ ! -f "$APP_DIR/backend/.env.local.php" ]; then
    fail "Neither backend/.env.prod.local nor backend/.env.local.php found.
       Copy your prod env values to $APP_DIR/backend/.env.prod.local before
       deploying for the first time. See SSH-deploy.md section 2.4."
fi

# =============================================================================
# 1) Composer
# =============================================================================
if [ "$SKIP_COMPOSER" != "1" ]; then
    log "composer install --no-dev"
    cd "$APP_DIR/backend"
    APP_ENV=prod APP_DEBUG=0 composer install \
        --no-dev --optimize-autoloader --classmap-authoritative \
        --no-interaction --no-progress

    if [ -f .env.prod.local ]; then
        log "composer dump-env prod (consolidates .env into .env.local.php)"
        APP_ENV=prod composer dump-env prod
    fi
else
    warn "Skipping composer install (SKIP_COMPOSER=1)"
fi

# =============================================================================
# 2) Frontend
# =============================================================================
if [ "$SKIP_FRONTEND" != "1" ]; then
    if [ -d "$APP_DIR/frontend" ] && command -v npm >/dev/null 2>&1; then
        log "npm ci && npm run build"
        cd "$APP_DIR/frontend"
        npm ci --no-audit --no-fund
        rm -rf dist
        npm run build

        log "Copying frontend/dist/ into backend/public/ (preserving uploads/)"
        # Remove old hashed assets to prevent stale leftovers, keep uploads/.
        rm -rf "$APP_DIR/backend/public/assets"
        cp -a "$APP_DIR/frontend/dist/." "$APP_DIR/backend/public/"
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
