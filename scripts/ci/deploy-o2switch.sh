#!/usr/bin/env bash
# Upload one validated frontend artifact outside the live checkout, then invoke
# the uploaded release transaction over host-key-verified SSH.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

: "${DEPLOY_ENVIRONMENT:?DEPLOY_ENVIRONMENT is required}"
: "${DEPLOY_BRANCH:?DEPLOY_BRANCH is required}"
: "${DEPLOY_SHA:?DEPLOY_SHA is required}"
: "${APP_URL:?APP_URL is required}"
: "${PREBUILT_FRONTEND_DIR:?PREBUILT_FRONTEND_DIR is required}"
: "${O2_REMOTE_PATH:?O2_REMOTE_PATH is required}"
: "${O2_SSH_HOST:?O2_SSH_HOST is required}"
: "${O2_SSH_USER:?O2_SSH_USER is required}"
: "${O2_SSH_KEY_FILE:?O2_SSH_KEY_FILE is required}"
: "${O2_KNOWN_HOSTS_FILE:?O2_KNOWN_HOSTS_FILE is required}"

O2_SSH_PORT="${O2_SSH_PORT:-22}"
O2_WEB_USER="${O2_WEB_USER:-$O2_SSH_USER}"
O2_WEB_GROUP="${O2_WEB_GROUP:-$O2_WEB_USER}"
O2_BACKUP_COMMAND="${O2_BACKUP_COMMAND:-$O2_REMOTE_PATH/scripts/server/backup-comics.sh}"
O2_POST_DEPLOY_HOOK="${O2_POST_DEPLOY_HOOK:-}"
run_id="${GITHUB_RUN_ID:-manual}"

log()  { printf '\033[1;36m[o2-deploy]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[o2-deploy]\033[0m %s\n' "$*" >&2; }
fail() { printf '\033[1;31m[o2-deploy]\033[0m %s\n' "$*" >&2; exit 1; }

case "$DEPLOY_ENVIRONMENT:$DEPLOY_BRANCH" in
    production:main|staging:develop) ;;
    *) fail "Only main -> production and develop -> staging are allowed." ;;
esac
[[ "$DEPLOY_SHA" =~ ^[0-9a-f]{40}$ ]] || fail "DEPLOY_SHA must be a full lowercase commit SHA."
[[ "$APP_URL" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?$ ]] || fail "APP_URL must be one HTTPS origin."
[[ "$O2_REMOTE_PATH" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail "O2_REMOTE_PATH must be a simple absolute path."
case "/$O2_REMOTE_PATH/" in
    *'/../'*) fail "O2_REMOTE_PATH must not contain .. path components." ;;
esac
[ "$O2_REMOTE_PATH" != "/" ] || fail "O2_REMOTE_PATH must not be the filesystem root."
[[ "$O2_SSH_HOST" =~ ^[A-Za-z0-9.-]+$ ]] || fail "O2_SSH_HOST must be a hostname."
[[ "$O2_SSH_USER" =~ ^[A-Za-z0-9._-]+$ ]] || fail "O2_SSH_USER contains unsupported characters."
[[ "$O2_SSH_PORT" =~ ^[0-9]+$ ]] || fail "O2_SSH_PORT must be numeric."
[[ "$O2_BACKUP_COMMAND" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail "O2_BACKUP_COMMAND must be one absolute executable path."
[[ "$run_id" =~ ^[A-Za-z0-9._-]+$ ]] || fail "GITHUB_RUN_ID contains unsupported characters."

[ -r "$O2_SSH_KEY_FILE" ] || fail "Dedicated SSH private key file is unreadable."
[ -s "$O2_KNOWN_HOSTS_FILE" ] || fail "Pinned SSH known_hosts file is empty."
[ -f "$PREBUILT_FRONTEND_DIR/index.html" ] || fail "Downloaded frontend artifact has no index.html."
[ -f "$PREBUILT_FRONTEND_DIR/deployment-commit.txt" ] || fail "Downloaded frontend artifact has no provenance file."
if find "$PREBUILT_FRONTEND_DIR" -type l -print -quit | grep -q .; then
    fail "Downloaded frontend artifact contains a symbolic link."
fi
artifact_file_count="$(find "$PREBUILT_FRONTEND_DIR" -type f | wc -l)"
artifact_kib="$(du -sk "$PREBUILT_FRONTEND_DIR" | awk '{print $1}')"
[ "$artifact_file_count" -le 10000 ] || fail "Downloaded frontend artifact contains too many files."
[ "$artifact_kib" -le 1048576 ] || fail "Downloaded frontend artifact exceeds 1 GiB."
[ "$(sed -n 's/^commit=//p' "$PREBUILT_FRONTEND_DIR/deployment-commit.txt")" = "$DEPLOY_SHA" ] \
    || fail "Downloaded frontend artifact does not match DEPLOY_SHA."
[ "$(sed -n 's/^app_url=//p' "$PREBUILT_FRONTEND_DIR/deployment-commit.txt")" = "$APP_URL" ] \
    || fail "Downloaded frontend artifact does not match APP_URL."

remote_parent="${O2_REMOTE_PATH%/*}"
[ -n "$remote_parent" ] || remote_parent=/
remote_release="$remote_parent/.panel-page-flip-deployments/$DEPLOY_ENVIRONMENT/${DEPLOY_SHA}-${run_id}"
ssh_target="$O2_SSH_USER@$O2_SSH_HOST"
ssh_options=(
    -p "$O2_SSH_PORT"
    -i "$O2_SSH_KEY_FILE"
    -o BatchMode=yes
    -o IdentitiesOnly=yes
    -o StrictHostKeyChecking=yes
    -o "UserKnownHostsFile=$O2_KNOWN_HOSTS_FILE"
    -o ServerAliveInterval=30
    -o ServerAliveCountMax=10
)

uploaded=0
cleanup_remote_release() {
    local result=0
    [ "$uploaded" = "1" ] || return 0
    if ! ssh "${ssh_options[@]}" "$ssh_target" "bash -s -- '$remote_release'" <<'REMOTE_CLEANUP'
set -euo pipefail
release_dir="$1"
case "$release_dir" in
    */.panel-page-flip-deployments/staging/*|*/.panel-page-flip-deployments/production/*) ;;
    *) printf 'Refusing unsafe remote cleanup path.\n' >&2; exit 1 ;;
esac
rm -rf -- "$release_dir"
REMOTE_CLEANUP
    then
        warn "Temporary remote artifact cleanup failed: $remote_release"
        result=1
    fi
    return "$result"
}

finalize() {
    local deployment_status=$?
    trap - EXIT
    if cleanup_remote_release; then
        exit "$deployment_status"
    fi
    [ "$deployment_status" -ne 0 ] || deployment_status=1
    exit "$deployment_status"
}
trap finalize EXIT

log "Preparing SHA-specific remote artifact directory"
ssh "${ssh_options[@]}" "$ssh_target" "bash -s -- '$remote_release'" <<'REMOTE_PREPARE'
set -euo pipefail
release_dir="$1"
case "$release_dir" in
    */.panel-page-flip-deployments/staging/*|*/.panel-page-flip-deployments/production/*) ;;
    *) printf 'Refusing unsafe remote release path.\n' >&2; exit 1 ;;
esac
umask 077
mkdir -p "$release_dir/frontend"
REMOTE_PREPARE
uploaded=1

tar -C "$PREBUILT_FRONTEND_DIR" -czf - . \
    | ssh "${ssh_options[@]}" "$ssh_target" "tar -xzf - -C '$remote_release/frontend'"
tar -C "$REPO_ROOT/scripts/server" -czf - server-deploy.sh install-frontend.sh \
    | ssh "${ssh_options[@]}" "$ssh_target" "tar -xzf - -C '$remote_release'"

printf -v remote_command \
    'APP_DIR=%q DEPLOY_ENVIRONMENT=%q DEPLOY_BRANCH=%q DEPLOY_SHA=%q APP_URL=%q PREBUILT_FRONTEND_DIR=%q WEB_USER=%q WEB_GROUP=%q BACKUP_COMMAND=%q POST_DEPLOY_HOOK=%q bash %q' \
    "$O2_REMOTE_PATH" "$DEPLOY_ENVIRONMENT" "$DEPLOY_BRANCH" "$DEPLOY_SHA" "$APP_URL" \
    "$remote_release/frontend" "$O2_WEB_USER" "$O2_WEB_GROUP" "$O2_BACKUP_COMMAND" \
    "$O2_POST_DEPLOY_HOOK" "$remote_release/server-deploy.sh"

log "Starting backup-gated $DEPLOY_ENVIRONMENT transaction for $DEPLOY_SHA"
ssh "${ssh_options[@]}" "$ssh_target" "$remote_command"
log "$DEPLOY_ENVIRONMENT transaction completed for $DEPLOY_SHA"
