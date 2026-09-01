#!/usr/bin/env bash
# Remove Docker Compose projects for this app whose checkout no longer exists.
#
# Worktrees are created and deleted faster than their stacks are, and `git
# worktree remove` knows nothing about Docker. What is left behind is not
# harmless: the containers keep holding published ports, so the next worktree
# has to search further up the range for a free one, and their bind mounts pin
# the deleted directory.
#
#   dev-gc.sh          list what would be removed
#   dev-gc.sh --prune  remove it
set -euo pipefail

export DOCKER_CONFIG="${DOCKER_CONFIG:-/tmp/ppf-dockercfg}"
mkdir -p "$DOCKER_CONFIG"
[ -f "$DOCKER_CONFIG/config.json" ] || printf '{}' > "$DOCKER_CONFIG/config.json"

PRUNE=0
[ "${1:-}" = "--prune" ] && PRUNE=1

# Compose stamps every container with its project and the directory the compose
# file was read from. That directory is the checkout, so its absence is the
# whole test.
mapfile -t ROWS < <(
  docker ps -aq --filter 'label=com.docker.compose.project' 2>/dev/null |
    xargs -r docker inspect --format \
      '{{index .Config.Labels "com.docker.compose.project"}}|{{index .Config.Labels "com.docker.compose.project.working_dir"}}' |
    sort -u
)

ORPHANS=()
for row in "${ROWS[@]}"; do
  project="${row%%|*}"
  workdir="${row#*|}"
  case "$project" in cbz_reader|cbz_reader_*) ;; *) continue ;; esac
  [ -n "$workdir" ] || continue
  if [ ! -d "$workdir" ]; then
    ORPHANS+=("$project|$workdir")
  fi
done

if [ "${#ORPHANS[@]}" -eq 0 ]; then
  echo "No orphaned stacks."
  exit 0
fi

for entry in "${ORPHANS[@]}"; do
  project="${entry%%|*}"
  workdir="${entry#*|}"
  echo "orphan: $project  (checkout gone: $workdir)"
  if [ "$PRUNE" -eq 1 ]; then
    docker ps -aq --filter "label=com.docker.compose.project=$project" | xargs -r docker rm -f
    docker volume ls -q --filter "label=com.docker.compose.project=$project" | xargs -r docker volume rm -f
    docker network ls -q --filter "label=com.docker.compose.project=$project" | xargs -r docker network rm
    echo "  removed"
  fi
done

[ "$PRUNE" -eq 1 ] || echo -e "\nRe-run with --prune to remove them."
