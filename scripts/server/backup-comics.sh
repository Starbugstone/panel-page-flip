#!/usr/bin/env bash
# =============================================================================
# scripts/server/backup-comics.sh
# -----------------------------------------------------------------------------
# Pre-deploy / daily backup for production. Backs up:
#   1. MySQL/MariaDB dump (from Symfony's effective server-held DATABASE_URL)
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
command -v php >/dev/null 2>&1 || fail "php not found"

runtime_config_files=()
if [ -f "$APP_DIR/backend/.env.local.php" ]; then
    runtime_config_files+=("$APP_DIR/backend/.env.local.php")
else
    [ ! -f "$APP_DIR/backend/.env.local" ] || runtime_config_files+=("$APP_DIR/backend/.env.local")
    [ ! -f "$APP_DIR/backend/.env.prod.local" ] || runtime_config_files+=("$APP_DIR/backend/.env.prod.local")
fi
[ "${#runtime_config_files[@]}" -gt 0 ] \
    || fail "No server-held runtime configuration is available to resolve DATABASE_URL."

# Match Symfony's precedence: compiled dotenv is authoritative; otherwise the
# prod-local file overrides matching values from the general local file.
DATABASE_URL="$(php -r '
$values = [];
foreach (array_slice($argv, 1) as $path) {
    if (str_ends_with($path, ".php")) {
        $loaded = require $path;
        is_array($loaded) || exit(2);
        $values = array_replace($values, $loaded);
        continue;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (!preg_match("/^DATABASE_URL=(.*)$/", $line, $match)) {
            continue;
        }
        $value = trim($match[1]);
        if (strlen($value) >= 2 && (($value[0] === "\"" && $value[-1] === "\"") || ($value[0] === chr(39) && $value[-1] === chr(39)))) {
            $value = substr($value, 1, -1);
        }
        $values["DATABASE_URL"] = $value;
    }
}
echo (string) ($values["DATABASE_URL"] ?? "");
' "${runtime_config_files[@]}")"
[ -n "$DATABASE_URL" ] || fail "DATABASE_URL is missing from the effective runtime configuration."
case "$DATABASE_URL" in
    mysql://*) ;;
    *) fail "DATABASE_URL must use the mysql:// scheme for this backup helper." ;;
esac

# mysql://user:pass@host:port/db?params
DB_URL_NO_SCHEME="${DATABASE_URL#mysql://}"
case "$DB_URL_NO_SCHEME" in
    *@*) ;;
    *) fail "DATABASE_URL has no user/host separator." ;;
esac
DB_CREDS="${DB_URL_NO_SCHEME%%@*}"
DB_REST="${DB_URL_NO_SCHEME#*@}"
case "$DB_CREDS" in
    *:*) ;;
    *) fail "DATABASE_URL credentials must include a password separator." ;;
esac
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

[[ "$DB_USER" =~ ^[A-Za-z0-9_.-]+$ ]] || fail "DATABASE_URL contains an unsupported database user."
[[ "$DB_HOST" =~ ^[A-Za-z0-9.-]+$ ]] || fail "DATABASE_URL contains an unsupported database host."
[[ "$DB_PORT" =~ ^[0-9]+$ ]] && [ "$DB_PORT" -le 65535 ] \
    || fail "DATABASE_URL contains an invalid database port."
[[ "$DB_NAME" =~ ^[A-Za-z0-9_.-]+$ ]] || fail "DATABASE_URL contains an unsupported database name."

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
