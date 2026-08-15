from pathlib import Path


def read(path: str) -> str:
    return Path(path).read_text()


def write(path: str, content: str) -> None:
    Path(path).write_text(content)


def replace(path: str, old: str, new: str) -> None:
    text = read(path)
    if old not in text:
        raise SystemExit(f"Expected text not found in {path}: {old[:120]!r}")
    write(path, text.replace(old, new, 1))


# 1) Permanent SPA fallback for React routes, while keeping /api on Symfony.
replace(
    'scripts/deploy/htaccess.dist',
    '''    # Otherwise rewrite all other queries to the Symfony front controller.\n    RewriteRule ^ %{ENV:BASE}/index.php [L]\n''',
    '''    # Client-side routes belong to the React SPA. Keep API requests on\n    # Symfony, but send any other missing path (for example /admin or /library)\n    # to the built index.html so React Router can resolve it. This must remain\n    # after the real file/directory rule above and before the Symfony catch-all.\n    RewriteCond %{REQUEST_URI} !^/api(?:/|$) [NC]\n    RewriteCond %{REQUEST_FILENAME} !-f\n    RewriteCond %{REQUEST_FILENAME} !-d\n    RewriteRule ^ %{ENV:BASE}/index.html [L]\n\n    # API requests and any other backend route still go through Symfony.\n    RewriteRule ^ %{ENV:BASE}/index.php [L]\n''',
)

# 2) Refuse production builds from a checkout that is not exactly origin/main.
replace(
    'scripts/build-release.sh',
    '''# --- preflight ----------------------------------------------------------------\nrequire_command docker\n\nif [ ! -f "$ENV_FILE" ]; then\n''',
    '''# --- preflight ----------------------------------------------------------------\nrequire_command docker\nrequire_command git\n\nlog "Verifying checkout matches origin/main"\nif ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then\n    fail "Production releases must be built from a Git checkout."\nfi\nif ! git remote get-url origin >/dev/null 2>&1; then\n    fail "Git remote 'origin' is not configured."\nfi\nif ! git fetch --quiet origin main; then\n    fail "Could not refresh origin/main; refusing to build from an unverifiable checkout."\nfi\nLOCAL_HEAD="$(git rev-parse HEAD)"\nREMOTE_MAIN="$(git rev-parse refs/remotes/origin/main)"\nif [ "$LOCAL_HEAD" != "$REMOTE_MAIN" ]; then\n    fail "Refusing production release: local HEAD ${LOCAL_HEAD:0:12} does not match origin/main ${REMOTE_MAIN:0:12}. Run: git switch main && git pull --ff-only origin main"\nfi\nlog "Checkout is current at ${LOCAL_HEAD:0:12}"\n\nif [ ! -f "$ENV_FILE" ]; then\n''',
)

# 3) Prune only generated Vite assets. Do not parse index.html: lazy chunks are
# intentionally not all referenced there. Exact-mirroring public/assets is safe
# because that directory contains generated release artefacts, never user data.
replace(
    'scripts/deploy-ftp.sh',
    '''# Safe-mode by default: never deletes anything on the server, never touches\n# the user-content folders.\n''',
    '''# Safe-mode by default: never deletes application/server state and never\n# touches user-content folders. Generated public/assets/ is the one exception:\n# it is mirrored exactly so obsolete content-hashed Vite chunks are pruned.\n''',
)
replace(
    'scripts/deploy-ftp.sh',
    '''LFTP_MIRROR="mirror --reverse --continue --parallel=${FTP_PARALLEL} --verbose ${DRY_FLAG} ${DELETE_FLAG} ${EXCL_STR} ${LOCAL_DIR}/ ${REMOTE_DIR}/"\n\n# --- run inside docker --------------------------------------------------------\n''',
    '''LFTP_MIRROR="mirror --reverse --continue --parallel=${FTP_PARALLEL} --verbose ${DRY_FLAG} ${DELETE_FLAG} ${EXCL_STR} ${LOCAL_DIR}/ ${REMOTE_DIR}/"\n\n# The normal mirror is deliberately non-destructive unless --delete is passed,\n# because production contains server-managed state. Vite's generated assets are\n# different: the release directory is authoritative and hashed filenames change\n# every build. Mirror that one directory with --delete so dead chunks do not\n# accumulate. Requiring index.html prevents --skip-frontend builds from wiping\n# the live asset directory.\nASSET_PRUNE_COMMAND=""\nASSET_LOCAL_DIR="$RELEASE_DIR/backend/public/assets"\nASSET_REMOTE_DIR="${FTP_REMOTE_ROOT%/}/backend/public/assets"\nif [ -d "$ASSET_LOCAL_DIR" ] && [ -f "$RELEASE_DIR/backend/public/index.html" ]; then\n    ASSET_PRUNE_COMMAND="mirror --reverse --continue --parallel=${FTP_PARALLEL} --verbose ${DRY_FLAG} --delete ${ASSET_LOCAL_DIR}/ ${ASSET_REMOTE_DIR}/"\n    log "Generated asset cleanup: enabled for public/assets/"\nelse\n    warn "Generated asset cleanup skipped (no built frontend assets/index.html in release)."\nfi\n\n# --- run inside docker --------------------------------------------------------\n''',
)
replace(
    'scripts/deploy-ftp.sh',
    '''${LFTP_OPEN}\n${LFTP_MIRROR}\nbye\n''',
    '''${LFTP_OPEN}\n${LFTP_MIRROR}\n${ASSET_PRUNE_COMMAND}\nbye\n''',
)

# 4) Add a real post-deploy smoke check. It validates the SPA fallback, checks
# that the deployed HTML references this release's entry chunk when available,
# verifies that chunk is fetchable, and proves /api/login is still an API route.
replace(
    'scripts/post-deploy.sh',
    '''#   ./scripts/post-deploy.sh                # health -> migrate -> cache-clear (HTTP)\n#   ./scripts/post-deploy.sh --action health\n#   ./scripts/post-deploy.sh --action migrate\n#   ./scripts/post-deploy.sh --action cache-clear\n''',
    '''#   ./scripts/post-deploy.sh                # health -> migrate -> cache-clear -> smoke (HTTP)\n#   ./scripts/post-deploy.sh --action health\n#   ./scripts/post-deploy.sh --action migrate\n#   ./scripts/post-deploy.sh --action cache-clear\n#   ./scripts/post-deploy.sh --action smoke\n''',
)
replace(
    'scripts/post-deploy.sh',
    '''SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"\nENV_FILE="$SCRIPT_DIR/.env.deploy"\n''',
    '''SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"\nREPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"\nRELEASE_DIR="$REPO_ROOT/release"\nENV_FILE="$SCRIPT_DIR/.env.deploy"\n''',
)
replace(
    'scripts/post-deploy.sh',
    '''if [ "${#ACTIONS[@]}" -eq 0 ]; then\n    ACTIONS=(health migrate upgrade-data cache-clear)\nfi\n''',
    '''if [ "${#ACTIONS[@]}" -eq 0 ]; then\n    ACTIONS=(health migrate upgrade-data cache-clear smoke)\nfi\n''',
)
replace(
    'scripts/post-deploy.sh',
    '''    case "$a" in\n        health|migrate|upgrade-data|cache-clear|about) ;;\n        *) fail "Unknown action: $a (allowed: health migrate upgrade-data cache-clear about)" ;;\n    esac\n''',
    '''    case "$a" in\n        health|migrate|upgrade-data|cache-clear|about|smoke) ;;\n        *) fail "Unknown action: $a (allowed: health migrate upgrade-data cache-clear about smoke)" ;;\n    esac\n''',
)
replace(
    'scripts/post-deploy.sh',
    '''# =============================================================================\n# SSH mode (optional, more reliable when available)\n# =============================================================================\n''',
    '''# =============================================================================\n# Public smoke test\n# =============================================================================\nsmoke_call() {\n    local base_url="${PUBLIC_URL%/}"\n    local spa_out api_out expected_entry api_code\n    spa_out="$(mktemp)"\n    api_out="$(mktemp)"\n\n    log "Smoke GET ${base_url}/admin"\n    if ! curl -fsS "${base_url}/admin" -o "$spa_out"; then\n        rm -f "$spa_out" "$api_out"\n        fail "SPA smoke request failed for /admin."\n    fi\n    if ! grep -q "Panel Page Flip" "$spa_out"; then\n        rm -f "$spa_out" "$api_out"\n        fail "/admin did not return the Panel Page Flip SPA."\n    fi\n\n    # If the release exists locally, prove production is serving this build's\n    # entry chunk rather than merely returning some older valid SPA shell.\n    if [ -f "$RELEASE_DIR/backend/public/index.html" ]; then\n        expected_entry="$(grep -oE 'src="/assets/[^"]+\\.js"' "$RELEASE_DIR/backend/public/index.html" | head -n 1 | sed -E 's/^src="([^"]+)"$/\\1/' || true)"\n        [ -n "$expected_entry" ] || { rm -f "$spa_out" "$api_out"; fail "Could not find the Vite entry chunk in release index.html."; }\n        if ! grep -Fq "$expected_entry" "$spa_out"; then\n            rm -f "$spa_out" "$api_out"\n            fail "Production /admin is not serving this release's entry chunk ($expected_entry)."\n        fi\n        log "Smoke GET ${base_url}${expected_entry}"\n        if ! curl -fsS -o /dev/null "${base_url}${expected_entry}"; then\n            rm -f "$spa_out" "$api_out"\n            fail "Current Vite entry chunk is not fetchable from production."\n        fi\n    else\n        warn "No local release/index.html; skipping exact entry-chunk comparison."\n    fi\n\n    log "Smoke POST ${base_url}/api/login"\n    api_code="$(curl -sS -o "$api_out" -w "%{http_code}" \\\n        -X POST \\\n        -H "Content-Type: application/json" \\\n        --data '{}' \\\n        "${base_url}/api/login")"\n    case "$api_code" in\n        400|401) ;;\n        *)\n            cat "$api_out"\n            rm -f "$spa_out" "$api_out"\n            fail "/api/login smoke check returned HTTP $api_code (expected 400 or 401)."\n            ;;\n    esac\n    if grep -qi '<!doctype html' "$api_out"; then\n        rm -f "$spa_out" "$api_out"\n        fail "/api/login returned SPA HTML; API routing is broken."\n    fi\n\n    rm -f "$spa_out" "$api_out"\n    log "Smoke checks passed."\n}\n\n# =============================================================================\n# SSH mode (optional, more reliable when available)\n# =============================================================================\n''',
)
replace(
    'scripts/post-deploy.sh',
    '''for a in "${ACTIONS[@]}"; do\n    log "===== ${a} ====="\n    if [ "$USE_SSH" = "1" ]; then\n        ssh_call "$a"\n    else\n        http_call "$a"\n    fi\ndone\n''',
    '''for a in "${ACTIONS[@]}"; do\n    log "===== ${a} ====="\n    if [ "$a" = "smoke" ]; then\n        smoke_call\n    elif [ "$USE_SSH" = "1" ]; then\n        ssh_call "$a"\n    else\n        http_call "$a"\n    fi\ndone\n''',
)

# Static invariants so the helper fails before committing a partial/bad patch.
htaccess = read('scripts/deploy/htaccess.dist')
spa = htaccess.index('RewriteRule ^ %{ENV:BASE}/index.html [L]')
api = htaccess.index('RewriteRule ^ %{ENV:BASE}/index.php [L]', spa)
if spa >= api:
    raise SystemExit('SPA fallback is not before the Symfony catch-all')

for path in ['scripts/build-release.sh', 'scripts/deploy-ftp.sh', 'scripts/post-deploy.sh']:
    if '\r\n' in read(path):
        raise SystemExit(f'Unexpected CRLF in {path}')
