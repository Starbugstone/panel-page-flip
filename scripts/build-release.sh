#!/usr/bin/env bash
# =============================================================================
# build-release.sh
# -----------------------------------------------------------------------------
# Builds a production release inside ./release using only Docker.
# No host Node/Composer/PHP needed.
#
# Output layout (matches the production server tree):
#   release/
#   └── backend/
#       ├── bin/
#       ├── config/
#       ├── migrations/
#       ├── public/                     <-- frontend dist + Symfony front controller
#       │   ├── index.php
#       │   ├── .htaccess               <-- copied from scripts/deploy/htaccess.dist
#       │   ├── _post-deploy.php        <-- copied from scripts/deploy/_post-deploy.php.dist
#       │   ├── index.html, assets/...  <-- React build
#       │   └── (uploads/ stays on the server, never touched)
#       ├── src/
#       ├── templates/
#       ├── translations/
#       ├── vendor/                     <-- composer install --no-dev
#       ├── var/cache/prod/             <-- pre-warmed
#       ├── .env
#       ├── .env.local.php              <-- composer dump-env prod
#       ├── composer.json
#       └── composer.lock
#
# Usage:
#   ./scripts/build-release.sh
#   ./scripts/build-release.sh --tarball   # also produce release.tar.gz
#   ./scripts/build-release.sh --skip-frontend
#   ./scripts/build-release.sh --skip-backend
#
# Reads scripts/.env.deploy for the PROD_* and POST_DEPLOY_TOKEN values.
# =============================================================================

set -euo pipefail

# --- locate repo root ---------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

ENV_FILE="$SCRIPT_DIR/.env.deploy"
RELEASE_DIR="$REPO_ROOT/release"
PHP_VERSION_DEFAULT="8.2"
NODE_VERSION_DEFAULT="20"

# --- helpers ------------------------------------------------------------------
log()  { printf "\033[1;36m[build]\033[0m %s\n" "$*"; }
warn() { printf "\033[1;33m[warn]\033[0m  %s\n" "$*"; }
fail() { printf "\033[1;31m[fail]\033[0m  %s\n" "$*" >&2; exit 1; }

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        fail "Required command '$1' is not installed."
    fi
}

# --- parse args ---------------------------------------------------------------
DO_FRONTEND=1
DO_BACKEND=1
DO_TARBALL=0

for arg in "$@"; do
    case "$arg" in
        --skip-frontend) DO_FRONTEND=0 ;;
        --skip-backend)  DO_BACKEND=0 ;;
        --tarball)       DO_TARBALL=1 ;;
        -h|--help)
            sed -n '2,30p' "${BASH_SOURCE[0]}"
            exit 0
            ;;
        *) fail "Unknown argument: $arg" ;;
    esac
done

# --- preflight ----------------------------------------------------------------
require_command docker

if [ ! -f "$ENV_FILE" ]; then
    fail "Missing $ENV_FILE — copy scripts/.env.deploy.example and fill it in."
fi

# Load PROD_* / POST_DEPLOY_TOKEN safely.
set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

# Try to read PHP_VERSION / NODE_VERSION from the project's .env if present.
if [ -f "$REPO_ROOT/.env" ]; then
    # shellcheck disable=SC1091
    source <(grep -E '^(PHP_VERSION|NODE_VERSION)=' "$REPO_ROOT/.env" || true)
fi
PHP_VERSION="${PHP_VERSION:-$PHP_VERSION_DEFAULT}"
NODE_VERSION="${NODE_VERSION:-$NODE_VERSION_DEFAULT}"

# Required PROD_* values for backend env baking.
REQUIRED_PROD_VARS=(
    PROD_APP_SECRET
    PROD_APP_DATA_KEY
    PROD_DATABASE_URL
    PROD_FRONTEND_URL
    POST_DEPLOY_TOKEN
)
for v in "${REQUIRED_PROD_VARS[@]}"; do
    if [ -z "${!v:-}" ]; then
        fail "Missing $v in $ENV_FILE."
    fi
done

# --- clean ---------------------------------------------------------------------
log "Cleaning $RELEASE_DIR"
rm -rf "$RELEASE_DIR"
mkdir -p "$RELEASE_DIR/backend/public"

# =============================================================================
# Frontend
# =============================================================================
if [ "$DO_FRONTEND" = "1" ]; then
    log "Building frontend with node:${NODE_VERSION}-alpine"
    # The development container can leave generated dist files owned by root.
    # Remove only that disposable build output before switching to the host UID.
    docker run --rm \
        -v "$REPO_ROOT/frontend":/app \
        -w /app \
        "node:${NODE_VERSION}-alpine" \
        sh -c 'rm -rf dist'
    docker run --rm \
        -v "$REPO_ROOT/frontend":/app \
        -w /app \
        -u "$(id -u):$(id -g)" \
        -e VITE_BUILD_ID="$(date -u +%Y%m%d-%H%M%S)" \
        -e VITE_BUILD_TIME="$(date -u +%FT%TZ)" \
        "node:${NODE_VERSION}-alpine" \
        sh -c '
            set -e
            if [ -f package-lock.json ]; then
                npm ci --no-audit --no-fund
            else
                npm install --no-audit --no-fund
            fi
            rm -rf dist
            npm run build
        '
    if [ ! -d "$REPO_ROOT/frontend/dist" ]; then
        fail "Frontend build did not produce frontend/dist/"
    fi
    log "Copying frontend/dist/ into release/backend/public/"
    cp -a "$REPO_ROOT/frontend/dist/." "$RELEASE_DIR/backend/public/"
else
    warn "Skipping frontend build (--skip-frontend)"
fi

# =============================================================================
# Backend
# =============================================================================
if [ "$DO_BACKEND" = "1" ]; then
    log "Copying backend sources to release/"
    rsync -a \
        --exclude='/var/' \
        --exclude='/vendor/' \
        --exclude='/tests/' \
        --exclude='/.env.local' \
        --exclude='/.env.local.php' \
        --exclude='/.env.dev' \
        --exclude='/.env.test' \
        --exclude='/.env.*.local' \
        --exclude='/public/uploads/' \
        --exclude='/public/error.php' \
        --exclude='/public/index.html' \
        --exclude='/public/comic.png' \
        --exclude='/public/placeholder.svg' \
        --exclude='/public/robots.txt' \
        --exclude='/public/favicon.ico' \
        --exclude='/.gitignore' \
        --exclude='/phpunit.xml' \
        --exclude='/phpunit.xml.dist' \
        --exclude='/setup.sh' \
        --exclude='/compose.yaml' \
        --exclude='/compose.override.yaml' \
        "$REPO_ROOT/backend/" "$RELEASE_DIR/backend/"

    # Recreate var/ structure expected by Symfony.
    mkdir -p "$RELEASE_DIR/backend/var/cache" "$RELEASE_DIR/backend/var/log"

    # Drop the .htaccess so Apache shared hosting routes to index.php.
    log "Installing public/.htaccess"
    cp "$SCRIPT_DIR/deploy/htaccess.dist" "$RELEASE_DIR/backend/public/.htaccess"

    # Drop the post-deploy runner.
    log "Installing public/_post-deploy.php"
    cp "$SCRIPT_DIR/deploy/_post-deploy.php.dist" "$RELEASE_DIR/backend/public/_post-deploy.php"

    # Generate .env.prod.local from PROD_* vars (will be consolidated by dump-env).
    log "Writing temporary .env.prod.local"
    PROD_ENV_FILE="$RELEASE_DIR/backend/.env.prod.local"
    : > "$PROD_ENV_FILE"

    write_dotenv() {
        local key="$1" value="$2" escaped
        if [[ "$value" == *$'\n'* || "$value" == *$'\r'* ]]; then
            fail "$key must not contain line breaks."
        fi
        escaped="${value//\\/\\\\}"
        escaped="${escaped//\"/\\\"}"
        escaped="${escaped//\$/\\\$}"
        printf '%s="%s"\n' "$key" "$escaped" >> "$PROD_ENV_FILE"
    }

    write_dotenv APP_ENV prod
    write_dotenv APP_DEBUG 0
    write_dotenv APP_SECRET "$PROD_APP_SECRET"
    write_dotenv APP_DATA_KEY "$PROD_APP_DATA_KEY"
    write_dotenv APP_SCHEME "${PROD_FRONTEND_SCHEME:-https}"
    write_dotenv APP_HOST "${PROD_FRONTEND_HOST:-localhost}"
    write_dotenv APP_PORT "${PROD_FRONTEND_PORT:-443}"
    write_dotenv DATABASE_URL "$PROD_DATABASE_URL"
    write_dotenv CORS_ALLOW_ORIGIN "${PROD_CORS_ALLOW_ORIGIN:-^https://.*$}"
    write_dotenv FRONTEND_URL "$PROD_FRONTEND_URL"
    write_dotenv FRONTEND_SCHEME "${PROD_FRONTEND_SCHEME:-https}"
    write_dotenv FRONTEND_HOST "${PROD_FRONTEND_HOST:-localhost}"
    write_dotenv FRONTEND_PORT "${PROD_FRONTEND_PORT:-443}"
    write_dotenv MAILER_DSN "${PROD_MAILER_DSN:-null://null}"
    write_dotenv MAILER_FROM_ADDRESS "${PROD_MAILER_FROM_ADDRESS:-noreply@example.com}"
    write_dotenv MAILER_FROM_NAME "${PROD_MAILER_FROM_NAME:-Comic Reader}"
    write_dotenv PRIVACY_OPERATOR "${PROD_PRIVACY_OPERATOR:-Panel Page Flip site operator}"
    write_dotenv PRIVACY_EMAIL "${PROD_PRIVACY_EMAIL:-${PROD_MAILER_FROM_ADDRESS:-noreply@example.com}}"
    write_dotenv MAILER_TRANSPORT "${PROD_MAILER_TRANSPORT:-smtp}"
    write_dotenv MESSENGER_TRANSPORT_DSN "${PROD_MESSENGER_TRANSPORT_DSN:-doctrine://default?auto_setup=0}"
    write_dotenv MAX_CONCURRENT_UPLOADS "${PROD_MAX_CONCURRENT_UPLOADS:-3}"
    write_dotenv DROPBOX_APP_KEY "${PROD_DROPBOX_APP_KEY:-}"
    write_dotenv DROPBOX_APP_SECRET "${PROD_DROPBOX_APP_SECRET:-}"
    write_dotenv DROPBOX_REDIRECT_URI "${PROD_DROPBOX_REDIRECT_URI:-${PROD_FRONTEND_URL}/api/dropbox/callback}"
    write_dotenv DROPBOX_APP_FOLDER "${PROD_DROPBOX_APP_FOLDER:-/}"
    write_dotenv DROPBOX_SYNC_LIMIT "${PROD_DROPBOX_SYNC_LIMIT:-10}"
    write_dotenv DROPBOX_RATE_LIMIT "${PROD_DROPBOX_RATE_LIMIT:-60}"
    write_dotenv DEPLOY_TOKEN "$POST_DEPLOY_TOKEN"
    chmod 600 "$PROD_ENV_FILE"

    log "Running composer install --no-dev inside php:${PHP_VERSION}-cli"
    # The official php:X-cli image already has a base; we add zip+intl which
    # Symfony often needs at composer-time, then install Composer.
    docker run --rm \
        -v "$RELEASE_DIR/backend":/app \
        -w /app \
        -e APP_ENV=prod \
        -e APP_DEBUG=0 \
        -e COMPOSER_ALLOW_SUPERUSER=1 \
        -e COMPOSER_HOME=/tmp/composer \
        -e RELEASE_UID="$(id -u)" \
        -e RELEASE_GID="$(id -g)" \
        "php:${PHP_VERSION}-cli" \
        sh -c '
            set -e
            trap '\''chown -R "$RELEASE_UID:$RELEASE_GID" /app'\'' EXIT
            apk add --no-cache --quiet git unzip libzip-dev icu-dev oniguruma-dev libxml2-dev 2>/dev/null \
                || (apt-get update -qq && apt-get install -y -qq git unzip libzip-dev libicu-dev libonig-dev libxml2-dev)
            docker-php-ext-install -j"$(nproc)" zip intl pdo_mysql opcache >/dev/null
            curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer >/dev/null
            composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress
            composer dump-env prod
            php bin/console cache:clear --env=prod --no-debug
            php bin/console cache:warmup --env=prod --no-debug
        '

    # The .env.prod.local has been consolidated into .env.local.php. Drop the
    # raw file so the production server never sees secrets in plaintext.
    if [ -f "$RELEASE_DIR/backend/.env.local.php" ]; then
        log "Removing raw .env.prod.local (consolidated into .env.local.php)"
        rm -f "$PROD_ENV_FILE"
    else
        warn ".env.local.php was NOT generated. Composer dump-env prod may have failed."
    fi

    # Strip dev-only profiler cache if it sneaked in.
    rm -rf "$RELEASE_DIR/backend/var/cache/dev" "$RELEASE_DIR/backend/var/log/dev.log"
else
    warn "Skipping backend build (--skip-backend)"
fi

# =============================================================================
# Tarball (optional)
# =============================================================================
if [ "$DO_TARBALL" = "1" ]; then
    log "Creating release.tar.gz"
    tar -C "$RELEASE_DIR" \
        --exclude='backend/public/uploads/*' \
        -czf "$REPO_ROOT/release.tar.gz" .
fi

# =============================================================================
# Summary
# =============================================================================
log "Release ready in: $RELEASE_DIR"
RELEASE_SIZE=$(du -sh "$RELEASE_DIR" | cut -f1)
log "Total size:      $RELEASE_SIZE"
log "Next step:       ./scripts/deploy-ftp.sh"
