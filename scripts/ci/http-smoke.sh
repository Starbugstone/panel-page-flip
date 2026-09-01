#!/usr/bin/env bash
# Bounded public smoke checks. Staging must prove that Directory Privacy stops
# anonymous requests before authenticated checks are attempted.

set -euo pipefail

: "${APP_URL:?APP_URL is required}"
: "${DEPLOY_ENVIRONMENT:?DEPLOY_ENVIRONMENT is required}"

log()  { printf '\033[1;36m[http-smoke]\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31m[http-smoke]\033[0m %s\n' "$*" >&2; exit 1; }

[[ "$APP_URL" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?$ ]] || fail "APP_URL must be one HTTPS origin."
case "$DEPLOY_ENVIRONMENT" in
    staging|production) ;;
    *) fail "DEPLOY_ENVIRONMENT must be staging or production." ;;
esac

command -v curl >/dev/null 2>&1 || fail "curl is required."
response_file="$(mktemp)"
robots_file="$(mktemp)"
cleanup() {
    rm -f -- "$response_file" "$robots_file"
}
trap cleanup EXIT

curl_options=(--silent --show-error --fail --location --connect-timeout 10 --max-time 30)
if [ "$DEPLOY_ENVIRONMENT" = "staging" ]; then
    : "${STAGING_BASIC_AUTH_USERNAME:?Staging Basic Auth username is required}"
    : "${STAGING_BASIC_AUTH_PASSWORD:?Staging Basic Auth password is required}"

    root_status="$(curl --silent --output /dev/null --write-out '%{http_code}' --connect-timeout 10 --max-time 30 "$APP_URL/")"
    api_status="$(curl --silent --output /dev/null --write-out '%{http_code}' --connect-timeout 10 --max-time 30 "$APP_URL/api/config")"
    [ "$root_status" = "401" ] || fail "Staging root is anonymously accessible (expected HTTP 401, got $root_status)."
    [ "$api_status" = "401" ] || fail "Staging API is anonymously accessible (expected HTTP 401, got $api_status)."
    curl_options+=(--user "${STAGING_BASIC_AUTH_USERNAME}:${STAGING_BASIC_AUTH_PASSWORD}")
fi

curl "${curl_options[@]}" --output "$response_file" "$APP_URL/"
grep -q '<div id="root"' "$response_file" || fail "Frontend smoke response does not contain the application root."
asset_path="$(grep -oE '/assets/[A-Za-z0-9._/-]+\.(js|css)' "$response_file" | head -n 1 || true)"
[ -n "$asset_path" ] || fail "Frontend smoke response contains no hashed asset reference."
curl "${curl_options[@]}" --output /dev/null "$APP_URL$asset_path"

if [ "$DEPLOY_ENVIRONMENT" = "staging" ]; then
    curl "${curl_options[@]}" --output "$robots_file" "$APP_URL/robots.txt"
    grep -q '^Disallow: /$' "$robots_file" || fail "Staging robots.txt does not disallow indexing."
    grep -q 'noindex, nofollow, noarchive' "$response_file" || fail "Staging HTML does not contain noindex metadata."
fi

log "$DEPLOY_ENVIRONMENT HTTP and frontend asset smoke checks passed."
