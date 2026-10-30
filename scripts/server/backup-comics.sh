#!/usr/bin/env bash
# =============================================================================
# scripts/server/backup-comics.sh
# -----------------------------------------------------------------------------
# Pre-deploy / daily backup for production. Backs up:
#   1. MySQL/MariaDB dump, using the DATABASE_URL the application itself reads
#   2. a dated snapshot of backend/public/uploads/, so every retained dump has
#      the files that belong to it
#
# Exits non-zero if either step fails, and publishes neither unless both worked.
# Point SSH_BACKUP_COMMAND at this script (or a symlink under
# /usr/local/bin/backup-comics).
#
# Usage on the server:
#   APP_DIR=/var/www/comics ./scripts/server/backup-comics.sh
#
# Optional env:
#   APP_DIR          project root (default: parent of scripts/server)
#   BACKUP_ROOT      where dumps land (default: /var/backups/comics)
#   RETENTION_DAYS   how long to keep dumps and uploads snapshots (default: 30)
#   ALLOW_EMPTY_UPLOADS=1
#                    accept an empty uploads folder although the latest backup
#                    holds files. Only for a deliberate wipe of every upload.
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
BACKUP_ROOT="${BACKUP_ROOT:-/var/backups/comics}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
ALLOW_EMPTY_UPLOADS="${ALLOW_EMPTY_UPLOADS:-0}"
# rsync reads a relative --link-dest from the destination, not from here, and would
# only warn and copy everything.
case "$BACKUP_ROOT" in
    /*) ;;
    *) BACKUP_ROOT="$PWD/$BACKUP_ROOT" ;;
esac

log()  { printf "\033[1;36m[backup]\033[0m %s\n" "$*"; }
fail() { printf "\033[1;31m[backup]\033[0m %s\n" "$*" >&2; exit 1; }

[ -d "$APP_DIR/backend" ] || fail "APP_DIR=$APP_DIR does not contain backend/"
[[ "$RETENTION_DAYS" =~ ^[0-9]+$ ]] || fail "RETENTION_DAYS must be a whole number of days."
command -v mysqldump >/dev/null 2>&1 || fail "mysqldump not found"
command -v gzip >/dev/null 2>&1 || fail "gzip not found"

# The value on stdout never reaches a process argument: php is given the file's
# path and asked for the one key.
read_compiled_database_url() {
    command -v php >/dev/null 2>&1 || fail "php not found; it is needed to read $1"
    # Single quotes on purpose: this is PHP source, not a shell expansion.
    # shellcheck disable=SC2016
    php -r '$env = include $argv[1]; if (is_array($env) && is_string($env["DATABASE_URL"] ?? null)) { echo $env["DATABASE_URL"]; }' -- "$1"
}

# Same order as Symfony's Dotenv::bootEnv(): a real environment variable wins, a
# compiled .env.local.php replaces the dotenv files outright, and otherwise the
# last file loaded wins. The committed .env is left out deliberately: it holds a
# development default, and a host with nothing better has nothing to back up.
resolve_database_url() {
    local file line
    if [ -n "${DATABASE_URL:-}" ]; then
        DATABASE_URL_SOURCE="the environment"
        return 0
    fi
    if [ -f "$APP_DIR/backend/.env.local.php" ]; then
        DATABASE_URL_SOURCE="$APP_DIR/backend/.env.local.php"
        DATABASE_URL="$(read_compiled_database_url "$DATABASE_URL_SOURCE")" \
            || fail "Could not read $DATABASE_URL_SOURCE"
        return 0
    fi
    for file in .env.prod.local .env.prod .env.local; do
        [ -f "$APP_DIR/backend/$file" ] || continue
        line="$(grep -E '^DATABASE_URL=' "$APP_DIR/backend/$file" | tail -n1 || true)"
        [ -n "$line" ] || continue
        DATABASE_URL_SOURCE="$APP_DIR/backend/$file"
        DATABASE_URL="${line#DATABASE_URL=}"
        DATABASE_URL="${DATABASE_URL%$'\r'}"
        case "$DATABASE_URL" in
            \"*\") DATABASE_URL="${DATABASE_URL:1:-1}" ;;
            \'*\') DATABASE_URL="${DATABASE_URL:1:-1}" ;;
        esac
        return 0
    done
    fail "No DATABASE_URL in the environment, backend/.env.local.php, .env.prod.local, .env.prod or .env.local."
}

DATABASE_URL_SOURCE=""
resolve_database_url
[ -n "$DATABASE_URL" ] || fail "DATABASE_URL empty in $DATABASE_URL_SOURCE"

# mysql://user:pass@host:port/db?params
DB_URL_NO_SCHEME="${DATABASE_URL#mysql://}"
DB_CREDS="${DB_URL_NO_SCHEME%%@*}"
DB_REST="${DB_URL_NO_SCHEME#*@}"
DB_USER="${DB_CREDS%%:*}"
DB_PASS_RAW="${DB_CREDS#*:}"
DB_HOSTPORT="${DB_REST%%/*}"
DB_NAME_RAW="${DB_REST#*/}"
DB_NAME="${DB_NAME_RAW%%\?*}"
DB_HOST="${DB_HOSTPORT%%:*}"
DB_PORT=3306
case "$DB_HOSTPORT" in
    *:*) DB_PORT="${DB_HOSTPORT##*:}" ;;
esac

# URL-decode a few common encodings in passwords
urldecode() {
    local s="${1//+/ }"
    printf '%b' "${s//%/\\x}"
}
DB_PASS="$(urldecode "$DB_PASS_RAW")"

# Anything but a directory: an empty folder of empty folders is what a dropped
# mount or a wiped tree looks like, so subdirectories do not count as content.
holds_files() {
    [ -d "$1" ] && [ -n "$(find "$1/" ! -type d -print -quit 2>/dev/null)" ]
}

STAMP="$(date +%F_%H%M%S)"
UPLOADS_SRC="$APP_DIR/backend/public/uploads"
SNAPSHOT_ROOT="$BACKUP_ROOT/uploads-snapshots"
# What this script kept before it took snapshots: one mirror, overwritten on every
# run. Nothing writes to it now. It stays the comparison and hard-link base until
# the first snapshot exists, and removing it afterwards is the operator's call.
LEGACY_MIRROR="$BACKUP_ROOT/uploads"

latest_snapshot() {
    [ -d "$SNAPSHOT_ROOT" ] || return 0
    find "$SNAPSHOT_ROOT" -mindepth 1 -maxdepth 1 -type d -name '????-??-??_??????' | sort | tail -n 1
}

# Creating a missing folder here would turn a lost mount into a successful backup
# of nothing. Checked before the dump so that a refused run leaves nothing behind.
[ -d "$UPLOADS_SRC" ] || fail "Uploads folder is missing: $UPLOADS_SRC. Restore it or fix the mount. On a new installation create it first with mkdir -p."

PREVIOUS="$(latest_snapshot)"
if [ -z "$PREVIOUS" ] && [ -d "$LEGACY_MIRROR" ]; then
    PREVIOUS="$LEGACY_MIRROR"
fi
if [ -n "$PREVIOUS" ] && holds_files "$PREVIOUS" && ! holds_files "$UPLOADS_SRC"; then
    [ "$ALLOW_EMPTY_UPLOADS" = "1" ] \
        || fail "Uploads folder $UPLOADS_SRC is empty but the backup $PREVIOUS holds files. Set ALLOW_EMPTY_UPLOADS=1 only if every upload was removed on purpose."
    log "ALLOW_EMPTY_UPLOADS=1: accepting an empty uploads folder"
fi

mkdir -p "$BACKUP_ROOT/db" "$SNAPSHOT_ROOT"
DUMP="$BACKUP_ROOT/db/${STAMP}.sql.gz"
SNAPSHOT="$SNAPSHOT_ROOT/$STAMP"
if [ -e "$DUMP" ] || [ -e "$SNAPSHOT" ]; then
    fail "A backup named $STAMP already exists."
fi

# Both are built under a temporary name and published together at the end, so a
# failure can never leave a dump without its files, or the reverse.
DUMP_PARTIAL="$DUMP.partial"
SNAPSHOT_PARTIAL="$SNAPSHOT.partial"
remove_partials() { rm -rf "$DUMP_PARTIAL" "$SNAPSHOT_PARTIAL"; }
trap remove_partials EXIT

log "Dumping database '$DB_NAME' → $DUMP"
export MYSQL_PWD="$DB_PASS"
mysqldump \
    --single-transaction \
    --routines \
    --triggers \
    -h "$DB_HOST" \
    -P "$DB_PORT" \
    -u "$DB_USER" \
    "$DB_NAME" | gzip -c > "$DUMP_PARTIAL"
unset MYSQL_PWD

[ -s "$DUMP_PARTIAL" ] || fail "Database dump is empty: $DUMP_PARTIAL"

log "Snapshotting uploads → $SNAPSHOT"
mkdir -p "$SNAPSHOT_PARTIAL"
if command -v rsync >/dev/null 2>&1; then
    # Unchanged files become hard links into the previous snapshot, so a dated
    # copy costs only what changed since then.
    LINK_DEST=()
    [ -z "$PREVIOUS" ] || LINK_DEST=(--link-dest="$PREVIOUS")
    rsync -a "${LINK_DEST[@]}" "$UPLOADS_SRC/" "$SNAPSHOT_PARTIAL/"
else
    log "rsync not found: this snapshot is a full copy. Install rsync to share unchanged files between snapshots."
    cp -a "$UPLOADS_SRC/." "$SNAPSHOT_PARTIAL/"
fi
# Both copies give the folder the source's mtime, which can be months old. Pruning
# goes by age, so stamp it now or a new snapshot could be deleted straight away.
touch "$SNAPSHOT_PARTIAL"

mv "$SNAPSHOT_PARTIAL" "$SNAPSHOT"
mv "$DUMP_PARTIAL" "$DUMP"

log "Pruning dumps and uploads snapshots older than ${RETENTION_DAYS} days"
find "$BACKUP_ROOT/db" -maxdepth 1 -name '*.sql.gz' -mtime "+$RETENTION_DAYS" -delete 2>/dev/null || true
find "$SNAPSHOT_ROOT" -mindepth 1 -maxdepth 1 -type d -name '????-??-??_??????' -mtime "+$RETENTION_DAYS" -exec rm -rf {} + 2>/dev/null || true
# Left by a run that was killed outright, which skips the cleanup trap.
find "$BACKUP_ROOT/db" -maxdepth 1 -name '*.partial' -mtime +1 -delete 2>/dev/null || true
find "$SNAPSHOT_ROOT" -mindepth 1 -maxdepth 1 -type d -name '*.partial' -mtime +1 -exec rm -rf {} + 2>/dev/null || true

log "Backup OK (db=$DUMP, uploads=$SNAPSHOT)"
