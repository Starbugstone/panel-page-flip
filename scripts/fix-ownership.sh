#!/usr/bin/env bash
# Give this checkout back to the host user.
#
# Files written by a container that ran as root or www-data are owned by a UID
# the host has no claim on, and without passwordless sudo there is no way to
# chown them from the host. A throwaway root container with the checkout bind
# mounted has exactly the privilege needed and nothing else.
#
# This is a repair for damage already on disk. The cause is fixed in
# docker-compose.yml (`user:`) and docker/php/Dockerfile (HOST_UID/HOST_GID);
# once a stack is rebuilt on those, nothing should make this necessary again.
#
#   fix-ownership.sh              this checkout
#   fix-ownership.sh PATH [PATH]  the given directories
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

export DOCKER_CONFIG="${DOCKER_CONFIG:-/tmp/ppf-dockercfg}"
mkdir -p "$DOCKER_CONFIG"
[ -f "$DOCKER_CONFIG/config.json" ] || printf '{}' > "$DOCKER_CONFIG/config.json"

TARGETS=("$@")
[ "${#TARGETS[@]}" -gt 0 ] || TARGETS=("$REPO_ROOT")

UID_GID="$(id -u):$(id -g)"

# find exits non-zero when it cannot descend into a directory, which is exactly
# the situation being repaired. Under `set -o pipefail` that would abort the run
# before it reached the chown that fixes it.
count_foreign() {
  find "$1" -path '*/.git' -prune -o ! -user "$(id -un)" -print 2>/dev/null | wc -l || true
}

for target in "${TARGETS[@]}"; do
  abs="$(cd "$target" && pwd)"
  before="$(count_foreign "$abs")"
  if [ "$before" -eq 0 ]; then
    echo "$abs: already yours"
    continue
  fi
  echo "==> $abs: $before path(s) not owned by $(id -un)"
  # --user is deliberately not passed: chown is the privilege being borrowed.
  docker run --rm -v "$abs:/target" alpine:3 \
    sh -c "chown -R ${UID_GID} /target && chmod -R u+rwX /target"
  after="$(count_foreign "$abs")"
  echo "    now $after"
done
