#!/usr/bin/env bash
# Run one backup-gated server release transaction. CI uploads this script next
# to the validated frontend artifact so the safety ordering does not depend on
# whichever version of the repository is currently live.

set -euo pipefail

: "${APP_DIR:?APP_DIR must be set (for example /home/account/apps/panel-page-flip-production)}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_ENVIRONMENT="${DEPLOY_ENVIRONMENT:-production}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-}"
DEPLOY_SHA="${DEPLOY_SHA:-}"
PREBUILT_FRONTEND_DIR="${PREBUILT_FRONTEND_DIR:-}"
APP_URL="${APP_URL:-}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-$WEB_USER}"
SKIP_FRONTEND="${SKIP_FRONTEND:-0}"
SKIP_COMPOSER="${SKIP_COMPOSER:-0}"
POST_DEPLOY_HOOK="${POST_DEPLOY_HOOK:-}"
BACKUP_COMMAND="${BACKUP_COMMAND:-}"
ALLOW_NONSTANDARD_BRANCH="${ALLOW_NONSTANDARD_BRANCH:-0}"

log()  { printf '\033[1;36m[server]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[server]\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31m[server]\033[0m %s\n' "$*" >&2; exit 1; }

case "$DEPLOY_ENVIRONMENT" in
    production) expected_branch=main ;;
    staging) expected_branch=develop ;;
    *) fail "DEPLOY_ENVIRONMENT must be production or staging." ;;
esac

[ -d "$APP_DIR/backend" ] || fail "$APP_DIR/backend does not exist."
[ -d "$APP_DIR/.git" ] || fail "$APP_DIR is not a Git checkout."
case "$APP_DIR" in
    /*) ;;
    *) fail "APP_DIR must be an absolute path." ;;
esac

identity_file="$APP_DIR/.panel-page-flip-environment"
[ -f "$identity_file" ] || fail "Missing $identity_file; the operator must bind this checkout to staging or production."
[ "$(tr -d '\r\n' < "$identity_file")" = "$DEPLOY_ENVIRONMENT" ] \
    || fail "Checkout identity does not match DEPLOY_ENVIRONMENT; refusing a cross-environment deploy."

runtime_config_files=()
if [ -f "$APP_DIR/backend/.env.local.php" ]; then
    # Symfony's compiled dotenv is authoritative whenever it exists.
    runtime_config_files+=("$APP_DIR/backend/.env.local.php")
else
    [ ! -f "$APP_DIR/backend/.env.local" ] || runtime_config_files+=("$APP_DIR/backend/.env.local")
    [ ! -f "$APP_DIR/backend/.env.prod.local" ] || runtime_config_files+=("$APP_DIR/backend/.env.prod.local")
fi
[ "${#runtime_config_files[@]}" -gt 0 ] || \
    fail "No server-held production dotenv configuration was found under backend/."

configured_app_url="$(php -r '
$key = "APP_URL";
$values = [];
foreach (array_slice($argv, 1) as $path) {
    if (str_ends_with($path, ".php")) {
        $loaded = require $path;
        is_array($loaded) || exit(2);
        $values = array_replace($values, $loaded);
        continue;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (!preg_match("/^([A-Z][A-Z0-9_]*)=(.*)$/", $line, $match)) {
            continue;
        }
        $value = trim($match[2]);
        if (strlen($value) >= 2 && (($value[0] === "\"" && $value[-1] === "\"") || ($value[0] === chr(39) && $value[-1] === chr(39)))) {
            $value = substr($value, 1, -1);
        }
        $values[$match[1]] = $value;
    }
}
echo (string) ($values[$key] ?? "");
' "${runtime_config_files[@]}")"
[ -n "$configured_app_url" ] || fail "The server-held runtime configuration must explicitly set APP_URL."
if [ -z "$APP_URL" ]; then
    APP_URL="$configured_app_url"
else
    [ "$APP_URL" = "$configured_app_url" ] \
        || fail "Deployment APP_URL does not match the server-held runtime APP_URL."
fi

if [ -n "$DEPLOY_BRANCH" ]; then
    [[ "$DEPLOY_BRANCH" =~ ^[A-Za-z0-9._/-]+$ ]] || fail "DEPLOY_BRANCH contains unsupported characters."
    if [ "$DEPLOY_BRANCH" != "$expected_branch" ] && [ "$ALLOW_NONSTANDARD_BRANCH" != "1" ]; then
        fail "$DEPLOY_ENVIRONMENT deploys must use $expected_branch."
    fi
fi
if [ -n "$DEPLOY_SHA" ]; then
    [[ "$DEPLOY_SHA" =~ ^[0-9a-f]{40}$ ]] || fail "DEPLOY_SHA must be a full lowercase commit SHA."
    [ -n "$DEPLOY_BRANCH" ] || fail "DEPLOY_BRANCH is required with DEPLOY_SHA."
fi

if [ -n "$APP_URL" ]; then
    [[ "$APP_URL" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?$ ]] || fail "APP_URL must be one HTTPS origin."
else
    [ "$SKIP_FRONTEND" = "1" ] || fail "APP_URL is required when installing the frontend."
fi

if [ "$DEPLOY_ENVIRONMENT" = "staging" ]; then
    log "Validating staging isolation before backup"
    php -r '
$values = [];
foreach (array_slice($argv, 1) as $path) {
    if (str_ends_with($path, ".php")) {
        $loaded = require $path;
        is_array($loaded) || exit(2);
        $values = array_replace($values, $loaded);
        continue;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (!preg_match("/^(APP_ENV|APP_DEBUG|ADSENSE_ENABLED|MAILER_DSN|STAGING_ISOLATION_CONFIRMED|STAGING_MAIL_SAFETY_CONFIRMED)=(.*)$/", $line, $match)) {
            continue;
        }
        $value = trim($match[2]);
        if (strlen($value) >= 2 && (($value[0] === "\"" && $value[-1] === "\"") || ($value[0] === chr(39) && $value[-1] === chr(39)))) {
            $value = substr($value, 1, -1);
        }
        $values[$match[1]] = $value;
    }
}
$truthy = static fn (mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOL);
$fail = static function (string $message): never { fwrite(STDERR, $message."\n"); exit(1); };
($values["APP_ENV"] ?? "") === "prod" || $fail("Staging dotenv must explicitly set APP_ENV=prod.");
!$truthy($values["APP_DEBUG"] ?? true) || $fail("Staging dotenv must explicitly set APP_DEBUG=0.");
array_key_exists("ADSENSE_ENABLED", $values) && !$truthy($values["ADSENSE_ENABLED"]) || $fail("Staging dotenv must explicitly set ADSENSE_ENABLED=false.");
($values["STAGING_ISOLATION_CONFIRMED"] ?? "") === "1" || $fail("STAGING_ISOLATION_CONFIRMED=1 is required after isolation review.");
$mailer = (string) ($values["MAILER_DSN"] ?? "");
str_starts_with($mailer, "null://") || ($values["STAGING_MAIL_SAFETY_CONFIRMED"] ?? "") === "1" || $fail("Staging mail must use null:// or carry the reviewed safety confirmation.");
' "${runtime_config_files[@]}"
fi

is_managed_frontend_status() {
    local path="${1:3}"
    case "$path" in
        .panel-page-flip-environment|backend/public/.htaccess|backend/public/index.html|backend/public/robots.txt|backend/public/sitemap.xml|\
        backend/public/apple-touch-icon.png|backend/public/favicon-*.png|backend/public/favicon.ico|\
        backend/public/placeholder.svg|backend/public/tools|backend/public/tools/*)
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

unexpected_status=""
while IFS= read -r status_line; do
    [ -n "$status_line" ] || continue
    if ! is_managed_frontend_status "$status_line"; then
        unexpected_status+="${status_line}"$'\n'
    fi
done < <(git -C "$APP_DIR" status --porcelain --untracked-files=all)
[ -z "$unexpected_status" ] || {
    printf '%s' "$unexpected_status" >&2
    fail "The application checkout has uncommitted source changes."
}

if [ -n "$PREBUILT_FRONTEND_DIR" ]; then
    [ "$SKIP_FRONTEND" != "1" ] || fail "PREBUILT_FRONTEND_DIR and SKIP_FRONTEND=1 are mutually exclusive."
    [ -f "$PREBUILT_FRONTEND_DIR/deployment-commit.txt" ] || fail "Prebuilt frontend has no deployment provenance."
    artifact_sha="$(sed -n 's/^commit=//p' "$PREBUILT_FRONTEND_DIR/deployment-commit.txt")"
    artifact_url="$(sed -n 's/^app_url=//p' "$PREBUILT_FRONTEND_DIR/deployment-commit.txt")"
    [ "$artifact_sha" = "$DEPLOY_SHA" ] || fail "Frontend artifact SHA does not match DEPLOY_SHA."
    [ "$artifact_url" = "$APP_URL" ] || fail "Frontend artifact APP_URL does not match this environment."
    FRONTEND_SOURCE_DIR="$PREBUILT_FRONTEND_DIR" \
    PUBLIC_DIR="$APP_DIR/backend/public" \
    DEPLOY_STATE_DIR="$APP_DIR/backend/var/deploy" \
    DEPLOY_ENVIRONMENT="$DEPLOY_ENVIRONMENT" \
        bash -n "$SCRIPT_DIR/install-frontend.sh"
elif [ "$SKIP_FRONTEND" != "1" ]; then
    command -v npm >/dev/null 2>&1 || fail "npm is required when no prebuilt frontend is supplied."
fi

if [ -n "$DEPLOY_BRANCH" ]; then
    git -C "$APP_DIR" remote get-url origin >/dev/null \
        || fail "The checkout has no usable origin remote."
    remote_branch_sha="$(git -C "$APP_DIR" ls-remote --exit-code origin "refs/heads/$DEPLOY_BRANCH" | awk 'NR == 1 { print $1 }')" \
        || fail "The expected remote branch is not reachable."
    [[ "$remote_branch_sha" =~ ^[0-9a-f]{40}$ ]] || fail "The remote branch did not resolve to one commit."
    if [ -n "$DEPLOY_SHA" ] && [ "$DEPLOY_SHA" != "$remote_branch_sha" ]; then
        fail "The validated SHA has been superseded by a newer branch head; the newer workflow must deploy instead."
    fi
fi

[ -n "$BACKUP_COMMAND" ] || BACKUP_COMMAND="$APP_DIR/scripts/server/backup-comics.sh"
log "Running pre-deploy backup"
if [[ "$BACKUP_COMMAND" =~ ^/[A-Za-z0-9._/-]+$ ]] && [ -x "$BACKUP_COMMAND" ]; then
    APP_DIR="$APP_DIR" "$BACKUP_COMMAND"
else
    APP_DIR="$APP_DIR" bash -c "$BACKUP_COMMAND"
fi
log "Pre-deploy backup completed successfully"

if [ -n "$DEPLOY_BRANCH" ]; then
    log "Fetching $DEPLOY_BRANCH after the successful backup"
    git -C "$APP_DIR" fetch --no-tags origin \
        "+refs/heads/$DEPLOY_BRANCH:refs/remotes/origin/$DEPLOY_BRANCH"
    fetched_branch_sha="$(git -C "$APP_DIR" rev-parse "refs/remotes/origin/$DEPLOY_BRANCH")"
    target_sha="$DEPLOY_SHA"
    [ -n "$target_sha" ] || target_sha="$fetched_branch_sha"
    [ "$target_sha" = "$fetched_branch_sha" ] \
        || fail "The validated SHA was superseded while the backup ran; live code remains unchanged."
    git -C "$APP_DIR" cat-file -e "${target_sha}^{commit}" \
        || fail "Target commit does not exist after fetch."
    git -C "$APP_DIR" merge-base --is-ancestor "$target_sha" "refs/remotes/origin/$DEPLOY_BRANCH" \
        || fail "Target commit does not belong to origin/$DEPLOY_BRANCH."

    preserved_htaccess=""
    if [ -f "$APP_DIR/backend/public/.htaccess" ]; then
        preserved_htaccess="$SCRIPT_DIR/host-public.htaccess"
        cp "$APP_DIR/backend/public/.htaccess" "$preserved_htaccess"
    fi

    # The preflight rejected source changes and the backup is complete. Force is
    # limited to tracked files; ignored runtime config and uploads are untouched.
    git -C "$APP_DIR" checkout --detach --force "$target_sha"
    if [ -n "$preserved_htaccess" ]; then
        cp "$preserved_htaccess" "$APP_DIR/backend/public/.htaccess"
    fi
    deployed_head="$(git -C "$APP_DIR" rev-parse HEAD)"
    [ "$deployed_head" = "$target_sha" ] || fail "Checkout did not reach the requested commit."
    DEPLOY_SHA="$target_sha"
    log "Checked out validated commit $DEPLOY_SHA"
fi

if [ "$SKIP_FRONTEND" != "1" ]; then
    if [ -n "$PREBUILT_FRONTEND_DIR" ]; then
        frontend_source="$PREBUILT_FRONTEND_DIR"
        log "Installing the GitHub-built frontend artifact"
    else
        log "Building frontend on the server for the manual fallback"
        cd "$APP_DIR/frontend"
        npm ci --no-audit --no-fund
        rm -rf dist
        APP_URL="$APP_URL" npm run build
        APP_URL="$APP_URL" npm run check:seo
        frontend_source="$APP_DIR/frontend/dist"
    fi

    FRONTEND_SOURCE_DIR="$frontend_source" \
    PUBLIC_DIR="$APP_DIR/backend/public" \
    DEPLOY_STATE_DIR="$APP_DIR/backend/var/deploy" \
    DEPLOY_ENVIRONMENT="$DEPLOY_ENVIRONMENT" \
        "$SCRIPT_DIR/install-frontend.sh"
else
    warn "Skipping frontend installation (SKIP_FRONTEND=1)."
fi

if [ "$SKIP_COMPOSER" != "1" ]; then
    log "composer install --no-dev"
    cd "$APP_DIR/backend"
    APP_ENV=prod APP_DEBUG=0 composer install \
        --no-dev --optimize-autoloader --classmap-authoritative \
        --no-interaction --no-progress
else
    warn "Skipping Composer installation (SKIP_COMPOSER=1)."
fi

cd "$APP_DIR/backend"
APP_ENV=prod APP_DEBUG=0 php bin/console about --env=prod --no-debug >/dev/null

if [ "$DEPLOY_ENVIRONMENT" = "staging" ]; then
    env -u APP_ENV -u APP_DEBUG -u APP_URL php -r '
require "vendor/autoload.php";
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(".env", "prod");
$truthy = static fn (mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOL);
if (($_SERVER["APP_ENV"] ?? "") !== "prod" || $truthy($_SERVER["APP_DEBUG"] ?? false)) {
    fwrite(STDERR, "Staging must run APP_ENV=prod and APP_DEBUG=0.\n");
    exit(1);
}
if ($truthy($_SERVER["ADSENSE_ENABLED"] ?? false)) {
    fwrite(STDERR, "Staging must keep ADSENSE_ENABLED=false.\n");
    exit(1);
}
if (($_SERVER["STAGING_ISOLATION_CONFIRMED"] ?? "") !== "1") {
    fwrite(STDERR, "STAGING_ISOLATION_CONFIRMED=1 is required after DB, uploads, and credentials are reviewed.\n");
    exit(1);
}
if (($_SERVER["APP_URL"] ?? "") !== $argv[1]) {
    fwrite(STDERR, "Resolved staging APP_URL does not match the deployment target.\n");
    exit(1);
}
$mailer = (string) ($_SERVER["MAILER_DSN"] ?? "");
if (!str_starts_with($mailer, "null://") && ($_SERVER["STAGING_MAIL_SAFETY_CONFIRMED"] ?? "") !== "1") {
    fwrite(STDERR, "Use a null mail transport or set STAGING_MAIL_SAFETY_CONFIRMED=1 after reviewing the sink.\n");
    exit(1);
}
' "$APP_URL"
fi

log "Checking the production-mode database connection"
APP_ENV=prod APP_DEBUG=0 php bin/console doctrine:query:sql 'SELECT 1' \
    --env=prod --no-interaction >/dev/null

log "doctrine:migrations:migrate"
APP_ENV=prod APP_DEBUG=0 php bin/console doctrine:migrations:migrate \
    --no-interaction --allow-no-migration --env=prod

log "Encrypting legacy Dropbox tokens and backfilling file sizes"
APP_ENV=prod APP_DEBUG=0 php bin/console app:migrate-dropbox-tokens --env=prod
APP_ENV=prod APP_DEBUG=0 php bin/console app:backfill-comic-file-size --env=prod

log "cache:clear + cache:warmup"
APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear --env=prod --no-debug
APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup --env=prod --no-debug

log "Fixing runtime permissions for $WEB_USER:$WEB_GROUP"
mkdir -p "$APP_DIR/backend/var/cache" "$APP_DIR/backend/var/log" \
    "$APP_DIR/backend/var/page-cache" "$APP_DIR/backend/var/deploy" \
    "$APP_DIR/backend/public/uploads"
if chown -R "$WEB_USER:$WEB_GROUP" "$APP_DIR/backend/var" "$APP_DIR/backend/public/uploads" 2>/dev/null; then
    :
elif command -v sudo >/dev/null 2>&1; then
    sudo chown -R "$WEB_USER:$WEB_GROUP" "$APP_DIR/backend/var" "$APP_DIR/backend/public/uploads"
else
    warn "Could not change runtime ownership; verify the O2Switch account-user permissions."
fi
chmod -R u+rwX,g+rwX "$APP_DIR/backend/var" "$APP_DIR/backend/public/uploads" 2>/dev/null \
    || warn "Could not update every runtime permission."

[ -f "$APP_DIR/backend/public/index.html" ] || fail "Frontend index.html is missing after deployment."
asset_path="$(grep -oE '/assets/[A-Za-z0-9._/-]+\.(js|css)' "$APP_DIR/backend/public/index.html" | head -n 1 || true)"
[ -n "$asset_path" ] || fail "Frontend index.html has no hashed asset reference."
[ -f "$APP_DIR/backend/public$asset_path" ] || fail "Frontend entry asset is missing after deployment."

if [ -n "$DEPLOY_SHA" ]; then
    actual_sha="$(git -C "$APP_DIR" rev-parse HEAD)"
    [ "$actual_sha" = "$DEPLOY_SHA" ] || fail "git rev-parse HEAD no longer matches DEPLOY_SHA."
    printf '%s\n' "$DEPLOY_SHA" > "$APP_DIR/backend/var/deploy/deployed-sha"
    log "Verified deployed Git SHA: $DEPLOY_SHA"
fi

if [ -n "$POST_DEPLOY_HOOK" ]; then
    log "Running the configured post-deploy hook"
    bash -c "$POST_DEPLOY_HOOK"
fi

log "Deploy finished at $(date -u +%FT%TZ)"
