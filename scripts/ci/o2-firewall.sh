#!/usr/bin/env bash
# Manage only the temporary O2Switch SSH exception owned by this workflow.

set -euo pipefail

log()  { printf '\033[1;36m[o2-firewall]\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31m[o2-firewall]\033[0m %s\n' "$*" >&2; exit 1; }

validate_ipv4() {
    local address="$1"
    local octet
    local -a octets

    [[ "$address" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "Runner address is not IPv4."
    IFS=. read -r -a octets <<< "$address"
    [ "${#octets[@]}" -eq 4 ] || fail "Runner address is not IPv4."
    for octet in "${octets[@]}"; do
        [ "$octet" -le 255 ] || fail "Runner address is not IPv4."
    done
}

require_credentials() {
    : "${CPANEL_SERVER:?CPANEL_SERVER is required}"
    : "${CPANEL_USERNAME:?CPANEL_USERNAME is required}"
    : "${CPANEL_API_TOKEN:?CPANEL_API_TOKEN is required}"
    [[ "$CPANEL_SERVER" =~ ^[A-Za-z0-9.-]+$ ]] || fail "CPANEL_SERVER must be a hostname."
    [[ "$CPANEL_USERNAME" =~ ^[A-Za-z0-9._-]+$ ]] || fail "CPANEL_USERNAME contains unsupported characters."
    [[ "$CPANEL_API_TOKEN" =~ ^[A-Za-z0-9]+$ ]] || fail "CPANEL_API_TOKEN contains unsupported characters."
    command -v curl >/dev/null 2>&1 || fail "curl is required."
    command -v python3 >/dev/null 2>&1 || fail "python3 is required."
}

request() {
    local operation="$1"
    local query="${2:-}"
    local url="https://${CPANEL_SERVER}:2083/execute/SshWhitelist/${operation}${query}"
    local response

    response="$(curl --silent --show-error --fail-with-body --max-time 50 --config - "$url" <<EOF
header = "Authorization: cpanel ${CPANEL_USERNAME}:${CPANEL_API_TOKEN}"
EOF
)" || fail "O2Switch SshWhitelist/$operation request failed."

    if ! printf '%s' "$response" | python3 -c '
import json
import sys
try:
    payload = json.load(sys.stdin)
except (json.JSONDecodeError, UnicodeDecodeError):
    raise SystemExit(2)
if payload.get("status") != 1:
    errors = payload.get("errors")
    if errors:
        print(f"O2Switch API error: {errors}", file=sys.stderr)
    raise SystemExit(1)
'; then
        fail "O2Switch SshWhitelist/$operation did not return status 1."
    fi

    printf '%s' "$response"
}

operation="${1:-}"
address="${2:-}"
validate_ipv4 "$address"

case "$operation" in
    validate)
        exit 0
        ;;
    add)
        marker="${3:-}"
        [ -n "$marker" ] || fail "An ownership marker path is required for add."
        require_credentials
        list_response="$(request list)"
        if printf '%s' "$list_response" | python3 -c '
import json
import sys
address = sys.argv[1]
payload = json.load(sys.stdin)
entries = payload.get("data", {}).get("list")
if not isinstance(entries, list):
    raise SystemExit(2)
raise SystemExit(0 if any(str(item.get("address")) == address and int(item.get("port", 0)) == 22 for item in entries) else 1)
' "$address"; then
            fail "Runner IP is already whitelisted; refusing to claim or remove an existing exception."
        else
            list_status=$?
            [ "$list_status" -eq 1 ] || fail "O2Switch whitelist list response has an unexpected shape."
        fi

        marker_parent="$(dirname "$marker")"
        [ -d "$marker_parent" ] || fail "Ownership marker directory does not exist."
        printf '%s\n' "$address" > "$marker"
        request add "?address=${address}&port=22" >/dev/null
        log "Temporary SSH exception added for $address."
        ;;
    remove)
        direction="${3:-}"
        case "$direction" in
            in|out) ;;
            *) fail "Removal direction must be in or out." ;;
        esac
        require_credentials
        request remove "?address=${address}&port=22&direction=${direction}" >/dev/null
        log "Temporary SSH exception removed for $address ($direction)."
        ;;
    *)
        fail "Usage: $0 validate IPv4 | add IPv4 MARKER | remove IPv4 in|out"
        ;;
esac
