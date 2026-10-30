#!/usr/bin/env bash
# =============================================================================
# asset-retention.sh
# -----------------------------------------------------------------------------
# Decides which hashed frontend assets a deployment may delete.
#
# A tab opened before a deploy still asks for the old build's lazy chunks at
# their original URLs, so a deploy adds the new build beside the old one instead
# of replacing it. A file's age says nothing about when it stopped being needed:
# a chunk built months ago may have been live until today. So the clock starts
# when a file is first seen outside the live build, and that moment is kept in a
# ledger on the server between deployments.
#
# This only computes. The caller lists the server's files, deletes what comes
# back and stores the new ledger, which is what lets the FTP, rsync and on-server
# paths share it.
#
# Usage:
#   asset-retention.sh <live-list> <present-list> <ledger-in> <ledger-out> <expired-out>
#
#   live-list     names in the build being deployed, one per line
#   present-list  names now in the server's public/assets/, one per line
#   ledger-in     "<superseded-epoch> <name>" lines; may not exist yet
#   ledger-out    the ledger to store for the next deployment
#   expired-out   names to delete now
#
# Optional env:
#   ASSET_RETENTION_DAYS  days a superseded file stays available (default: 14)
# =============================================================================

set -euo pipefail

fail() { printf "\033[1;31m[fail]\033[0m   %s\n" "$*" >&2; exit 1; }

[ "$#" -eq 5 ] || fail "Usage: asset-retention.sh <live-list> <present-list> <ledger-in> <ledger-out> <expired-out>"
live_list="$1"
present_list="$2"
ledger_in="$3"
ledger_out="$4"
expired_out="$5"

ASSET_RETENTION_DAYS="${ASSET_RETENTION_DAYS:-14}"
[[ "$ASSET_RETENTION_DAYS" =~ ^[0-9]+$ ]] || fail "ASSET_RETENTION_DAYS must be a whole number of days."
# An empty build list would make every file on the server look superseded.
[ -s "$live_list" ] || fail "The live build lists no assets; refusing to judge the server's."
[ -f "$ledger_in" ] || ledger_in=/dev/null

# The names come from the server and go on to rm, so anything but a plain file
# name is skipped: not deleted, not recorded.
awk \
    -v now="$(date +%s)" \
    -v max_age="$((ASSET_RETENTION_DAYS * 86400))" \
    -v ledger_out="$ledger_out" \
    -v expired_out="$expired_out" '
    BEGIN { printf "" > ledger_out; printf "" > expired_out }
    FILENAME == ARGV[1] { live[$0] = 1; next }
    FILENAME == ARGV[2] { if ($1 ~ /^[0-9]+$/) { since[$2] = $1 }; next }
    /^[A-Za-z0-9][A-Za-z0-9._-]*$/ {
        if ($0 in live) { next }
        superseded = ($0 in since) ? since[$0] : now
        if (now - superseded > max_age) {
            print $0 > expired_out
        } else {
            print superseded, $0 > ledger_out
        }
    }
' "$live_list" "$ledger_in" "$present_list"
