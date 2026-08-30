#!/usr/bin/env bash
# =============================================================================
# scripts/deploy-ssh.sh
# -----------------------------------------------------------------------------
# Driver script that runs from your laptop. SSHes into the production server,
# runs `git pull`, then triggers scripts/server/server-deploy.sh.
#
# This is the standard deploy path when you have SSH + git access.
#
# Usage:
#   ./scripts/deploy-ssh.sh                # full deploy: git pull + composer + npm + migrate
#   ./scripts/deploy-ssh.sh --skip-frontend  # don't run npm build on server
#   ./scripts/deploy-ssh.sh --skip-composer  # don't run composer install
#   ./scripts/deploy-ssh.sh --no-git         # skip git pull (deploy current server checkout)
#   ./scripts/deploy-ssh.sh --branch=develop # pull a different branch this time
#   ./scripts/deploy-ssh.sh --rsync          # build locally, rsync release/ to server, then run migrate+cache:clear
#   ./scripts/deploy-ssh.sh --command="cmd"  # run an arbitrary remote command (debug helper)
#
# Reads scripts/.env.deploy for SSH_* values.
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$SCRIPT_DIR/.env.deploy"

log()  { printf "\033[1;36m[ssh-deploy]\033[0m %s\n" "$*"; }
warn() { printf "\033[1;33m[warn]\033[0m       %s\n" "$*"; }
fail() { printf "\033[1;31m[fail]\033[0m       %s\n" "$*" >&2; exit 1; }

# --- args (parse first so --help works without a credentials file) ---
DO_GIT=1
DO_FRONTEND=1
DO_COMPOSER=1
USE_RSYNC=0
CUSTOM_COMMAND=""
BRANCH_OVERRIDE=""

for arg in "$@"; do
    case "$arg" in
        --no-git)         DO_GIT=0 ;;
        --skip-frontend)  DO_FRONTEND=0 ;;
        --skip-composer)  DO_COMPOSER=0 ;;
        --rsync)          USE_RSYNC=1 ;;
        --branch=*)       BRANCH_OVERRIDE="${arg#--branch=}" ;;
        --command=*)      CUSTOM_COMMAND="${arg#--command=}" ;;
        -h|--help)
            sed -n '2,25p' "${BASH_SOURCE[0]}"
            exit 0
            ;;
        *) fail "Unknown argument: $arg" ;;
    esac
done

[ -f "$ENV_FILE" ] || fail "Missing $ENV_FILE — copy scripts/.env.deploy.example."

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

# --- required vars ---
for v in SSH_HOST SSH_USER SSH_REMOTE_PATH; do
    [ -n "${!v:-}" ] || fail "Missing $v in $ENV_FILE."
done
SSH_PORT="${SSH_PORT:-22}"
SSH_GIT_BRANCH="${SSH_GIT_BRANCH:-main}"
SSH_WEB_USER="${SSH_WEB_USER:-www-data}"
SSH_WEB_GROUP="${SSH_WEB_GROUP:-$SSH_WEB_USER}"
SSH_POST_DEPLOY_HOOK="${SSH_POST_DEPLOY_HOOK:-}"
SSH_BACKUP_COMMAND="${SSH_BACKUP_COMMAND:-}"
[ -n "$SSH_BACKUP_COMMAND" ] || fail "Missing SSH_BACKUP_COMMAND; upgrades require a database and uploads backup."

[ -n "$BRANCH_OVERRIDE" ] && SSH_GIT_BRANCH="$BRANCH_OVERRIDE"

# --- ssh wrapper ---
SSH_OPTS=(-p "$SSH_PORT" -o StrictHostKeyChecking=accept-new -o ServerAliveInterval=30)
[ -n "${SSH_KEY:-}" ] && SSH_OPTS+=(-i "$SSH_KEY")

ssh_target="$SSH_USER@$SSH_HOST"

run_remote() {
    local cmd="$1"
    log "ssh $ssh_target -- $(echo "$cmd" | head -c 80)..."
    ssh "${SSH_OPTS[@]}" "$ssh_target" "$cmd"
}

run_remote_script() {
    local script="$1"
    log "ssh $ssh_target -- bash <<EOF"
    ssh "${SSH_OPTS[@]}" "$ssh_target" "bash -s" <<< "$script"
}

# =============================================================================
# Custom command escape hatch
# =============================================================================
if [ -n "$CUSTOM_COMMAND" ]; then
    run_remote "cd '$SSH_REMOTE_PATH' && $CUSTOM_COMMAND"
    exit 0
fi

# =============================================================================
# Rsync mode: build locally, rsync release/, then run migrate+cache:clear remotely
# =============================================================================
if [ "$USE_RSYNC" = "1" ]; then
    log "running required production backup before upload"
    run_remote "$SSH_BACKUP_COMMAND"

    [ -d "$REPO_ROOT/release" ] || {
        log "release/ not found — building first"
        "$SCRIPT_DIR/build-release.sh"
    }
    log "rsyncing release/ to $ssh_target:$SSH_REMOTE_PATH"

    RSYNC_SSH="ssh -p $SSH_PORT"
    [ -n "${SSH_KEY:-}" ] && RSYNC_SSH="$RSYNC_SSH -i $SSH_KEY"

    # --delete-after would otherwise remove the host's own dotenv files, which
    # are the runtime configuration in the default server-local mode. Compiled
    # mode is the one case where .env.local.php belongs to the release rather
    # than to the server, so it is uploaded instead of protected.
    ENV_EXCLUDES=(--exclude='backend/.env.local' --exclude='backend/.env.prod.local')
    if [ "${DEPLOY_CONFIG_MODE:-server-local}" != "compiled" ]; then
        ENV_EXCLUDES+=(--exclude='backend/.env.local.php')
    fi

    rsync -azv --delete-after \
        --exclude='backend/public/uploads/' \
        --exclude='backend/public/uploads/*' \
        --exclude='backend/var/log/' \
        --exclude='backend/var/cache/' \
        "${ENV_EXCLUDES[@]}" \
        --exclude='.git/' \
        -e "$RSYNC_SSH" \
        "$REPO_ROOT/release/" \
        "$ssh_target:$SSH_REMOTE_PATH/"

    # Make sure the helper scripts are executable on the server.
    run_remote "chmod +x '$SSH_REMOTE_PATH/scripts/server/'*.sh 2>/dev/null || true"

    log "running migrate + cache:clear on the server"
    REMOTE_SCRIPT=$(cat <<EOF
set -e
cd "$SSH_REMOTE_PATH/backend"
APP_ENV=prod php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=prod
APP_ENV=prod php bin/console app:migrate-dropbox-tokens --env=prod
APP_ENV=prod php bin/console app:backfill-comic-file-size --env=prod
APP_ENV=prod php bin/console cache:clear --env=prod --no-debug
APP_ENV=prod php bin/console cache:warmup --env=prod --no-debug
[ -n "$SSH_POST_DEPLOY_HOOK" ] && eval "$SSH_POST_DEPLOY_HOOK" || true
EOF
)
    run_remote_script "$REMOTE_SCRIPT"
    log "Done."
    exit 0
fi

# =============================================================================
# Git mode (default): server-side git pull + server-deploy.sh
# =============================================================================

# Build the remote script that we'll exec inside one SSH session.
GIT_BLOCK=""
if [ "$DO_GIT" = "1" ]; then
    GIT_BLOCK=$(cat <<EOF
echo "[remote] git fetch + pull"
cd "$SSH_REMOTE_PATH"
git fetch --all --prune
git checkout "$SSH_GIT_BRANCH"
git pull --ff-only origin "$SSH_GIT_BRANCH"
echo "[remote] now at: \$(git log -1 --oneline)"
EOF
)
fi

SKIP_FRONTEND_VAR=$([ "$DO_FRONTEND" = "1" ] && echo 0 || echo 1)
SKIP_COMPOSER_VAR=$([ "$DO_COMPOSER" = "1" ] && echo 0 || echo 1)
SSH_BACKUP_COMMAND_QUOTED=$(printf '%q' "$SSH_BACKUP_COMMAND")

REMOTE_SCRIPT=$(cat <<EOF
set -euo pipefail

$GIT_BLOCK

# Make sure the deploy script is executable (in case .gitattributes lost it).
chmod +x "$SSH_REMOTE_PATH/scripts/server/"*.sh 2>/dev/null || true

# Run the server-side deploy with the right env.
APP_DIR="$SSH_REMOTE_PATH" \\
APP_URL="${PUBLIC_URL%/}" \\
WEB_USER="$SSH_WEB_USER" \\
WEB_GROUP="$SSH_WEB_GROUP" \\
SKIP_FRONTEND="$SKIP_FRONTEND_VAR" \\
SKIP_COMPOSER="$SKIP_COMPOSER_VAR" \\
POST_DEPLOY_HOOK="$SSH_POST_DEPLOY_HOOK" \\
BACKUP_COMMAND=$SSH_BACKUP_COMMAND_QUOTED \\
"$SSH_REMOTE_PATH/scripts/server/server-deploy.sh"
EOF
)

log "Connecting to $ssh_target:$SSH_PORT"
log "Remote path:   $SSH_REMOTE_PATH"
log "Branch:        $SSH_GIT_BRANCH"
log "Git pull:      $([ "$DO_GIT" = "1" ] && echo yes || echo no)"
log "Frontend:      $([ "$DO_FRONTEND" = "1" ] && echo build || echo skip)"
log "Composer:      $([ "$DO_COMPOSER" = "1" ] && echo install || echo skip)"

run_remote_script "$REMOTE_SCRIPT"

log "Deploy complete."
