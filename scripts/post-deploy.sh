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
#   ./scripts/post-deploy.sh                # health -> migrate -> cache-clear (HTTP)
#   ./scripts/post-deploy.sh --action health
#   ./scripts/post-deploy.sh --action migrate
#   ./scripts/post-deploy.sh --action cache-clear
#   ./scripts/post-deploy.sh --ssh          # use SSH instead of HTTP
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
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
    ACTIONS=(health migrate cache-clear)
fi

# Validate every action.
for a in "${ACTIONS[@]}"; do
    case "$a" in
        health|migrate|cache-clear|about) ;;
        *) fail "Unknown action: $a (allowed: health migrate cache-clear about)" ;;
    esac
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
    if [ "$USE_SSH" = "1" ]; then
        ssh_call "$a"
    else
        http_call "$a"
    fi
done

log "Post-deploy finished."
