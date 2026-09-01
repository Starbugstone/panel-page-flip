#!/usr/bin/env bash
# Write this checkout's .env: a Compose project name, a port block and a UID/GID
# that belong to this checkout and no other.
#
# Run it once per checkout, before the first `docker compose` command. It is
# idempotent: checkout-derived values are refreshed, while configuration you
# chose yourself is preserved. Re-running it after a pull also fills in keys
# that .env.example has gained.
#
# Why this exists: .env used to be tracked, so every git worktree inherited
# COMPOSE_PROJECT_NAME=cbz_reader and ports 8080/8081/3001/1025/8025. Compose
# keys containers by project name, so all of them resolved to one set of
# containers. The containers keep the bind mounts they were created with, so a
# worktree that started the stack first left the main repo running `php
# bin/phpunit` against the worktree's source. The failures that produced look
# like anything except what they are.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

TEMPLATE="$REPO_ROOT/.env.example"
TARGET="$REPO_ROOT/.env"

[ -f "$TEMPLATE" ] || { echo "Missing $TEMPLATE" >&2; exit 1; }

# A linked worktree has a .git file pointing into the primary repo's
# .git/worktrees; the primary repo has a .git directory. The primary keeps the
# historical project name and ports so existing docs, bookmarks and muscle
# memory stay true, and only worktrees are moved out of the way.
if [ -d "$REPO_ROOT/.git" ]; then
  IS_PRIMARY=1
else
  IS_PRIMARY=0
fi

slug() {
  # Compose project names must be lowercase alphanumeric, underscore or hyphen.
  printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9_-]/_/g'
}

# Deterministic per-path offset: the same checkout gets the same ports every
# time, so a container that is already running is found rather than duplicated.
PATH_HASH="$(printf '%s' "$REPO_ROOT" | sha1sum | cut -c1-8)"

port_free() {
  local port="$1"
  # ss covers host listeners; docker covers published ports on other projects
  # that are stopped but will rebind when started.
  if command -v ss >/dev/null 2>&1 && ss -ltn "sport = :$port" 2>/dev/null | grep -q LISTEN; then
    return 1
  fi
  if docker ps -a --format '{{.Ports}}' 2>/dev/null | grep -qE "(^|[^0-9:])(0\.0\.0\.0|::|127\.0\.0\.1):$port->"; then
    return 1
  fi
  return 0
}

# Ports already written into our own .env stay ours even though they are in use.
OWN_PORTS=""
if [ -f "$TARGET" ]; then
  OWN_PORTS="$(grep -oE '^[A-Z_]*PORT=[0-9]+' "$TARGET" | cut -d= -f2 | tr '\n' ' ')"
fi

next_port() {
  local candidate="$1"
  local limit=$((candidate + 400))
  while [ "$candidate" -lt "$limit" ]; do
    case " $OWN_PORTS " in *" $candidate "*) printf '%s' "$candidate"; return 0 ;; esac
    if port_free "$candidate"; then
      printf '%s' "$candidate"
      return 0
    fi
    candidate=$((candidate + 1))
  done
  echo "No free port near $1" >&2
  exit 1
}

if [ "$IS_PRIMARY" -eq 1 ]; then
  PROJECT="cbz_reader"
  NGINX_PORT=8080
  ADMINER_PORT=8081
  FRONTEND_DEV_PORT=3001
  MAILPIT_SMTP_PORT=1025
  MAILPIT_UI_PORT=8025
else
  PROJECT="cbz_reader_$(slug "$(basename "$REPO_ROOT")")_${PATH_HASH:0:4}"
  # 0-63 slots of 10 ports, keeping every service in a recognisable band.
  OFFSET=$(( 16#${PATH_HASH:0:2} % 64 * 10 + 10 ))
  NGINX_PORT="$(next_port $((8080 + OFFSET)))"
  ADMINER_PORT="$(next_port $((8081 + OFFSET)))"
  FRONTEND_DEV_PORT="$(next_port $((3001 + OFFSET)))"
  MAILPIT_SMTP_PORT="$(next_port $((1025 + OFFSET)))"
  MAILPIT_UI_PORT="$(next_port $((8025 + OFFSET)))"
fi

HOST_UID="$(id -u)"
HOST_GID="$(id -g)"
APP_URL="http://localhost:${NGINX_PORT}"

# Create the runtime directories on the host first. backend/var/ is gitignored,
# so a fresh clone or worktree does not have it — and when a bind mount's target
# is missing, the Docker daemon creates it, as root. The php_cache volume mounts
# at backend/var/cache, so starting the stack in a fresh checkout would leave a
# root-owned backend/var that the container, running as this user, then cannot
# create var/log or var/page-cache inside. Making them here means the daemon
# never has to.
mkdir -p \
  "$REPO_ROOT/backend/var/cache" \
  "$REPO_ROOT/backend/var/log" \
  "$REPO_ROOT/backend/var/page-cache" \
  "$REPO_ROOT/backend/var/quarantine/comics" \
  "$REPO_ROOT/backend/public/uploads"

# Derived values are authoritative; everything else in .env.example is a default
# the developer may already have overridden in .env.
declare -A DERIVED=(
  [COMPOSE_PROJECT_NAME]="$PROJECT"
  [HOST_UID]="$HOST_UID"
  [HOST_GID]="$HOST_GID"
  [NGINX_PORT]="$NGINX_PORT"
  [ADMINER_PORT]="$ADMINER_PORT"
  [FRONTEND_DEV_PORT]="$FRONTEND_DEV_PORT"
  [MAILPIT_SMTP_PORT]="$MAILPIT_SMTP_PORT"
  [MAILPIT_UI_PORT]="$MAILPIT_UI_PORT"
  [APP_URL]="$APP_URL"
)

existing_value() {
  [ -f "$TARGET" ] || return 1
  local line
  line="$(grep -m1 "^$1=" "$TARGET")" || return 1
  printf '%s' "${line#*=}"
}

TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT

while IFS= read -r line; do
  if [[ "$line" =~ ^([A-Za-z_][A-Za-z0-9_]*)= ]]; then
    key="${BASH_REMATCH[1]}"
    if [ -n "${DERIVED[$key]+set}" ]; then
      printf '%s=%s\n' "$key" "${DERIVED[$key]}" >> "$TMP"
    elif value="$(existing_value "$key")"; then
      printf '%s=%s\n' "$key" "$value" >> "$TMP"
    else
      printf '%s\n' "$line" >> "$TMP"
    fi
  else
    printf '%s\n' "$line" >> "$TMP"
  fi
done < "$TEMPLATE"

mv "$TMP" "$TARGET"
trap - EXIT

printf 'Wrote %s\n\n' "$TARGET"
printf '  project    %s%s\n' "$PROJECT" "$([ "$IS_PRIMARY" -eq 1 ] && echo '' || echo '  (worktree)')"
printf '  app        http://localhost:%s\n' "$NGINX_PORT"
printf '  adminer    http://localhost:%s\n' "$ADMINER_PORT"
printf '  mailpit    http://localhost:%s\n' "$MAILPIT_UI_PORT"
printf '  vite       http://localhost:%s\n' "$FRONTEND_DEV_PORT"
printf '  runs as    %s:%s\n' "$HOST_UID" "$HOST_GID"
