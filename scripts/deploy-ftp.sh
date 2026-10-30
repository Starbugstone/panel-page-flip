#!/usr/bin/env bash
# =============================================================================
# deploy-ftp.sh
# -----------------------------------------------------------------------------
# Mirrors the ./release tree to a remote FTP/FTPS server using lftp inside Docker.
# Safe-mode by default: never deletes application/server state and never
# touches user-content folders. Two narrow exceptions: public/error.php, which no
# release ships, is always removed, and superseded content-hashed Vite chunks are
# removed once they have outlived ASSET_RETENTION_DAYS (see asset-retention.sh).
#
# Usage:
#   ./scripts/deploy-ftp.sh                  # full upload (safe mode, no delete)
#   ./scripts/deploy-ftp.sh --dry-run        # show what would change
#   ./scripts/deploy-ftp.sh --frontend-only  # only mirror release/backend/public/
#   ./scripts/deploy-ftp.sh --backend-only   # mirror everything except public/
#   ./scripts/deploy-ftp.sh --delete         # mirror with --delete (DANGEROUS)
#
# Reads scripts/.env.deploy for FTP_* values.
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$SCRIPT_DIR/.env.deploy"
RELEASE_DIR="$REPO_ROOT/release"

log()  { printf "\033[1;36m[deploy]\033[0m %s\n" "$*"; }
warn() { printf "\033[1;33m[warn]\033[0m   %s\n" "$*"; }
fail() { printf "\033[1;31m[fail]\033[0m   %s\n" "$*" >&2; exit 1; }

# --- args ---------------------------------------------------------------------
DRY_RUN=0
SCOPE="all"
ALLOW_DELETE=0
for arg in "$@"; do
    case "$arg" in
        --dry-run)        DRY_RUN=1 ;;
        --frontend-only)  SCOPE="frontend" ;;
        --backend-only)   SCOPE="backend" ;;
        --delete)         ALLOW_DELETE=1 ;;
        -h|--help)
            sed -n '2,20p' "${BASH_SOURCE[0]}"
            exit 0
            ;;
        *) fail "Unknown argument: $arg" ;;
    esac
done

# --- preflight ----------------------------------------------------------------
[ -d "$RELEASE_DIR" ] || fail "No release/ directory. Run ./scripts/build-release.sh first."
[ -f "$ENV_FILE" ]    || fail "Missing $ENV_FILE. Copy scripts/.env.deploy.example."

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

for v in FTP_HOST FTP_USER FTP_PASSWORD FTP_REMOTE_ROOT; do
    [ -n "${!v:-}" ] || fail "Missing $v in $ENV_FILE"
done

FTP_PROTOCOL="${FTP_PROTOCOL:-ftps}"
FTP_PORT="${FTP_PORT:-21}"
FTP_PARALLEL="${FTP_PARALLEL:-3}"
FTP_VERIFY_CERTIFICATE="${FTP_VERIFY_CERTIFICATE:-1}"

case "$FTP_PROTOCOL" in
    ftp|ftps|sftp) ;;
    *) fail "FTP_PROTOCOL must be one of: ftp, ftps, sftp" ;;
esac

case "$FTP_VERIFY_CERTIFICATE" in
    0) SSL_VERIFY_CERTIFICATE=no ;;
    1) SSL_VERIFY_CERTIFICATE=yes ;;
    *) fail "FTP_VERIFY_CERTIFICATE must be 0 or 1" ;;
esac

# FTP has no way to take a backup, so a person has to vouch for one. post-deploy.sh
# refuses to migrate without this, and by then the new code would already be live
# against the old schema. A frontend-only upload is the one run that needs no
# migration to follow it.
if [ "$DRY_RUN" != "1" ] && [ "$SCOPE" != "frontend" ] && [ "${BACKUP_CONFIRMED:-0}" != "1" ]; then
    fail "Set BACKUP_CONFIRMED=1 in $ENV_FILE only after verifying a current database and uploads backup. Nothing was uploaded."
fi

command -v docker >/dev/null 2>&1 || fail "docker is required."

# --- build the lftp script ---------------------------------------------------
# Excludes that protect production state at all costs.
COMMON_EXCLUDES=(
    --exclude-glob ".git*"
    --exclude-glob ".env.local"
    --exclude-glob ".env.prod.local"
    --exclude-glob ".env.dev"
    --exclude-glob ".env.test"
    --exclude-glob "*.log"
    --exclude-glob "node_modules/"
    # NEVER touch user uploads.
    --exclude-glob "public/uploads/"
    --exclude-glob "public/uploads/*"
    # Never overwrite a server-managed sqlite (if any).
    --exclude-glob "var/data_*.db"
    # Generated comic pages belong to the server, not the release. The empty
    # directory itself still ships, so a first deploy creates it; its contents
    # are left alone, which keeps a --delete run from throwing away a warm
    # cache the server would then have to rebuild a page at a time.
    --exclude-glob "var/page-cache/*"
    # Quarantined originals are the only copy left, and the ledger is how old
    # assets get their retention period.
    --exclude-glob "var/quarantine/"
    --exclude-glob "var/asset-retention.tsv"
)

# The host's compiled env is production state in the default server-local mode,
# where the release deliberately contains none — so protect it. Compiled mode is
# the opposite case: .env.local.php is the configuration the build just made,
# and excluding it would mirror a release that cannot boot.
if [ "${DEPLOY_CONFIG_MODE:-server-local}" != "compiled" ]; then
    COMMON_EXCLUDES+=(--exclude-glob ".env.local.php")
fi

DELETE_FLAG=""
if [ "$ALLOW_DELETE" = "1" ]; then
    warn "Mirror with --delete is enabled — files removed from release/ will be deleted on the server."
    DELETE_FLAG="--delete"
fi

DRY_FLAG=""
if [ "$DRY_RUN" = "1" ]; then
    DRY_FLAG="--dry-run"
    log "DRY-RUN mode — no files will be transferred."
fi

case "$SCOPE" in
    all)
        LOCAL_DIR="$RELEASE_DIR/"
        REMOTE_DIR="$FTP_REMOTE_ROOT"
        COMMON_EXCLUDES+=(--exclude-glob "public/assets/")
        ;;
    frontend)
        LOCAL_DIR="$RELEASE_DIR/backend/public/"
        REMOTE_DIR="$FTP_REMOTE_ROOT/backend/public"
        # Frontend-only also leaves index.php / _post-deploy.php / .htaccess in
        # sync because they live in the same dir.
        COMMON_EXCLUDES+=(--exclude-glob "assets/")
        ;;
    backend)
        LOCAL_DIR="$RELEASE_DIR/backend/"
        REMOTE_DIR="$FTP_REMOTE_ROOT/backend"
        # Frontend assets in backend/public/ ARE included; if you want to
        # exclude them, run with --backend-only and then --frontend-only
        # separately, with the right script ordering.
        COMMON_EXCLUDES+=(--exclude-glob "public/assets/")
        ;;
esac

LOCAL_DIR="${LOCAL_DIR%/}"
REMOTE_DIR="${REMOTE_DIR%/}"

log "Protocol:   $FTP_PROTOCOL"
# Loaded from the operator-owned deployment file above; ShellCheck cannot
# follow that dynamic source but the required-variable loop has validated it.
# shellcheck disable=SC2153
log "Host:       $FTP_HOST:$FTP_PORT"
log "User:       $FTP_USER"
log "Local:      $LOCAL_DIR"
log "Remote:     $REMOTE_DIR"
log "Parallel:   $FTP_PARALLEL"
log "Delete:     $([ "$ALLOW_DELETE" = "1" ] && echo "YES (DANGEROUS)" || echo "no (safe mode)")"
log "Dry-run:    $([ "$DRY_RUN" = "1" ] && echo "yes" || echo "no")"

# --- build lftp commands ------------------------------------------------------
# Note: lftp uses a single -e "..." block of commands.
LFTP_OPEN="open -u $FTP_USER,$FTP_PASSWORD -p $FTP_PORT $FTP_PROTOCOL://$FTP_HOST"

# Certificate verification is enabled by default. Operators must explicitly
# opt out in scripts/.env.deploy for a temporary self-signed endpoint.
LFTP_SETTINGS=$(cat <<EOF
set ssl:verify-certificate ${SSL_VERIFY_CERTIFICATE}
set ftp:ssl-protect-data true
set net:max-retries 5
set net:reconnect-interval-base 5
set net:timeout 30
set ftp:passive-mode true
set xfer:clobber on
EOF
)

EXCL_STR=""
for ex in "${COMMON_EXCLUDES[@]}"; do
    EXCL_STR+=" $ex"
done

LFTP_MIRROR="mirror --reverse --continue --parallel=${FTP_PARALLEL} --verbose ${DRY_FLAG} ${DELETE_FLAG} ${EXCL_STR} ${LOCAL_DIR}/ ${REMOTE_DIR}/"

# Assets go up in their own step, never with --delete, because pages already open
# keep asking for the old build's chunks. They also go first: the main mirror
# publishes the index.html that refers to them, and with cmd:fail-exit a failed
# asset upload stops it from doing so.
ASSET_UPLOAD_COMMAND=""
ASSET_LOCAL_DIR="$RELEASE_DIR/backend/public/assets"
ASSET_REMOTE_DIR="${FTP_REMOTE_ROOT%/}/backend/public/assets"
if [ -d "$ASSET_LOCAL_DIR" ]; then
    ASSET_UPLOAD_COMMAND="mirror --reverse --continue --parallel=${FTP_PARALLEL} --verbose ${DRY_FLAG} ${ASSET_LOCAL_DIR}/ ${ASSET_REMOTE_DIR}/"
fi

# Excluded from the release, so only a stale copy on the server can still answer.
# The mirror is non-destructive and would never remove it on its own.
STALE_FILE_COMMAND=""
if [ "$DRY_RUN" != "1" ]; then
    STALE_FILE_COMMAND="rm -f ${FTP_REMOTE_ROOT%/}/backend/public/error.php"
fi

lftp_session() {
    docker run --rm -i \
        -v "$REPO_ROOT":/repo \
        -w /repo \
        --entrypoint sh \
        minidocks/lftp:latest \
        -c "
set -e
lftp <<'LFTPEOF'
${LFTP_SETTINGS}
${LFTP_OPEN}
$1
bye
LFTPEOF
"
}

# --- run inside docker --------------------------------------------------------
log "Starting lftp via docker (this may take a while)..."

lftp_session "set cmd:fail-exit yes
${ASSET_UPLOAD_COMMAND}
${LFTP_MIRROR}
${STALE_FILE_COMMAND}" \
    || fail "lftp mirror failed."

# Retention needs a complete build to compare against, so a --skip-frontend
# release leaves the live assets alone.
ASSET_LEDGER_REMOTE="${FTP_REMOTE_ROOT%/}/backend/var/asset-retention.tsv"
prune_superseded_assets() {
    local work listing
    work="$(mktemp -d)"
    # shellcheck disable=SC2064 # Expanded now: $work is local to this function.
    trap "rm -rf '$work'; rm -f '$RELEASE_DIR/.asset-retention.next'" RETURN
    ls -1A "$ASSET_LOCAL_DIR" > "$work/live"

    # A first deployment has no ledger, which lftp reports and carries on from.
    # cls comes last because its status is the one this session returns.
    listing="$(lftp_session "echo @@ledger
cat ${ASSET_LEDGER_REMOTE}
echo @@present
cd ${ASSET_REMOTE_DIR}
cls -1")" || return 1
    printf '%s\n' "$listing" | sed -n '/^@@ledger$/,/^@@present$/p' | sed '/^@@/d' > "$work/ledger"
    printf '%s\n' "$listing" | sed -n '/^@@present$/,$p' | sed '/^@@/d' > "$work/present"

    bash "$SCRIPT_DIR/asset-retention.sh" "$work/live" "$work/present" "$work/ledger" "$work/ledger.next" "$work/expired" || return 1

    local commands="" name
    while IFS= read -r name; do
        commands+="rm -f ${ASSET_REMOTE_DIR}/${name}"$'\n'
    done < "$work/expired"

    # Inside the repository because that is what the container has mounted.
    cp "$work/ledger.next" "$RELEASE_DIR/.asset-retention.next"
    lftp_session "${commands}put /repo/release/.asset-retention.next -o ${ASSET_LEDGER_REMOTE}" || return 1
    log "Superseded assets deleted after ${ASSET_RETENTION_DAYS:-14} days: $(wc -l < "$work/expired")"
}

if [ "$DRY_RUN" = "1" ]; then
    log "Dry run: superseded assets are left alone."
elif [ -d "$ASSET_LOCAL_DIR" ] && [ -f "$RELEASE_DIR/backend/public/index.html" ]; then
    # Tidying up must not fail a deployment that has already gone out. Nothing is
    # lost by trying again next time.
    prune_superseded_assets || warn "Could not prune superseded assets; they stay on the server until the next deploy."
else
    warn "Superseded asset cleanup skipped (no built frontend assets/index.html in release)."
fi

log "Upload complete."
log "Run:   ./scripts/post-deploy.sh   to apply migrations and clear cache."
