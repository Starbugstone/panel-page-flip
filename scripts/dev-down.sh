#!/usr/bin/env bash
# Destroy this checkout's stack: containers, network, and the named volumes
# holding its database and Symfony cache.
#
# Run this before deleting a worktree. A removed worktree leaves its containers
# running and its volumes on disk with nothing left pointing at them, and the
# bind mounts keep the deleted directory alive from the daemon's side.
#
#   dev-down.sh            containers, network and volumes for this checkout
#   dev-down.sh --keep-data  leave db_data and php_cache in place
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

# The Docker credential helper is broken under WSL here; an empty config skips it.
export DOCKER_CONFIG="${DOCKER_CONFIG:-/tmp/ppf-dockercfg}"
mkdir -p "$DOCKER_CONFIG"
[ -f "$DOCKER_CONFIG/config.json" ] || printf '{}' > "$DOCKER_CONFIG/config.json"

VOLUMES="-v"
if [ "${1:-}" = "--keep-data" ]; then
  VOLUMES=""
fi

PROJECT="$(grep -m1 '^COMPOSE_PROJECT_NAME=' .env 2>/dev/null | cut -d= -f2- || true)"
echo "==> Removing stack for ${PROJECT:-<no .env; using directory default>}"

# shellcheck disable=SC2086
docker compose down $VOLUMES --remove-orphans

echo "Done."
