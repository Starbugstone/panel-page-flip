#!/usr/bin/env bash
# Install a validated Vite dist into the live public directory. Assets are
# switched before index.html, and the previous build's hashed files remain at
# their original /assets/... URLs for clients that loaded the old HTML.

set -euo pipefail

: "${FRONTEND_SOURCE_DIR:?FRONTEND_SOURCE_DIR must point at a Vite dist directory}"
: "${PUBLIC_DIR:?PUBLIC_DIR must be the application public directory}"
: "${DEPLOY_STATE_DIR:?DEPLOY_STATE_DIR must be outside the public directory}"
: "${DEPLOY_ENVIRONMENT:?DEPLOY_ENVIRONMENT must be staging or production}"

log()  { printf '\033[1;36m[frontend-install]\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31m[frontend-install]\033[0m %s\n' "$*" >&2; exit 1; }

case "$DEPLOY_ENVIRONMENT" in
    staging|production) ;;
    *) fail "DEPLOY_ENVIRONMENT must be staging or production." ;;
esac

[ -d "$FRONTEND_SOURCE_DIR" ] || fail "Frontend source directory does not exist."
[ -f "$FRONTEND_SOURCE_DIR/index.html" ] || fail "Frontend artifact has no index.html."
[ -d "$FRONTEND_SOURCE_DIR/assets" ] || fail "Frontend artifact has no assets directory."
find "$FRONTEND_SOURCE_DIR/assets" -type f -print -quit | grep -q . \
    || fail "Frontend artifact assets directory is empty."
[ ! -e "$FRONTEND_SOURCE_DIR/uploads" ] || fail "Frontend artifact must never contain uploads."
[ ! -e "$FRONTEND_SOURCE_DIR/.htaccess" ] || fail "Frontend artifact must never replace host-owned .htaccess."
if find "$FRONTEND_SOURCE_DIR" -type l -print -quit | grep -q .; then
    fail "Frontend artifact contains a symbolic link."
fi

mkdir -p "$PUBLIC_DIR" "$DEPLOY_STATE_DIR"
work_dir="$(mktemp -d "$DEPLOY_STATE_DIR/frontend-install.XXXXXX")"
cleanup() {
    rm -rf -- "$work_dir"
}
trap cleanup EXIT

prepared_root="$work_dir/root"
mkdir -p "$prepared_root"
cp -a "$FRONTEND_SOURCE_DIR/." "$prepared_root/"
rm -rf -- "$prepared_root/assets"
rm -f -- "$prepared_root/deployment-commit.txt"

if [ "$DEPLOY_ENVIRONMENT" = "staging" ]; then
    printf 'User-agent: *\nDisallow: /\n' > "$prepared_root/robots.txt"
    if grep -qiE '<meta[[:space:]]+name="robots"' "$prepared_root/index.html"; then
        sed -i -E 's#<meta[[:space:]]+name="robots"[[:space:]]+content="[^"]*"[[:space:]]*/?>#<meta name="robots" content="noindex, nofollow, noarchive" />#I' \
            "$prepared_root/index.html"
    else
        sed -i 's#</head>#    <meta name="robots" content="noindex, nofollow, noarchive" />\n  </head>#' \
            "$prepared_root/index.html"
    fi
fi

assets_next="$PUBLIC_DIR/assets.next"
assets_previous="$PUBLIC_DIR/assets.previous"
rm -rf -- "$assets_next" "$assets_previous"
mkdir -p "$assets_next"

current_manifest="$DEPLOY_STATE_DIR/current-assets.list"
if [ -d "$PUBLIC_DIR/assets" ]; then
    if [ -f "$current_manifest" ]; then
        while IFS= read -r relative_path; do
            [ -n "$relative_path" ] || continue
            case "/$relative_path/" in
                *'/../'*) fail "Stored asset manifest contains an unsafe path." ;;
            esac
            [ "${relative_path#/}" = "$relative_path" ] || fail "Stored asset manifest contains an absolute path."
            [ -f "$PUBLIC_DIR/assets/$relative_path" ] || continue
            mkdir -p "$assets_next/$(dirname "$relative_path")"
            cp -a "$PUBLIC_DIR/assets/$relative_path" "$assets_next/$relative_path"
        done < "$current_manifest"
    else
        # One bounded compatibility pass for installations created before the
        # manifest existed. Subsequent deploys retain exactly one generation.
        cp -a "$PUBLIC_DIR/assets/." "$assets_next/"
    fi
fi
cp -a "$FRONTEND_SOURCE_DIR/assets/." "$assets_next/"

new_manifest="$work_dir/current-assets.list"
find "$FRONTEND_SOURCE_DIR/assets" -type f -printf '%P\n' | LC_ALL=C sort > "$new_manifest"

install_public_entry() {
    local source_path="$1"
    local name
    local next_path
    local previous_path

    name="$(basename "$source_path")"
    next_path="$PUBLIC_DIR/.${name}.next"
    previous_path="$PUBLIC_DIR/.${name}.previous"
    rm -rf -- "$next_path" "$previous_path"
    cp -a "$source_path" "$next_path"
    if [ -e "$PUBLIC_DIR/$name" ]; then
        mv "$PUBLIC_DIR/$name" "$previous_path"
    fi
    if ! mv "$next_path" "$PUBLIC_DIR/$name"; then
        [ ! -e "$previous_path" ] || mv "$previous_path" "$PUBLIC_DIR/$name"
        fail "Could not activate frontend entry $name."
    fi
    rm -rf -- "$previous_path"
}

# Install supporting files/directories before switching the hashed assets.
while IFS= read -r -d '' source_path; do
    [ "$(basename "$source_path")" = "index.html" ] && continue
    install_public_entry "$source_path"
done < <(find "$prepared_root" -mindepth 1 -maxdepth 1 -print0)

if [ -d "$PUBLIC_DIR/assets" ]; then
    mv "$PUBLIC_DIR/assets" "$assets_previous"
fi
if ! mv "$assets_next" "$PUBLIC_DIR/assets"; then
    [ ! -d "$assets_previous" ] || mv "$assets_previous" "$PUBLIC_DIR/assets"
    fail "Could not activate the frontend assets directory."
fi

manifest_next="$DEPLOY_STATE_DIR/current-assets.list.next"
cp "$new_manifest" "$manifest_next"
mv "$manifest_next" "$current_manifest"

# index.html is last: both the old and new asset URLs work at this point.
install_public_entry "$prepared_root/index.html"

log "Frontend activated; uploads were not read or changed."
