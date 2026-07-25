#!/usr/bin/env bash
# =============================================================================
# scripts/server/backup-comics.sh
# -----------------------------------------------------------------------------
# Pre-deploy / daily backup for production. Backs up:
#   1. MySQL/MariaDB dump (from DATABASE_URL in backend/.env.prod.local)
#   2. backend/public/uploads/
#
# Exits non-zero if either step fails. Point SSH_BACKUP_COMMAND at this script
# (or a symlink under /usr/local/bin/backup-comics).
#
# Usage on the server:
#   APP_DIR=/var/www/comics ./scripts/server/backup-comics.sh
#
# Optional env:
#   APP_DIR          project root (default: parent of scripts/server)
#   BACKUP_ROOT      where dumps land (default: /var/backups/comics)
#   RETENTION_DAYS   how long to keep *.sql.gz (default: 30)
# =============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-$(cd "$SCRIPT_DIR/../.." && pwd)}"
BACKUP_ROOT="${BACKUP_ROOT:-/var/backups/comics}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"

log()  { printf "\033[1;36m[backup]\033[0m %s\n" "$*"; }
fail() { printf "\033[1;31m[backup]\033[0m %s\n" "$*" >&2; exit 1; }

[ -d "$APP_DIR/backend" ] || fail "APP_DIR=$APP_DIR does not contain backend/"
command -v mysqldump >/dev/null 2>&1 || fail "mysqldump not found"
command -v gzip >/dev/null 2>&1 || fail "gzip not found"

ENV_FILE=""
if [ -f "$APP_DIR/backend/.env.prod.local" ]; then
    ENV_FILE="$APP_DIR/backend/.env.prod.local"
elif [ -f "$APP_DIR/backend/.env.local" ]; then
    ENV_FILE="$APP_DIR/backend/.env.local"
else
    fail "No backend/.env.prod.local (or .env.local) to read DATABASE_URL from."
fi

DATABASE_URL_LINE="$(grep -E '^DATABASE_URL=' "$ENV_FILE" | tail -n1 || true)"
[ -n "$DATABASE_URL_LINE" ] || fail "DATABASE_URL missing in $ENV_FILE"
DATABASE_URL="${DATABASE_URL_LINE#DATABASE_URL=}"
DATABASE_URL="${DATABASE_URL%$'\r'}"
case "$DATABASE_URL" in
    \"*\") DATABASE_URL="${DATABASE_URL:1:-1}" ;;
    \'*\') DATABASE_URL="${DATABASE_URL:1:-1}" ;;
esac
[ -n "$DATABASE_URL" ] || fail "DATABASE_URL empty in $ENV_FILE"

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

DAY="$(date +%F_%H%M%S)"
mkdir -p "$BACKUP_ROOT/db" "$BACKUP_ROOT/uploads"
DUMP="$BACKUP_ROOT/db/${DAY}.sql.gz"
UPLOADS_SRC="$APP_DIR/backend/public/uploads"
UPLOADS_DST="$BACKUP_ROOT/uploads"

log "Dumping database '$DB_NAME' → $DUMP"
export MYSQL_PWD="$DB_PASS"
mysqldump \
    --single-transaction \
    --routines \
    --triggers \
    -h "$DB_HOST" \
    -P "$DB_PORT" \
    -u "$DB_USER" \
    "$DB_NAME" | gzip -c > "$DUMP"
unset MYSQL_PWD

[ -s "$DUMP" ] || fail "Database dump is empty: $DUMP"

log "Syncing uploads → $UPLOADS_DST"
mkdir -p "$UPLOADS_SRC"
if command -v rsync >/dev/null 2>&1; then
    rsync -a --delete "$UPLOADS_SRC/" "$UPLOADS_DST/"
else
    rm -rf "${UPLOADS_DST}.tmp"
    mkdir -p "${UPLOADS_DST}.tmp"
    cp -a "$UPLOADS_SRC/." "${UPLOADS_DST}.tmp/"
    rm -rf "$UPLOADS_DST"
    mv "${UPLOADS_DST}.tmp" "$UPLOADS_DST"
fi

log "Pruning dumps older than ${RETENTION_DAYS} days"
find "$BACKUP_ROOT/db" -name '*.sql.gz' -mtime "+$RETENTION_DAYS" -delete 2>/dev/null || true

log "Backup OK (db=$DUMP, uploads=$UPLOADS_DST)"
