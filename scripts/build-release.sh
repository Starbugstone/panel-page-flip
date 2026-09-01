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
#       ├── .env.local.php              <-- only in explicit compiled mode
#       ├── composer.json
#       └── composer.lock
#
# Usage:
#   ./scripts/build-release.sh
#   ./scripts/build-release.sh --tarball   # also produce release.tar.gz
#   ./scripts/build-release.sh --skip-frontend
#   ./scripts/build-release.sh --skip-backend
#
# Server-local mode (the default) never puts production secrets in the release;
# backend/.env.local remains authoritative on the host. Set
# DEPLOY_CONFIG_MODE=compiled to retain the portable compiled-env workflow.
# =============================================================================

set -euo pipefail

# --- locate repo root ---------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

ENV_FILE="$SCRIPT_DIR/.env.deploy"
RELEASE_DIR="$REPO_ROOT/release"
PHP_VERSION_DEFAULT="8.2"
# Must satisfy frontend/package.json "engines": building the release on an
# older runtime than the one the project declares is a difference nobody sees
# until the built assets misbehave in production.
NODE_VERSION_DEFAULT="22"

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
require_command git

log "Verifying checkout matches origin/main"
if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    fail "Production releases must be built from a Git checkout."
fi
if [ -n "$(git status --porcelain --untracked-files=all)" ]; then
    fail "Refusing production release: the checkout has modified, staged, or untracked files. Commit or remove them before building."
fi
if ! git remote get-url origin >/dev/null 2>&1; then
    fail "Git remote 'origin' is not configured."
fi
if ! git fetch --quiet origin main; then
    fail "Could not refresh origin/main; refusing to build from an unverifiable checkout."
fi
LOCAL_HEAD="$(git rev-parse HEAD)"
REMOTE_MAIN="$(git rev-parse refs/remotes/origin/main)"
if [ "$LOCAL_HEAD" != "$REMOTE_MAIN" ]; then
    fail "Refusing production release: local HEAD ${LOCAL_HEAD:0:12} does not match origin/main ${REMOTE_MAIN:0:12}. Run: git switch main && git pull --ff-only origin main"
fi
log "Checkout is current at ${LOCAL_HEAD:0:12}"

if [ ! -f "$ENV_FILE" ]; then
    fail "Missing $ENV_FILE — copy scripts/.env.deploy.example and fill it in."
fi

# Load PROD_* / POST_DEPLOY_TOKEN safely.
set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

DEPLOY_CONFIG_MODE="${DEPLOY_CONFIG_MODE:-server-local}"
case "$DEPLOY_CONFIG_MODE" in
    server-local|compiled) ;;
    *) fail "DEPLOY_CONFIG_MODE must be 'server-local' or 'compiled'." ;;
esac

# Try to read PHP_VERSION / NODE_VERSION from the project's .env if present.
if [ -f "$REPO_ROOT/.env" ]; then
    # shellcheck disable=SC1091
    source <(grep -E '^(PHP_VERSION|NODE_VERSION)=' "$REPO_ROOT/.env" || true)
fi
PHP_VERSION="${PHP_VERSION:-$PHP_VERSION_DEFAULT}"
NODE_VERSION="${NODE_VERSION:-$NODE_VERSION_DEFAULT}"

# Only compiled mode bakes runtime configuration into the artefact. The default
# server-local mode uses disposable non-production values while Composer builds,
# then deletes them; the host's ignored backend/.env.local is never copied or
# replaced.
REQUIRED_PROD_VARS=(PUBLIC_URL)
if [ "$DEPLOY_CONFIG_MODE" = "compiled" ]; then
    REQUIRED_PROD_VARS+=(PROD_APP_SECRET PROD_APP_DATA_KEY PROD_DATABASE_URL PROD_CORS_ALLOW_ORIGIN POST_DEPLOY_TOKEN)
else
    PROD_APP_SECRET=build-only-not-deployed
    PROD_APP_DATA_KEY=0000000000000000000000000000000000000000000000000000000000000000
    PROD_DATABASE_URL='mysql://build:build@127.0.0.1:3306/build?serverVersion=8.0.32&charset=utf8mb4'
    PROD_CORS_ALLOW_ORIGIN='^https://build\.invalid$'
    POST_DEPLOY_TOKEN=build-only-not-deployed
fi
for v in "${REQUIRED_PROD_VARS[@]}"; do
    if [ -z "${!v:-}" ]; then
        fail "Missing $v in $ENV_FILE."
    fi
done

# Required rather than defaulted, and the old default refused by name. The
# deployment is same-origin, so this regex exists to name that one origin; the
# fallback it replaces was ^https://.*$ — every site on the internet, baked into
# production by a variable nobody had to set. Asked for explicitly because the
# escaping is easy to get wrong and a derived regex would get it wrong quietly.
if [ "$PROD_CORS_ALLOW_ORIGIN" = '^https://.*$' ]; then
    fail "PROD_CORS_ALLOW_ORIGIN must match this deployment's own origin, not every HTTPS site. See scripts/.env.deploy.example."
fi

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
    # Do not bind-mount scripts/ here: it can contain .env.deploy with
    # FTP/production secrets, and the Vite build only needs the route manifest.
    docker run --rm \
        -v "$REPO_ROOT/frontend":/app \
        -w /app \
        "node:${NODE_VERSION}-alpine" \
        sh -c 'rm -rf dist'
    docker run --rm \
        -v "$REPO_ROOT/frontend":/app \
        -v "$REPO_ROOT/backend/config/frontend-routes.json":/backend/config/frontend-routes.json:ro \
        -w /app \
        -u "$(id -u):$(id -g)" \
        -e APP_URL="$PUBLIC_URL" \
        -e FRONTEND_ROUTES_FILE=/backend/config/frontend-routes.json \
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
            npm run check:seo
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

    # public/comic.png is deliberately kept: ComicController serves it as the
    # cover placeholder when a comic's own cover file is missing. Excluding it
    # turned that fallback into a 404 and a broken image in the library.

    # Recreate var/ structure expected by Symfony.
    #
    # page-cache holds generated comic pages. It is shipped as an empty
    # directory because an FTP deployment has no shell to create one, and
    # without it every page turn regenerates the image instead of reading it
    # back. Nothing in it is authoritative, so shipping it empty is correct.
    mkdir -p "$RELEASE_DIR/backend/var/cache" "$RELEASE_DIR/backend/var/log" "$RELEASE_DIR/backend/var/page-cache"

    # Drop the .htaccess so Apache shared hosting routes to index.php.
    log "Installing public/.htaccess"
    cp "$SCRIPT_DIR/deploy/htaccess.dist" "$RELEASE_DIR/backend/public/.htaccess"

    # Drop the post-deploy runner.
    log "Installing public/_post-deploy.php"
    cp "$SCRIPT_DIR/deploy/_post-deploy.php.dist" "$RELEASE_DIR/backend/public/_post-deploy.php"

    # Generate the disposable Composer environment. Only compiled mode retains
    # its values in .env.local.php; server-local mode deletes it after building.
    log "Writing temporary build environment"
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

    if [ "${PROD_SECURITY_ALERTS_ENABLED:-0}" != "0" ] && [ -z "${PROD_MAILER_DSN:-}" ]; then
        fail "PROD_MAILER_DSN must be set when PROD_SECURITY_ALERTS_ENABLED is enabled."
    fi

    # A mistyped publisher id is not a build error to Symfony — it logs a warning
    # and serves the site with advertising quietly off, which is an outage
    # nobody is watching for. Caught here instead, while somebody is looking.
    # Trimmed first, because AdvertisingConfiguration trims before it validates:
    # matching the raw value here would reject a publisher id the application
    # would have accepted, and abort the release over surrounding whitespace.
    PROD_ADSENSE_CLIENT="$(printf '%s' "${PROD_ADSENSE_CLIENT:-}" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
    PROD_GOOGLE_ANALYTICS_MEASUREMENT_ID="$(printf '%s' "${PROD_GOOGLE_ANALYTICS_MEASUREMENT_ID:-}" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' | tr '[:lower:]' '[:upper:]')"
    PROD_OAUTH_GOOGLE_CLIENT_ID="$(printf '%s' "${PROD_OAUTH_GOOGLE_CLIENT_ID:-}" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
    PROD_OAUTH_GOOGLE_CLIENT_SECRET="$(printf '%s' "${PROD_OAUTH_GOOGLE_CLIENT_SECRET:-}" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
    TURNSTILE_SITE_KEY="$(printf '%s' "${TURNSTILE_SITE_KEY:-}" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
    TURNSTILE_SECRET_KEY="$(printf '%s' "${TURNSTILE_SECRET_KEY:-}" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
    if [ "$DEPLOY_CONFIG_MODE" = "server-local" ]; then
        PROD_ADSENSE_ENABLED=false
        PROD_ADSENSE_CLIENT=
        PROD_GOOGLE_ANALYTICS_ENABLED=false
        PROD_GOOGLE_ANALYTICS_MEASUREMENT_ID=
        PROD_OAUTH_GOOGLE_CLIENT_ID=
        PROD_OAUTH_GOOGLE_CLIENT_SECRET=
        TURNSTILE_ENABLED=false
        TURNSTILE_SITE_KEY=
        TURNSTILE_SECRET_KEY=
    fi
    if [ "${PROD_GOOGLE_ANALYTICS_ENABLED:-false}" = "true" ]; then
        if [[ ! "$PROD_GOOGLE_ANALYTICS_MEASUREMENT_ID" =~ ^G-[A-Z0-9]{5,20}$ ]]; then
            fail "PROD_GOOGLE_ANALYTICS_MEASUREMENT_ID must be G- followed by 5 to 20 letters or digits when PROD_GOOGLE_ANALYTICS_ENABLED is true."
        fi
        case "$PROD_ADSENSE_CLIENT" in
            ca-pub-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]) ;;
            *) fail "PROD_ADSENSE_CLIENT must identify the certified Google CMP when PROD_GOOGLE_ANALYTICS_ENABLED is true." ;;
        esac
    fi
    if [ "${PROD_ADSENSE_ENABLED:-false}" = "true" ]; then
        case "$PROD_ADSENSE_CLIENT" in
            ca-pub-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]) ;;
            *) fail "PROD_ADSENSE_CLIENT must be ca-pub- followed by 16 digits when PROD_ADSENSE_ENABLED is true." ;;
        esac
    fi
    if { [ -n "$PROD_OAUTH_GOOGLE_CLIENT_ID" ] && [ -z "$PROD_OAUTH_GOOGLE_CLIENT_SECRET" ]; } \
        || { [ -z "$PROD_OAUTH_GOOGLE_CLIENT_ID" ] && [ -n "$PROD_OAUTH_GOOGLE_CLIENT_SECRET" ]; }; then
        fail "PROD_OAUTH_GOOGLE_CLIENT_ID and PROD_OAUTH_GOOGLE_CLIENT_SECRET must both be set to enable Google social sign-in."
    fi
    if [ "${TURNSTILE_ENABLED:-false}" = "true" ] \
        && { [ -z "$TURNSTILE_SITE_KEY" ] || [ -z "$TURNSTILE_SECRET_KEY" ]; }; then
        fail "TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY are required when TURNSTILE_ENABLED is true."
    fi

    write_dotenv APP_ENV prod
    write_dotenv APP_DEBUG 0
    write_dotenv APP_SECRET "$PROD_APP_SECRET"
    write_dotenv APP_DATA_KEY "$PROD_APP_DATA_KEY"
    write_dotenv APP_URL "${PUBLIC_URL%/}"
    write_dotenv DATABASE_URL "$PROD_DATABASE_URL"
    write_dotenv CORS_ALLOW_ORIGIN "$PROD_CORS_ALLOW_ORIGIN"
    write_dotenv TRUSTED_PROXIES "${PROD_TRUSTED_PROXIES:-}"
    write_dotenv MAILER_DSN "${PROD_MAILER_DSN:-null://null}"
    write_dotenv MAILER_FROM_ADDRESS "${PROD_MAILER_FROM_ADDRESS:-noreply@example.com}"
    write_dotenv MAILER_FROM_NAME "${PROD_MAILER_FROM_NAME:-Panel Page Flip}"
    write_dotenv PRIVACY_OPERATOR "${PROD_PRIVACY_OPERATOR:-Panel Page Flip site operator}"
    write_dotenv PRIVACY_EMAIL "${PROD_PRIVACY_EMAIL:-${PROD_MAILER_FROM_ADDRESS:-noreply@example.com}}"
    write_dotenv MAILER_TRANSPORT "${PROD_MAILER_TRANSPORT:-smtp}"
    write_dotenv MESSENGER_TRANSPORT_DSN "${PROD_MESSENGER_TRANSPORT_DSN:-doctrine://default?auto_setup=0}"
    write_dotenv LOCK_DSN "${PROD_LOCK_DSN:-flock}"
    write_dotenv MAX_CONCURRENT_UPLOADS "${PROD_MAX_CONCURRENT_UPLOADS:-3}"
    write_dotenv MAX_PARALLEL_FILE_UPLOADS "${PROD_MAX_PARALLEL_FILE_UPLOADS:-2}"
    write_dotenv UPLOAD_USER_QUOTA_BYTES "${PROD_UPLOAD_USER_QUOTA_BYTES:-10737418240}"
    # Written explicitly so a release never inherits the development default
    # from a stray .env; the application defaults it on in any case.
    write_dotenv LOGIN_RATE_LIMIT_ENABLED "${PROD_LOGIN_RATE_LIMIT_ENABLED:-1}"
    write_dotenv APP_LOG_RETENTION_DAYS "${PROD_APP_LOG_RETENTION_DAYS:-30}"
    write_dotenv SECURITY_LOG_RETENTION_DAYS "${PROD_SECURITY_LOG_RETENTION_DAYS:-365}"
    write_dotenv AUDIT_LOG_RETENTION_DAYS "${PROD_AUDIT_LOG_RETENTION_DAYS:-365}"
    write_dotenv SECURITY_ALERTS_ENABLED "${PROD_SECURITY_ALERTS_ENABLED:-0}"
    write_dotenv SECURITY_ALERT_EMAILS "${PROD_SECURITY_ALERT_EMAILS:-}"
    write_dotenv SECURITY_ALERT_WINDOW_MINUTES "${PROD_SECURITY_ALERT_WINDOW_MINUTES:-15}"
    write_dotenv SECURITY_ALERT_FAILED_LOGIN_THRESHOLD "${PROD_SECURITY_ALERT_FAILED_LOGIN_THRESHOLD:-10}"
    write_dotenv SECURITY_ALERT_AUTHZ_THRESHOLD "${PROD_SECURITY_ALERT_AUTHZ_THRESHOLD:-10}"
    write_dotenv DROPBOX_APP_KEY "${PROD_DROPBOX_APP_KEY:-}"
    write_dotenv DROPBOX_APP_SECRET "${PROD_DROPBOX_APP_SECRET:-}"
    write_dotenv DROPBOX_REDIRECT_URI "${PROD_DROPBOX_REDIRECT_URI:-${PUBLIC_URL%/}/api/dropbox/callback}"
    write_dotenv DROPBOX_APP_FOLDER "${PROD_DROPBOX_APP_FOLDER:-/}"
    write_dotenv DROPBOX_SYNC_LIMIT "${PROD_DROPBOX_SYNC_LIMIT:-10}"
    write_dotenv DROPBOX_RATE_LIMIT "${PROD_DROPBOX_RATE_LIMIT:-60}"
    # Only explicit compiled mode bakes these settings. In the default
    # server-local mode they were forced off above and this disposable file is
    # removed after Composer finishes.
    write_dotenv ADSENSE_ENABLED "${PROD_ADSENSE_ENABLED:-false}"
    write_dotenv ADSENSE_CLIENT "$PROD_ADSENSE_CLIENT"
    write_dotenv GOOGLE_ANALYTICS_ENABLED "${PROD_GOOGLE_ANALYTICS_ENABLED:-false}"
    write_dotenv GOOGLE_ANALYTICS_MEASUREMENT_ID "$PROD_GOOGLE_ANALYTICS_MEASUREMENT_ID"
    write_dotenv OAUTH_GOOGLE_CLIENT_ID "${PROD_OAUTH_GOOGLE_CLIENT_ID:-}"
    write_dotenv OAUTH_GOOGLE_CLIENT_SECRET "${PROD_OAUTH_GOOGLE_CLIENT_SECRET:-}"
    write_dotenv TURNSTILE_ENABLED "${TURNSTILE_ENABLED:-false}"
    write_dotenv TURNSTILE_SITE_KEY "$TURNSTILE_SITE_KEY"
    write_dotenv TURNSTILE_SECRET_KEY "$TURNSTILE_SECRET_KEY"
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
        -e DEPLOY_CONFIG_MODE="$DEPLOY_CONFIG_MODE" \
        "php:${PHP_VERSION}-cli" \
        sh -c '
            set -e
            trap '\''chown -R "$RELEASE_UID:$RELEASE_GID" /app'\'' EXIT
            apk add --no-cache --quiet git unzip libzip-dev icu-dev oniguruma-dev libxml2-dev 2>/dev/null \
                || (apt-get update -qq && apt-get install -y -qq git unzip libzip-dev libicu-dev libonig-dev libxml2-dev)
            docker-php-ext-install -j"$(nproc)" zip intl pdo_mysql opcache >/dev/null
            curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer >/dev/null
            composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress
            if [ "${DEPLOY_CONFIG_MODE:-server-local}" = "compiled" ]; then composer dump-env prod; fi
            php bin/console cache:clear --env=prod --no-debug
            php bin/console cache:warmup --env=prod --no-debug
        '

    # The temporary dotenv file is never deployed. Compiled mode has already
    # consolidated it; server-local mode deliberately ships no runtime config.
    if [ "$DEPLOY_CONFIG_MODE" = "server-local" ]; then
        log "Removing disposable build environment (server .env.local remains authoritative)"
        rm -f "$PROD_ENV_FILE"
    elif [ -f "$RELEASE_DIR/backend/.env.local.php" ]; then
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
