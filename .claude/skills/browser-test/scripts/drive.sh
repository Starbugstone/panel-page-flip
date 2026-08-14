#!/usr/bin/env bash
# Run a Playwright driver against the running stack.
#
#   drive.sh path/to/driver.mjs
#
# The Playwright image ships the browsers but not the npm package, so it is
# installed once into a cached directory. The container joins the app's network
# and addresses it as http://nginx.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
DRIVER="${1:-$REPO_ROOT/.claude/skills/browser-test/scripts/drive.mjs}"
IMAGE="mcr.microsoft.com/playwright:v1.56.0-noble"
CACHE="${PPF_PLAYWRIGHT_CACHE:-/tmp/ppf-playwright}"
OUT="$REPO_ROOT/var/browser-test"

export DOCKER_CONFIG="${DOCKER_CONFIG:-/tmp/ppf-dockercfg}"
mkdir -p "$DOCKER_CONFIG"
[ -f "$DOCKER_CONFIG/config.json" ] || printf '{}' > "$DOCKER_CONFIG/config.json"

[ -f "$DRIVER" ] || { echo "No such driver: $DRIVER" >&2; exit 1; }

NETWORK="$(docker inspect "$(docker compose --project-directory "$REPO_ROOT" ps -q nginx)" \
  --format '{{range $k,$v := .NetworkSettings.Networks}}{{$k}}{{end}}' 2>/dev/null || true)"
[ -n "$NETWORK" ] || { echo "The stack is not running. Run up.sh first." >&2; exit 1; }

mkdir -p "$CACHE" "$OUT"

if [ ! -d "$CACHE/node_modules/playwright" ]; then
  echo "==> Installing the playwright package (first run only)"
  docker run --rm -v "$CACHE":/pw -w /pw -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 "$IMAGE" \
    sh -c "npm init -y >/dev/null 2>&1 && npm i playwright@1.56.0 --no-audit --no-fund >/dev/null 2>&1"
fi

cp "$DRIVER" "$CACHE/driver.mjs"

docker run --rm --network "$NETWORK" \
  -v "$CACHE":/pw \
  -v "$OUT/fixtures":/fixtures:ro \
  -v "$OUT":/out \
  -w /pw "$IMAGE" node /pw/driver.mjs
