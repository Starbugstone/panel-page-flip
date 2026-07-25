#!/usr/bin/env bash
# =============================================================================
# deploy.sh
# -----------------------------------------------------------------------------
# Convenience wrapper that runs build-release.sh + deploy-ftp.sh + post-deploy.sh
# in sequence, with a confirmation prompt before each step.
#
# Usage:
#   ./scripts/deploy.sh             # interactive (confirm each step)
#   ./scripts/deploy.sh --yes       # non-interactive (CI/CD mode)
# =============================================================================

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

YES=0
[ "${1:-}" = "--yes" ] && YES=1

confirm() {
    [ "$YES" = "1" ] && return 0
    read -r -p "$1 [y/N] " ans
    [[ "$ans" =~ ^[Yy]$ ]]
}

run_step() {
    local label="$1" script="$2"
    if confirm "$label"; then
        "$script"
    else
        printf "\033[1;33m[skip]\033[0m   %s\n" "$label"
    fi
}

run_step "1/3  Build production release?"  "$SCRIPT_DIR/build-release.sh"
run_step "2/3  Upload to FTP server?"      "$SCRIPT_DIR/deploy-ftp.sh"
run_step "3/3  Run post-deploy on server?" "$SCRIPT_DIR/post-deploy.sh"

printf "\n\033[1;32m[deploy]\033[0m All requested steps complete.\n"
