#!/usr/bin/env bash
# =============================================================================
# post-deploy.sh
# -----------------------------------------------------------------------------
# Runs the post-upload tasks on the production server. Two modes are supported:
#
# 1) HTTP mode (FTP-only hosts): hits the token-protected
#    public/_post-deploy.php endpoint shipped by build-release.sh.
#
# 2) SSH mode: skips _post-deploy.php and runs Symfony console directly over
#    SSH (set SSH_HOST in scripts/.env.deploy and use --ssh).
#
# Usage:
#   ./scripts/post-deploy.sh                # health -> migrate -> cache-clear -> smoke (HTTP)
#   ./scripts/post-deploy.sh --action health
#   ./scripts/post-deploy.sh --action migrate
#   ./scripts/post-deploy.sh --action cache-clear
#   ./scripts/post-deploy.sh --action smoke
#   ./scripts/post-deploy.sh --ssh          # use SSH instead of HTTP
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
RELEASE_DIR="$REPO_ROOT/release"
ENV_FILE="$SCRIPT_DIR/.env.deploy"

log()  { printf "\033[1;36m[post]\033[0m %s\n" "$*"; }
warn() { printf "\033[1;33m[warn]\033[0m %s\n" "$*"; }
fail() { printf "\033[1;31m[fail]\033[0m %s\n" "$*" >&2; exit 1; }

[ -f "$ENV_FILE" ] || fail "Missing $ENV_FILE."
set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

USE_SSH=0
ACTIONS=()

while [ $# -gt 0 ]; do
    case "$1" in
        --ssh)        USE_SSH=1; shift ;;
        --action)     ACTIONS+=("$2"); shift 2 ;;
        --action=*)   ACTIONS+=("${1#--action=}"); shift ;;
        -h|--help)
            sed -n '2,20p' "${BASH_SOURCE[0]}"
            exit 0
            ;;
        *)            ACTIONS+=("$1"); shift ;;
    esac
done

# Default sequence when no action requested.
if [ "${#ACTIONS[@]}" -eq 0 ]; then
    ACTIONS=(health migrate upgrade-data cache-clear smoke)
fi

# Validate every action.
for a in "${ACTIONS[@]}"; do
    case "$a" in
        health|migrate|upgrade-data|cache-clear|about|smoke) ;;
        *) fail "Unknown action: $a (allowed: health migrate upgrade-data cache-clear about smoke)" ;;
    esac
done

for a in "${ACTIONS[@]}"; do
    if [ "$a" = "migrate" ] && [ "${BACKUP_CONFIRMED:-0}" != "1" ]; then
        fail "Set BACKUP_CONFIRMED=1 only after verifying a current database and uploads backup."
    fi
done

# =============================================================================
# HTTP mode
# =============================================================================
http_call() {
    local action="$1"
    local url="${PUBLIC_URL%/}/_post-deploy.php?action=${action}"
    local method="POST"
    [ "$action" = "health" ] && method="GET"

    log "HTTP $method $url"
    # Token is sent in header (cleaner than ?token= in access logs).
    local code
    code=$(curl -sS -o /tmp/post-deploy.out -w "%{http_code}" \
        -X "$method" \
        -H "X-Deploy-Token: ${POST_DEPLOY_TOKEN}" \
        "$url")
    cat /tmp/post-deploy.out
    if [ "$code" != "200" ]; then
        fail "HTTP $code from server. See output above."
    fi
}

# =============================================================================
# Public smoke test
# =============================================================================
smoke_call() {
    local base_url="${PUBLIC_URL%/}"
    local spa_out api_out expected_entry api_code
    spa_out="$(mktemp)"
    api_out="$(mktemp)"

    log "Smoke GET ${base_url}/admin"
    if ! curl -fsS "${base_url}/admin" -o "$spa_out"; then
        rm -f "$spa_out" "$api_out"
        fail "SPA smoke request failed for /admin."
    fi
    if ! grep -q "Panel Page Flip" "$spa_out"; then
        rm -f "$spa_out" "$api_out"
        fail "/admin did not return the Panel Page Flip SPA."
    fi

    # If the release exists locally, prove production is serving this build's
    # entry chunk rather than merely returning some older valid SPA shell.
    if [ -f "$RELEASE_DIR/backend/public/index.html" ]; then
        expected_entry="$(grep -oE 'src="/assets/[^"]+\.js"' "$RELEASE_DIR/backend/public/index.html" | head -n 1 | sed -E 's/^src="([^"]+)"$/\1/' || true)"
        [ -n "$expected_entry" ] || { rm -f "$spa_out" "$api_out"; fail "Could not find the Vite entry chunk in release index.html."; }
        if ! grep -Fq "$expected_entry" "$spa_out"; then
            rm -f "$spa_out" "$api_out"
            fail "Production /admin is not serving this release's entry chunk ($expected_entry)."
        fi
        log "Smoke GET ${base_url}${expected_entry}"
        if ! curl -fsS -o /dev/null "${base_url}${expected_entry}"; then
            rm -f "$spa_out" "$api_out"
            fail "Current Vite entry chunk is not fetchable from production."
        fi
    else
        warn "No local release/index.html; skipping exact entry-chunk comparison."
    fi

    log "Smoke POST ${base_url}/api/login"
    api_code="$(curl -sS -o "$api_out" -w "%{http_code}" \
        -X POST \
        -H "Content-Type: application/json" \
        --data '{}' \
        "${base_url}/api/login")"
    case "$api_code" in
        400|401) ;;
        *)
            cat "$api_out"
            rm -f "$spa_out" "$api_out"
            fail "/api/login smoke check returned HTTP $api_code (expected 400 or 401)."
            ;;
    esac
    if grep -qi '<!doctype html' "$api_out"; then
        rm -f "$spa_out" "$api_out"
        fail "/api/login returned SPA HTML; API routing is broken."
    fi

    rm -f "$spa_out" "$api_out"
    log "Smoke checks passed."
}

# =============================================================================
# SSH mode (optional, more reliable when available)
# =============================================================================
ssh_call() {
    local action="$1"
    : "${SSH_HOST:?SSH_HOST not set in .env.deploy}"
    : "${SSH_USER:?SSH_USER not set in .env.deploy}"
    local target="${SSH_USER}@${SSH_HOST}"
    local remote_path="${SSH_REMOTE_PATH:-${FTP_REMOTE_ROOT}/backend}"

    case "$action" in
        health)
            ssh "$target" "cd ${remote_path} && php -r 'echo PHP_VERSION;' && echo"
            ;;
        migrate)
            ssh "$target" "cd ${remote_path} && php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=prod"
            ;;
        upgrade-data)
            ssh "$target" "cd ${remote_path} && php bin/console app:migrate-dropbox-tokens --env=prod && php bin/console app:backfill-comic-file-size --env=prod"
            ;;
        cache-clear)
            ssh "$target" "cd ${remote_path} && php bin/console cache:clear --env=prod && php bin/console cache:warmup --env=prod"
            ;;
        *)
            fail "Unknown action: $action"
            ;;
    esac
}

# =============================================================================
# Run
# =============================================================================
for a in "${ACTIONS[@]}"; do
    log "===== ${a} ====="
    if [ "$a" = "smoke" ]; then
        smoke_call
    elif [ "$USE_SSH" = "1" ]; then
        ssh_call "$a"
    else
        http_call "$a"
    fi
done

log "Post-deploy finished."
