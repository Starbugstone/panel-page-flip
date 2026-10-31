#!/usr/bin/env bash
#
# Tests for the delivery scripts: backup, build, deploy and post-deploy.
#
# These scripts act on production and nothing else runs them before they do, so
# every case runs the real script end to end against temporary directories. No
# case can reach a host: `ssh` and `docker` are stubs first on PATH, the stub ssh
# runs the remote command locally, and the host name is under .invalid. rsync,
# php and git are real, because what they actually do is what is being tested;
# they only ever touch this suite's own directories.
#
# Everything runs on copies of the scripts, so a scripts/.env.deploy holding real
# credentials is never read. Linux only, like the servers these scripts run on:
# it uses GNU stat, sed and touch.
#
# Usage: ./test-deploy-scripts.sh

set -uo pipefail

tests_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
scripts_dir="$(dirname -- "$tests_dir")"
repo_dir="$(dirname -- "$scripts_dir")"

passed=0
failed=0
sandbox=""
stubs=""
events=""
current_case=""
case_ok=1
last_output=""
last_exit=0

for tool in rsync php git timeout lftp; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    printf '%s is required to run these tests.\n' "$tool" >&2
    exit 2
  fi
done

real_date="$(command -v date)"
real_rsync="$(command -v rsync)"
real_php="$(command -v php)"
real_lftp="$(command -v lftp)"

# --- harness ------------------------------------------------------------

fail() {
  printf '  FAIL  %s\n' "$current_case"
  printf '        %s\n' "$1"
  failed=$((failed + 1))
  case_ok=0
}

begin_case() {
  current_case="$1"
  case_ok=1
  # An empty sandbox would point every path in the case at the filesystem root.
  if ! sandbox="$(mktemp -d "${TMPDIR:-/tmp}/deploy_test.XXXXXXXX")" || [ -z "$sandbox" ]; then
    printf 'Could not create the sandbox directory for: %s\n' "$current_case" >&2
    exit 2
  fi
  stubs="$sandbox/stubs"
  events="$sandbox/events.log"
  mkdir -p "$stubs"
  : > "$events"
  install_stubs
}

end_case() {
  rm -rf -- "$sandbox"
  if [ "$case_ok" -eq 1 ]; then
    printf '  PASS  %s\n' "$current_case"
    passed=$((passed + 1))
  fi
}

# Bounded, so a regression that makes a script wait forever fails the suite rather
# than hanging it.
run() {
  last_output="$(timeout 120 env PATH="$stubs:$PATH" EVENTS="$events" "$@" 2>&1 </dev/null)"
  last_exit=$?
}

assert_succeeded() {
  [ "$last_exit" -eq 0 ] || fail "Expected success, got exit $last_exit. Output: $last_output"
}

assert_refused() {
  [ "$last_exit" -ne 0 ] || fail "Expected a refusal, but it exited 0. Output: $last_output"
  case "$last_output" in
    *"$1"*) ;;
    *) fail "Expected the refusal to mention: $1. Output: $last_output" ;;
  esac
}

assert_output_contains() {
  case "$last_output" in
    *"$1"*) ;;
    *) fail "Expected output to contain: $1. Output: $last_output" ;;
  esac
}

assert_exists() { [ -e "$1" ] || fail "Expected to exist: ${1#"$sandbox"/}"; }
assert_absent() {
  if [ -e "$1" ] || [ -L "$1" ]; then
    fail "Expected not to exist: ${1#"$sandbox"/}"
  fi
}
assert_contains() { grep -qF -- "$2" "$1" 2>/dev/null || fail "Expected ${1#"$sandbox"/} to contain: $2"; }
assert_lacks() { ! grep -qF -- "$2" "$1" 2>/dev/null || fail "Expected ${1#"$sandbox"/} not to contain: $2"; }

# Fails unless the first text appears on an earlier line than the second.
assert_order() {
  local first second
  first="$(grep -nF -- "$2" "$1" | head -n 1 | cut -d: -f1)"
  second="$(grep -nF -- "$3" "$1" | tail -n 1 | cut -d: -f1)"
  if [ -z "$first" ] || [ -z "$second" ] || [ "$first" -ge "$second" ]; then
    fail "Expected '$2' before '$3' in ${1#"$sandbox"/}: $(cat "$1")"
  fi
}

count_matching() {
  find "$1" -mindepth 1 -maxdepth 1 -name "$2" 2>/dev/null | wc -l | tr -d ' '
}

write_script() {
  { printf '#!/usr/bin/env bash\n'; cat; } > "$1"
  chmod +x "$1"
}

write_stub() { write_script "$stubs/$1"; }

install_stubs() {
  # Runs the "remote" command here. rsync starts its far side the same way, which
  # is what lets the real rsync exercise the real rules with no network.
  write_stub ssh <<'EOF'
printf 'ssh-args %s\n' "$*" >> "$EVENTS"
while [ "$#" -gt 0 ]; do
  case "$1" in
    -p|-o|-i|-l) shift 2 ;;
    -*) shift ;;
    *) break ;;
  esac
done
shift
exec bash -c "$*"
EOF

  write_stub rsync <<EOF
printf 'rsync-run\n' >> "\$EVENTS"
exec "$real_rsync" "\$@"
EOF

  write_stub php <<'EOF'
printf 'php cwd=%s args=%s\n' "$PWD" "$*" >> "$EVENTS"
EOF

  write_stub date <<EOF
if [ "\${1:-}" = '+%F_%H%M%S' ] && [ -n "\${FAKE_STAMP:-}" ]; then
  printf '%s\n' "\$FAKE_STAMP"
else
  exec "$real_date" "\$@"
fi
EOF

  write_stub mysqldump <<'EOF'
printf 'mysqldump args=%s pwd=%s\n' "$*" "${MYSQL_PWD:-}" >> "$EVENTS"
[ -z "${FAKE_DUMP_FAILS:-}" ] || exit 1
printf -- '-- dump\n'
EOF

  # Answers the listing session with what the case put on the "server", and keeps
  # a copy of the ledger the script uploads, which it deletes afterwards.
  write_stub docker <<'EOF'
for script; do :; done
printf '%s\n----\n' "$script" >> "$EVENTS.docker"
printf 'docker-run\n' >> "$EVENTS"
case "$script" in
  *'cls -1'*)
    printf '@@ledger\n'
    cat "${FAKE_FTP_LEDGER:-/dev/null}" 2>/dev/null
    printf '@@present\n'
    cat "${FAKE_FTP_PRESENT:-/dev/null}" 2>/dev/null
    ;;
  *put*)
    [ -z "${FAKE_FTP_RELEASE:-}" ] || cp "$FAKE_FTP_RELEASE/.asset-retention.next" "$EVENTS.ledger"
    ;;
esac
exit "${FAKE_DOCKER_EXIT:-0}"
EOF
}

# Copies only what is named, so scripts/.env.deploy never enters a sandbox.
install_scripts() {
  mkdir -p "$1/scripts"
  cp "$scripts_dir"/deploy-ssh.sh "$scripts_dir"/deploy-ftp.sh "$scripts_dir"/deploy.sh \
    "$scripts_dir"/post-deploy.sh "$scripts_dir"/build-release.sh "$scripts_dir"/asset-retention.sh \
    "$1/scripts/"
  cp -R "$scripts_dir/server" "$scripts_dir/deploy" "$1/scripts/"
}

# --- backup helpers -----------------------------------------------------

make_app() {
  app="$sandbox/app"
  backups="$sandbox/backups"
  uploads="$app/backend/public/uploads"
  install_scripts "$app"
  mkdir -p "$uploads"
  printf 'DATABASE_URL="mysql://bob:s3cret@db.invalid:3307/comics?serverVersion=8.0.32"\n' > "$app/backend/.env.local"
}

run_backup() {
  local stamp="$1"
  shift
  run env -u DATABASE_URL APP_DIR="$app" BACKUP_ROOT="$backups" FAKE_STAMP="$stamp" "$@" \
    "$app/scripts/server/backup-comics.sh"
}

inode_of() { stat -c %i "$1"; }

# --- deploy helpers -----------------------------------------------------

write_deploy_env() {
  local file="$1/scripts/.env.deploy"
  shift
  {
    printf 'SSH_HOST=test.invalid\nSSH_USER=deploy\nSSH_PORT=2222\n'
    printf 'SSH_KEY=/keys/deploy\n'
    printf 'SSH_REMOTE_PATH=%q\n' "$server"
    printf 'SSH_WEB_USER=%q\n' "$(id -un)"
    printf 'SSH_WEB_GROUP=%q\n' "$(id -gn)"
    printf 'SSH_BACKUP_COMMAND=%q\n' "printf 'backup-run\n' >> $events"
    printf 'PUBLIC_URL=https://test.invalid\nPOST_DEPLOY_TOKEN=token\n'
    printf 'FTP_HOST=test.invalid\nFTP_USER=u\nFTP_PASSWORD=p\nFTP_REMOTE_ROOT=/site\n'
    printf '%s\n' "$@"
  } > "$file"
}

make_server() {
  server="$sandbox/server"
  mkdir -p "$server/scripts/server" "$server/.git" "$server/frontend" \
    "$server/backend/var/quarantine/comics/1" "$server/backend/var/sessions" \
    "$server/backend/var/page-cache" "$server/backend/public/uploads/comics/1" \
    "$server/backend/public/.well-known/acme-challenge" "$server/backend/public/assets" \
    "$server/backend/src" "$server/backend/vendor/old"
  printf 'helper\n' > "$server/scripts/server/backup-comics.sh"
  printf 'ref\n' > "$server/.git/HEAD"
  printf '{}\n' > "$server/frontend/package.json"
  printf 'original\n' > "$server/backend/var/quarantine/comics/1/original.cbz"
  printf 'session\n' > "$server/backend/var/sessions/sess_1"
  printf 'page\n' > "$server/backend/var/page-cache/page.webp"
  printf 'comic\n' > "$server/backend/public/uploads/comics/1/a.cbz"
  printf 'token\n' > "$server/backend/public/.well-known/acme-challenge/token"
  printf 'APP_SECRET=live\n' > "$server/backend/.env.local"
  printf '<?php return [];\n' > "$server/backend/.env.local.php"
  printf '<?php // stale\n' > "$server/backend/src/Removed.php"
  printf '<?php // stale\n' > "$server/backend/vendor/old/Removed.php"
  printf '<?php phpinfo();\n' > "$server/backend/public/error.php"
  printf 'old development rules\n' > "$server/backend/public/.htaccess"
  printf 'old chunk\n' > "$server/backend/public/assets/Page-old.js"
}

make_release() {
  local release="$1/release/backend"
  mkdir -p "$release/src" "$release/bin" "$release/vendor" "$release/config" \
    "$release/public/assets" "$release/var/page-cache"
  printf '<?php // new\n' > "$release/src/Kernel.php"
  printf 'console\n' > "$release/bin/console"
  printf '<?php\n' > "$release/vendor/autoload.php"
  printf 'yaml\n' > "$release/config/services.yaml"
  printf '<?php\n' > "$release/public/index.php"
  printf '<html></html>\n' > "$release/public/index.html"
  printf 'new chunk\n' > "$release/public/assets/Page-new.js"
  cp "$scripts_dir/deploy/htaccess.dist" "$release/public/.htaccess"
}

make_rsync_deploy() {
  laptop="$sandbox/laptop"
  make_server
  install_scripts "$laptop"
  make_release "$laptop"
  write_deploy_env "$laptop" "$@"
}

make_ftp_deploy() {
  laptop="$sandbox/laptop"
  server="$sandbox/unused"
  install_scripts "$laptop"
  make_release "$laptop"
  write_deploy_env "$laptop" "$@"
}

replace_steps_with_recorders() {
  local step
  for step in build-release deploy-ftp post-deploy; do
    write_script "$laptop/scripts/$step.sh" <<EOF
printf 'step-$step\n' >> "\$EVENTS"
EOF
  done
}

run_ftp() {
  run env FAKE_FTP_RELEASE="$laptop/release" FAKE_FTP_LEDGER="$sandbox/ftp-ledger" \
    FAKE_FTP_PRESENT="$sandbox/ftp-present" "$laptop/scripts/deploy-ftp.sh" "$@"
}

# Stands in for a server-side checkout whose frontend builds into dist/.
make_git_server() {
  make_server
  install_scripts "$server"
  mkdir -p "$server/frontend"
  write_stub npm <<'EOF'
if [ "$1 ${2:-}" = 'run build' ]; then
  mkdir -p dist/assets
  printf 'new chunk\n' > dist/assets/Page-new.js
  printf '<html>new</html>\n' > dist/index.html
fi
EOF
}

run_server_deploy() {
  run env APP_DIR="$server" WEB_USER="$(id -un)" WEB_GROUP="$(id -gn)" APP_URL=https://test.invalid \
    SKIP_COMPOSER=1 BACKUP_COMMAND=true "$@" "$server/scripts/server/server-deploy.sh"
}

printf 'Testing the delivery scripts in %s\n\n' "$scripts_dir"

# --- cases: uploads backup ----------------------------------------------

begin_case 'backup refuses a missing uploads folder and does not recreate it'
make_app
printf 'page\n' > "$uploads/page.jpg"
run_backup 2026-01-01_000000
assert_succeeded
rm -rf "$uploads"
run_backup 2026-01-02_000000
assert_refused 'Uploads folder is missing'
assert_absent "$uploads"
assert_exists "$backups/uploads-snapshots/2026-01-01_000000/page.jpg"
assert_absent "$backups/db/2026-01-02_000000.sql.gz"
assert_absent "$backups/uploads-snapshots/2026-01-02_000000"
end_case

begin_case 'backup refuses an empty uploads folder while the old mirror holds the only copy'
make_app
mkdir -p "$backups/uploads/comics"
printf 'page\n' > "$backups/uploads/comics/page.jpg"
run_backup 2026-01-01_000000
assert_refused 'ALLOW_EMPTY_UPLOADS'
assert_exists "$backups/uploads/comics/page.jpg"
end_case

begin_case 'backup treats a tree of empty folders as empty'
make_app
printf 'page\n' > "$uploads/page.jpg"
run_backup 2026-01-01_000000
rm -f "$uploads/page.jpg"
mkdir -p "$uploads/comics/1/covers"
run_backup 2026-01-02_000000
assert_refused 'ALLOW_EMPTY_UPLOADS'
assert_absent "$backups/uploads-snapshots/2026-01-02_000000"
end_case

begin_case 'backup accepts an empty uploads folder only when told to, and keeps the older snapshot'
make_app
printf 'page\n' > "$uploads/page.jpg"
run_backup 2026-01-01_000000
rm -f "$uploads/page.jpg"
run_backup 2026-01-02_000000 ALLOW_EMPTY_UPLOADS=1
assert_succeeded
assert_exists "$backups/uploads-snapshots/2026-01-01_000000/page.jpg"
assert_exists "$backups/uploads-snapshots/2026-01-02_000000"
assert_absent "$backups/uploads-snapshots/2026-01-02_000000/page.jpg"
# That was the decision, so the next run has nothing to compare against.
run_backup 2026-01-03_000000
assert_succeeded
end_case

begin_case 'backup of a new installation accepts an empty uploads folder'
make_app
run_backup 2026-01-01_000000
assert_succeeded
assert_exists "$backups/db/2026-01-01_000000.sql.gz"
assert_exists "$backups/uploads-snapshots/2026-01-01_000000"
end_case

# --- cases: uploads snapshots -------------------------------------------

begin_case 'every dump has a dated snapshot, and unchanged files are hard links'
make_app
printf 'same\n' > "$uploads/same.jpg"
printf 'one\n' > "$uploads/changed.jpg"
printf 'gone\n' > "$uploads/removed.jpg"
run_backup 2026-01-01_000000
assert_succeeded
rm -f "$uploads/removed.jpg"
printf 'two, longer\n' > "$uploads/changed.jpg"
run_backup 2026-01-02_000000
assert_succeeded
first="$backups/uploads-snapshots/2026-01-01_000000"
second="$backups/uploads-snapshots/2026-01-02_000000"
assert_exists "$backups/db/2026-01-01_000000.sql.gz"
assert_exists "$backups/db/2026-01-02_000000.sql.gz"
assert_exists "$first/removed.jpg"
assert_absent "$second/removed.jpg"
assert_contains "$first/changed.jpg" 'one'
assert_contains "$second/changed.jpg" 'two, longer'
[ "$(inode_of "$first/same.jpg")" = "$(inode_of "$second/same.jpg")" ] || fail 'An unchanged file was copied instead of hard linked.'
end_case

begin_case 'a relative BACKUP_ROOT still hard links unchanged files'
make_app
printf 'same\n' > "$uploads/same.jpg"
cd "$sandbox" || exit 2
run_backup 2026-01-01_000000 BACKUP_ROOT=relative-backups
assert_succeeded
run_backup 2026-01-02_000000 BACKUP_ROOT=relative-backups
assert_succeeded
cd "$repo_dir" || exit 2
[ "$(inode_of "$sandbox/relative-backups/uploads-snapshots/2026-01-01_000000/same.jpg")" = \
  "$(inode_of "$sandbox/relative-backups/uploads-snapshots/2026-01-02_000000/same.jpg")" ] \
  || fail 'A relative BACKUP_ROOT silently fell back to full copies.'
end_case

begin_case 'the first snapshot links against the old mirror and leaves it alone'
make_app
mkdir -p "$backups/uploads"
printf 'same\n' > "$uploads/same.jpg"
cp -p "$uploads/same.jpg" "$backups/uploads/same.jpg"
printf 'only in the old backup\n' > "$backups/uploads/old-only.jpg"
run_backup 2026-01-01_000000
assert_succeeded
assert_exists "$backups/uploads/old-only.jpg"
[ "$(inode_of "$backups/uploads/same.jpg")" = "$(inode_of "$backups/uploads-snapshots/2026-01-01_000000/same.jpg")" ] \
  || fail 'The first snapshot did not hard link against the old mirror.'
end_case

begin_case 'retention prunes old dumps and snapshots together and never a new snapshot'
make_app
printf 'page\n' > "$uploads/page.jpg"
# A source untouched for months, which is what the copy gives the snapshot folder.
touch -d '200 days ago' "$uploads"
run_backup 2026-01-01_000000 RETENTION_DAYS=30
assert_succeeded
assert_exists "$backups/uploads-snapshots/2026-01-01_000000/page.jpg"
touch -d '40 days ago' "$backups/db/2026-01-01_000000.sql.gz" "$backups/uploads-snapshots/2026-01-01_000000"
run_backup 2026-03-01_000000 RETENTION_DAYS=30
assert_succeeded
assert_absent "$backups/db/2026-01-01_000000.sql.gz"
assert_absent "$backups/uploads-snapshots/2026-01-01_000000"
assert_exists "$backups/db/2026-03-01_000000.sql.gz"
assert_exists "$backups/uploads-snapshots/2026-03-01_000000/page.jpg"
end_case

begin_case 'a pruned snapshot takes nothing away from a newer one that shares its files'
make_app
printf 'page\n' > "$uploads/page.jpg"
run_backup 2026-01-01_000000
touch -d '40 days ago' "$backups/uploads-snapshots/2026-01-01_000000"
run_backup 2026-03-01_000000 RETENTION_DAYS=30
assert_succeeded
assert_absent "$backups/uploads-snapshots/2026-01-01_000000"
assert_contains "$backups/uploads-snapshots/2026-03-01_000000/page.jpg" 'page'
end_case

begin_case 'backup rejects a retention period that is not a number'
make_app
run_backup 2026-01-01_000000 RETENTION_DAYS=soon
assert_refused 'RETENTION_DAYS'
end_case

begin_case 'without rsync, backup takes a full dated copy under the same rules'
make_app
no_rsync="$sandbox/no-rsync"
mkdir -p "$no_rsync"
for tool in bash dirname find sort tail grep gzip cp mv rm mkdir touch; do
  ln -s "$(command -v "$tool")" "$no_rsync/$tool"
done
ln -s "$stubs/date" "$no_rsync/date"
ln -s "$stubs/mysqldump" "$no_rsync/mysqldump"
run_no_rsync() {
  last_output="$(timeout 120 env -u DATABASE_URL PATH="$no_rsync" EVENTS="$events" APP_DIR="$app" BACKUP_ROOT="$backups" \
    FAKE_STAMP="$1" "$app/scripts/server/backup-comics.sh" 2>&1 </dev/null)"
  last_exit=$?
}
mkdir -p "$uploads/comics"
printf 'page\n' > "$uploads/comics/page.jpg"
run_no_rsync 2026-01-01_000000
assert_succeeded
assert_output_contains 'full copy'
assert_contains "$backups/uploads-snapshots/2026-01-01_000000/comics/page.jpg" 'page'
rm -rf "$uploads/comics"
mkdir -p "$uploads/comics"
run_no_rsync 2026-01-02_000000
assert_refused 'ALLOW_EMPTY_UPLOADS'
rm -rf "$uploads"
run_no_rsync 2026-01-03_000000
assert_refused 'Uploads folder is missing'
assert_absent "$uploads"
assert_contains "$backups/uploads-snapshots/2026-01-01_000000/comics/page.jpg" 'page'
assert_absent "$backups/uploads-snapshots/2026-01-02_000000"
end_case

begin_case 'a failed dump publishes neither half of the backup'
make_app
printf 'page\n' > "$uploads/page.jpg"
run_backup 2026-01-01_000000 FAKE_DUMP_FAILS=1
[ "$last_exit" -ne 0 ] || fail 'A failed dump exited 0.'
[ "$(count_matching "$backups/db" '*')" = 0 ] || fail 'A failed dump left a file in db/.'
[ "$(count_matching "$backups/uploads-snapshots" '*')" = 0 ] || fail 'A failed dump left a snapshot.'
end_case

begin_case 'a failed uploads copy publishes neither half of the backup'
make_app
printf 'page\n' > "$uploads/page.jpg"
write_stub rsync <<'EOF'
exit 23
EOF
run_backup 2026-01-01_000000
[ "$last_exit" -ne 0 ] || fail 'A failed copy exited 0.'
[ "$(count_matching "$backups/db" '*')" = 0 ] || fail 'A failed copy left a file in db/.'
[ "$(count_matching "$backups/uploads-snapshots" '*')" = 0 ] || fail 'A failed copy left a snapshot.'
end_case

# --- cases: database configuration --------------------------------------

begin_case 'backup reads a compiled-only installation without putting the password in arguments'
make_app
rm -f "$app/backend/.env.local"
cat > "$app/backend/.env.local.php" <<'EOF'
<?php return ['DATABASE_URL' => 'mysql://carol:c0mpiled%21@compiled.invalid:3308/compiled_db'];
EOF
write_stub php <<EOF
printf 'php args=%s\n' "\$*" >> "\$EVENTS"
exec "$real_php" "\$@"
EOF
run_backup 2026-01-01_000000
assert_succeeded
assert_contains "$events" '-h compiled.invalid -P 3308 -u carol compiled_db'
assert_contains "$events" 'pwd=c0mpiled!'
assert_lacks "$events" 'args=--single-transaction --routines --triggers -p'
if grep -E '(php|mysqldump) args=' "$events" | sed 's/ pwd=.*//' | grep -qF 'c0mpiled'; then
  fail "The password reached a process argument: $(cat "$events")"
fi
end_case

begin_case 'backup refuses a compiled file with no DATABASE_URL rather than falling back'
make_app
cat > "$app/backend/.env.local.php" <<'EOF'
<?php return ['APP_ENV' => 'prod'];
EOF
write_stub php <<EOF
exec "$real_php" "\$@"
EOF
run_backup 2026-01-01_000000
assert_refused 'DATABASE_URL empty'
[ "$(grep -c 'mysqldump' "$events")" = 0 ] || fail 'mysqldump ran against the fallback file.'
end_case

begin_case 'backup resolves DATABASE_URL in the order the application does'
make_app
write_stub php <<EOF
exec "$real_php" "\$@"
EOF
printf 'DATABASE_URL=mysql://a:p@from-local.invalid/db\n' > "$app/backend/.env.local"
printf 'DATABASE_URL=mysql://a:p@from-prod-local.invalid/db\n' > "$app/backend/.env.prod.local"
run_backup 2026-01-01_000000
assert_succeeded
assert_contains "$events" 'from-prod-local.invalid'
assert_lacks "$events" 'from-local.invalid'
: > "$events"
cat > "$app/backend/.env.local.php" <<'EOF'
<?php return ['DATABASE_URL' => 'mysql://a:p@from-compiled.invalid/db'];
EOF
run_backup 2026-01-02_000000
assert_succeeded
assert_contains "$events" 'from-compiled.invalid'
: > "$events"
last_output="$(timeout 120 env PATH="$stubs:$PATH" EVENTS="$events" APP_DIR="$app" BACKUP_ROOT="$backups" FAKE_STAMP=2026-01-03_000000 \
  DATABASE_URL=mysql://a:p@from-environment.invalid/db "$app/scripts/server/backup-comics.sh" 2>&1 </dev/null)"
last_exit=$?
assert_succeeded
assert_contains "$events" 'from-environment.invalid'
end_case

begin_case 'backup refuses a host with no production database configuration'
make_app
rm -f "$app/backend/.env.local"
printf 'DATABASE_URL=mysql://dev:dev@database/dev\n' > "$app/backend/.env"
run_backup 2026-01-01_000000
assert_refused 'No DATABASE_URL'
end_case

# --- cases: deploy-ssh.sh --rsync ---------------------------------------

begin_case 'rsync deploy updates the code and preserves everything the server owns'
make_rsync_deploy
run "$laptop/scripts/deploy-ssh.sh" --rsync
assert_succeeded
assert_exists "$server/backend/src/Kernel.php"
assert_exists "$server/backend/vendor/autoload.php"
# Mirrored paths really are mirrored.
assert_absent "$server/backend/src/Removed.php"
assert_absent "$server/backend/vendor/old/Removed.php"
# Runtime state and helpers.
assert_exists "$server/scripts/server/backup-comics.sh"
assert_exists "$server/.git/HEAD"
assert_exists "$server/frontend/package.json"
assert_exists "$server/backend/var/quarantine/comics/1/original.cbz"
assert_exists "$server/backend/var/sessions/sess_1"
assert_exists "$server/backend/var/page-cache/page.webp"
assert_exists "$server/backend/public/uploads/comics/1/a.cbz"
assert_exists "$server/backend/public/.well-known/acme-challenge/token"
assert_contains "$server/backend/.env.local" 'APP_SECRET=live'
assert_exists "$server/backend/.env.local.php"
end_case

begin_case 'rsync deploy backs up first, and a failed backup uploads nothing'
make_rsync_deploy
run "$laptop/scripts/deploy-ssh.sh" --rsync
assert_succeeded
assert_order "$events" 'backup-run' 'rsync-run'
make_rsync_deploy "SSH_BACKUP_COMMAND=false"
: > "$events"
rm -f "$server/backend/src/Kernel.php"
run "$laptop/scripts/deploy-ssh.sh" --rsync
[ "$last_exit" -ne 0 ] || fail 'A failed backup did not stop the deploy.'
assert_absent "$server/backend/src/Kernel.php"
assert_lacks "$events" 'rsync-run'
end_case

begin_case 'rsync deploy applies the production public-file policy'
make_rsync_deploy
run "$laptop/scripts/deploy-ssh.sh" --rsync
assert_succeeded
assert_absent "$server/backend/public/error.php"
cmp -s "$scripts_dir/deploy/htaccess.dist" "$server/backend/public/.htaccess" || fail 'The production .htaccess was not installed.'
end_case

begin_case 'a frontend-only release cannot delete the live code'
make_rsync_deploy
rm -rf "$laptop/release/backend/src" "$laptop/release/backend/vendor" "$laptop/release/backend/config" \
  "$laptop/release/backend/bin"
run "$laptop/scripts/deploy-ssh.sh" --rsync
assert_succeeded
assert_exists "$server/backend/src/Removed.php"
assert_exists "$server/backend/vendor/old/Removed.php"
end_case

begin_case 'rsync deploy runs console commands in backend/ and creates the var folders'
make_rsync_deploy
rm -rf "$server/backend/var/cache" "$server/backend/var/log"
run "$laptop/scripts/deploy-ssh.sh" --rsync
assert_succeeded
assert_contains "$events" "php cwd=$server/backend args=bin/console doctrine:migrations:migrate"
assert_exists "$server/backend/var/cache"
assert_exists "$server/backend/var/log"
end_case

# --- cases: retaining hashed assets -------------------------------------

begin_case 'rsync deploy keeps previous chunks at their URLs, then removes them after the period'
make_rsync_deploy
run "$laptop/scripts/deploy-ssh.sh" --rsync
assert_succeeded
assert_exists "$server/backend/public/assets/Page-new.js"
assert_exists "$server/backend/public/assets/Page-old.js"
assert_contains "$server/backend/var/asset-retention.tsv" 'Page-old.js'
assert_lacks "$server/backend/var/asset-retention.tsv" 'Page-new.js'
# The clock starts when a chunk stops being live, and is not reset by later deploys.
started="$(cut -d' ' -f1 "$server/backend/var/asset-retention.tsv")"
sed -i "s/^$started /$((started - 100)) /" "$server/backend/var/asset-retention.tsv"
run "$laptop/scripts/deploy-ssh.sh" --rsync
assert_succeeded
assert_contains "$server/backend/var/asset-retention.tsv" "$((started - 100)) Page-old.js"
printf '%s Page-old.js\n' "$(($(date +%s) - 15 * 86400))" > "$server/backend/var/asset-retention.tsv"
run "$laptop/scripts/deploy-ssh.sh" --rsync
assert_succeeded
assert_absent "$server/backend/public/assets/Page-old.js"
assert_exists "$server/backend/public/assets/Page-new.js"
assert_lacks "$server/backend/var/asset-retention.tsv" 'Page-old.js'
end_case

begin_case 'a release with no built frontend leaves the live assets alone'
make_rsync_deploy
printf '%s Page-old.js\n' "$(($(date +%s) - 400 * 86400))" > "$server/backend/var/asset-retention.tsv"
rm -f "$laptop/release/backend/public/index.html"
run "$laptop/scripts/deploy-ssh.sh" --rsync
assert_succeeded
assert_exists "$server/backend/public/assets/Page-old.js"
end_case

begin_case 'a chunk that a rollback makes live again stops ageing'
live="$sandbox/live"
present="$sandbox/present"
printf 'a.js\n' > "$live"
printf 'a.js\nb.js\n' > "$present"
printf '1000 a.js\n1000 b.js\n' > "$sandbox/ledger"
run "$scripts_dir/asset-retention.sh" "$live" "$present" "$sandbox/ledger" "$sandbox/next" "$sandbox/expired"
assert_succeeded
assert_lacks "$sandbox/next" 'a.js'
assert_lacks "$sandbox/expired" 'a.js'
assert_contains "$sandbox/expired" 'b.js'
end_case

begin_case 'asset retention never offers a strange file name for deletion'
live="$sandbox/live"
present="$sandbox/present"
printf 'a.js\n' > "$live"
printf '../../index.php\n-rf\n.htaccess\nname with space\nb.js\n' > "$present"
run "$scripts_dir/asset-retention.sh" "$live" "$present" "$sandbox/none" "$sandbox/next" "$sandbox/expired"
assert_succeeded
[ "$(cut -d' ' -f2- "$sandbox/next")" = 'b.js' ] || fail "Only b.js should be tracked: $(cat "$sandbox/next")"
[ ! -s "$sandbox/expired" ] || fail "Nothing should expire yet: $(cat "$sandbox/expired")"
end_case

begin_case 'asset retention refuses to judge the server against an empty build'
: > "$sandbox/live"
printf 'a.js\n' > "$sandbox/present"
run "$scripts_dir/asset-retention.sh" "$sandbox/live" "$sandbox/present" "$sandbox/none" "$sandbox/next" "$sandbox/expired"
assert_refused 'lists no assets'
assert_absent "$sandbox/expired"
end_case

# --- cases: server-deploy.sh --------------------------------------------

begin_case 'server deploy keeps previous chunks at their URLs and installs the new build'
make_git_server
mkdir -p "$server/backend/public/assets.previous"
run_server_deploy
assert_succeeded
assert_exists "$server/backend/public/assets/Page-old.js"
assert_exists "$server/backend/public/assets/Page-new.js"
assert_contains "$server/backend/public/index.html" 'new'
assert_contains "$server/backend/var/asset-retention.tsv" 'Page-old.js'
assert_absent "$server/backend/public/assets.previous"
end_case

begin_case 'server deploy removes a chunk once its retention period is over'
make_git_server
printf '%s Page-old.js\n' "$(($(date +%s) - 15 * 86400))" > "$server/backend/var/asset-retention.tsv"
run_server_deploy
assert_succeeded
assert_absent "$server/backend/public/assets/Page-old.js"
assert_exists "$server/backend/public/assets/Page-new.js"
end_case

begin_case 'server deploy applies the public-file policy even when the frontend is skipped'
make_git_server
run_server_deploy SKIP_FRONTEND=1
assert_succeeded
assert_absent "$server/backend/public/error.php"
cmp -s "$scripts_dir/deploy/htaccess.dist" "$server/backend/public/.htaccess" || fail 'The production .htaccess was not installed.'
end_case

begin_case 'server deploy replaces a symlinked .htaccess from the old manual instructions'
make_git_server
rm -f "$server/backend/public/.htaccess"
ln -s "$server/scripts/deploy/htaccess.dist" "$server/backend/public/.htaccess"
run_server_deploy SKIP_FRONTEND=1
assert_succeeded
[ ! -L "$server/backend/public/.htaccess" ] || fail 'The .htaccess is still a symlink.'
cmp -s "$scripts_dir/deploy/htaccess.dist" "$server/backend/public/.htaccess" || fail 'The production .htaccess was not installed.'
end_case

begin_case 'git deploy clears a local .htaccess before pulling, and passes the retention period'
make_git_server
laptop="$sandbox/laptop"
install_scripts "$laptop"
write_deploy_env "$laptop" 'ASSET_RETENTION_DAYS=3' "SSH_WEB_USER=$(id -un)"
write_stub git <<'EOF'
printf 'git %s\n' "$*" >> "$EVENTS"
EOF
write_script "$server/scripts/server/server-deploy.sh" <<'EOF'
printf 'retention=%s\n' "$ASSET_RETENTION_DAYS" >> "$EVENTS"
EOF
run "$laptop/scripts/deploy-ssh.sh"
assert_succeeded
assert_order "$events" 'git checkout -- backend/public/.htaccess' 'git pull --ff-only'
assert_contains "$events" 'retention=3'
end_case

# --- cases: deploy-ftp.sh and the backup gate ---------------------------

begin_case 'deploy.sh checks the backup confirmation before building or uploading'
laptop="$sandbox/laptop"
server="$sandbox/unused"
install_scripts "$laptop"
replace_steps_with_recorders
write_deploy_env "$laptop" 'BACKUP_CONFIRMED=0'
run "$laptop/scripts/deploy.sh" --yes
assert_refused 'BACKUP_CONFIRMED=1'
[ ! -s "$events" ] || fail "A step ran before the backup was confirmed: $(cat "$events")"
write_deploy_env "$laptop" 'BACKUP_CONFIRMED=1'
run "$laptop/scripts/deploy.sh" --yes
assert_succeeded
assert_order "$events" 'step-build-release' 'step-deploy-ftp'
assert_order "$events" 'step-deploy-ftp' 'step-post-deploy'
end_case

begin_case 'deploy.sh reads the confirmation the way post-deploy.sh does'
laptop="$sandbox/laptop"
server="$sandbox/unused"
install_scripts "$laptop"
replace_steps_with_recorders
write_deploy_env "$laptop" 'BACKUP_CONFIRMED=0'
run env BACKUP_CONFIRMED=1 "$laptop/scripts/deploy.sh" --yes
assert_refused 'BACKUP_CONFIRMED=1'
[ ! -s "$events" ] || fail "A step ran although post-deploy.sh would refuse: $(cat "$events")"
end_case

begin_case 'deploy-ftp.sh uploads nothing until a backup is confirmed'
make_ftp_deploy 'BACKUP_CONFIRMED=0'
run_ftp
assert_refused 'BACKUP_CONFIRMED=1'
assert_lacks "$events" 'docker-run'
run_ftp --backend-only
assert_refused 'BACKUP_CONFIRMED=1'
assert_lacks "$events" 'docker-run'
end_case

begin_case 'deploy-ftp.sh does not ask for a backup for a dry run or a frontend-only upload'
make_ftp_deploy 'BACKUP_CONFIRMED=0'
run_ftp --dry-run
assert_succeeded
assert_contains "$events" 'docker-run'
: > "$events"
run_ftp --frontend-only
assert_succeeded
assert_contains "$events" 'docker-run'
end_case

begin_case 'deploy-ftp.sh uploads assets first and never deletes them in the mirror, even with --delete'
make_ftp_deploy 'BACKUP_CONFIRMED=1'
run_ftp --delete
assert_succeeded
docker_log="$events.docker"
assert_order "$docker_log" 'public/assets/ /site/backend/public/assets/' '--exclude-glob public/assets/'
assert_contains "$docker_log" 'set cmd:fail-exit yes'
assert_contains "$docker_log" '--exclude-glob var/quarantine/'
assert_contains "$docker_log" '--exclude-glob var/asset-retention.tsv'
if grep -F 'public/assets/ /site/backend/public/assets/' "$docker_log" | grep -qF -- '--delete'; then
  fail 'The asset upload mirrors with --delete.'
fi
end_case

begin_case 'deploy-ftp.sh excludes assets from the main mirror in every scope'
make_ftp_deploy 'BACKUP_CONFIRMED=1'
run_ftp --frontend-only --delete
assert_succeeded
assert_contains "$events.docker" '--exclude-glob assets/'
end_case

# Execute generated mirror commands with lftp's local transport. Credentials,
# open commands and all other network operations remain inside the Docker stub.
for ftp_scope in all frontend backend; do
  begin_case "FTP $ftp_scope deletion preserves originals and staged chunks"
  make_ftp_deploy 'BACKUP_CONFIRMED=1'
  make_server
  mkdir -p "$server/backend/var/upload-staging/1/upload-a"
  printf 'partial upload\n' > "$server/backend/var/upload-staging/1/upload-a/chunk_0"
  printf 'obsolete\n' > "$server/backend/public/obsolete.html"
  printf 'FTP_REMOTE_ROOT=%q\n' "$server" >> "$laptop/scripts/.env.deploy"
  write_stub docker <<EOF
for script; do :; done
while IFS= read -r command; do
  case "\$command" in
    mirror\ *) "$real_lftp" -c "set cmd:fail-exit yes; open file:///; \$command" || exit ;;
  esac
done <<< "\$script"
EOF
  ftp_args=(--delete)
  if [ "$ftp_scope" != all ]; then ftp_args+=("--$ftp_scope-only"); fi
  run_ftp "${ftp_args[@]}"
  assert_succeeded
  assert_exists "$server/backend/public/uploads/comics/1/a.cbz"
  assert_exists "$server/backend/var/upload-staging/1/upload-a/chunk_0"
  assert_exists "$server/backend/var/quarantine/comics/1/original.cbz"
  assert_exists "$server/backend/public/assets/Page-new.js"
  assert_absent "$server/backend/public/obsolete.html"
  end_case
done

begin_case 'deploy-ftp.sh removes a stale error.php, but not on a dry run'
make_ftp_deploy 'BACKUP_CONFIRMED=1'
run_ftp
assert_succeeded
assert_contains "$events.docker" 'rm -f /site/backend/public/error.php'
rm -f "$events.docker"
run_ftp --dry-run
assert_succeeded
assert_lacks "$events.docker" 'rm -f'
end_case

begin_case 'deploy-ftp.sh uploads the production .htaccess with the release'
make_ftp_deploy 'BACKUP_CONFIRMED=1'
run_ftp
assert_succeeded
assert_lacks "$events.docker" 'htaccess'
assert_contains "$events.docker" 'release/ /site/'
end_case

begin_case 'deploy-ftp.sh keeps previous chunks, then removes them after the period'
make_ftp_deploy 'BACKUP_CONFIRMED=1'
printf 'Page-new.js\nPage-old.js\nPage-older.js\n' > "$sandbox/ftp-present"
printf '%s Page-older.js\n' "$(($(date +%s) - 15 * 86400))" > "$sandbox/ftp-ledger"
run_ftp
assert_succeeded
assert_contains "$events.docker" 'rm -f /site/backend/public/assets/Page-older.js'
assert_lacks "$events.docker" 'assets/Page-old.js'
assert_lacks "$events.docker" 'assets/Page-new.js'
assert_contains "$events.docker" 'put /repo/release/.asset-retention.next -o /site/backend/var/asset-retention.tsv'
assert_contains "$events.ledger" 'Page-old.js'
assert_lacks "$events.ledger" 'Page-older.js'
assert_absent "$laptop/release/.asset-retention.next"
end_case

begin_case 'deploy-ftp.sh still succeeds when only the cleanup fails, and deletes nothing'
make_ftp_deploy 'BACKUP_CONFIRMED=1'
printf 'Page-older.js\n' > "$sandbox/ftp-present"
printf '%s Page-older.js\n' "$(($(date +%s) - 15 * 86400))" > "$sandbox/ftp-ledger"
write_stub docker <<'EOF'
for script; do :; done
printf '%s\n----\n' "$script" >> "$EVENTS.docker"
case "$script" in *'cls -1'*) exit 1 ;; esac
EOF
run_ftp
assert_succeeded
assert_output_contains 'Could not prune'
assert_lacks "$events.docker" 'Page-older.js'
end_case

begin_case 'deploy-ftp.sh fails when the upload fails'
make_ftp_deploy 'BACKUP_CONFIRMED=1'
run env FAKE_DOCKER_EXIT=1 FAKE_FTP_RELEASE="$laptop/release" "$laptop/scripts/deploy-ftp.sh"
assert_refused 'lftp mirror failed'
end_case

# --- cases: post-deploy.sh ----------------------------------------------

begin_case 'post-deploy over SSH runs in backend/ with the documented SSH_REMOTE_PATH'
make_rsync_deploy 'BACKUP_CONFIRMED=1'
run "$laptop/scripts/post-deploy.sh" --ssh --action health --action migrate --action cache-clear
assert_succeeded
assert_contains "$events" "php cwd=$server/backend args=-r echo PHP_VERSION;"
assert_contains "$events" "php cwd=$server/backend args=bin/console doctrine:migrations:migrate"
assert_contains "$events" "php cwd=$server/backend args=bin/console cache:clear --env=prod"
assert_contains "$events" 'ssh-args -p 2222 -i /keys/deploy deploy@test.invalid'
end_case

begin_case 'post-deploy over SSH handles a trailing slash and a path with a space'
make_rsync_deploy
mkdir -p "$sandbox/with space/comics/backend"
printf 'SSH_REMOTE_PATH=%q\n' "$sandbox/with space/comics/" >> "$laptop/scripts/.env.deploy"
run "$laptop/scripts/post-deploy.sh" --ssh --action health
assert_succeeded
assert_contains "$events" "php cwd=$sandbox/with space/comics/backend args=-r"
end_case

begin_case 'post-deploy over SSH falls back to the FTP root when SSH_REMOTE_PATH is unset'
make_rsync_deploy
mkdir -p "$sandbox/ftp-root/backend"
printf 'SSH_REMOTE_PATH=\nFTP_REMOTE_ROOT=%q\n' "$sandbox/ftp-root" >> "$laptop/scripts/.env.deploy"
run "$laptop/scripts/post-deploy.sh" --ssh --action health
assert_succeeded
assert_contains "$events" "php cwd=$sandbox/ftp-root/backend args=-r"
end_case

# --- cases: _post-deploy.php --------------------------------------------

make_runner() {
  runner="$sandbox/backend"
  mkdir -p "$runner/public" "$runner/vendor"
  cp "$scripts_dir/deploy/_post-deploy.php.dist" "$runner/public/_post-deploy.php"
  # What the real autoload_runtime.php does when the calling script returns
  # nothing: symfony/runtime requires it again, then rejects what comes back.
  cat > "$runner/vendor/autoload_runtime.php" <<'EOF'
<?php throw new TypeError('The runtime expected an application.');
EOF
  printf '<?php\n' > "$runner/vendor/autoload.php"
  cat > "$runner/.env.local.php" <<'EOF'
<?php return ['APP_ENV' => 'prod', 'DEPLOY_TOKEN' => 'right', 'DATABASE_URL' => 'mysql://x'];
EOF
}

run_runner() {
  last_output="$(timeout 60 env HTTP_X_DEPLOY_TOKEN="$1" "$real_php" -d display_errors=1 "$runner/public/_post-deploy.php" 2>&1 </dev/null)"
  last_exit=$?
}

begin_case 'the post-deploy runner finishes its action without the Symfony runtime'
make_runner
run_runner right
assert_succeeded
assert_output_contains 'OK'
assert_output_contains 'DATABASE_URL set: yes'
assert_output_contains '[post-deploy] done:'
case "$last_output" in *TypeError*) fail "The runner still loads the Symfony runtime: $last_output" ;; esac
end_case

begin_case 'the post-deploy runner still rejects a wrong token'
make_runner
run_runner wrong
assert_output_contains 'Not found.'
case "$last_output" in *'[post-deploy] done:'*) fail 'A wrong token ran the action.' ;; esac
end_case

# --- cases: server-install.sh -------------------------------------------

make_install() {
  app="$sandbox/app"
  install_scripts "$app"
  mkdir -p "$app/backend"
  write_stub git <<'EOF'
EOF
  write_stub composer <<'EOF'
EOF
  write_stub node <<'EOF'
EOF
  # Behaves as PHP does: php -m and extension_loaded both go by the registered
  # module name, which for OPcache is "Zend OPcache" and not "opcache".
  write_stub php <<'EOF'
extensions="${FAKE_PHP_EXTENSIONS:-pdo_mysql,intl,mbstring,zip,zlib,xsl,gd,Zend OPcache}"
case "$1" in
  -m) printf '%s\n' "$extensions" | tr ',' '\n' ;;
  -r)
    case "$2" in
      *PHP_MAJOR_VERSION*) printf '8.3' ;;
      *gd_info*) printf 'yes' ;;
      *extension_loaded*)
        printf ',%s,\n' "$extensions" | grep -qiF ",$4," ;;
    esac
    ;;
esac
EOF
}

begin_case 'the installer accepts a host whose PHP reports Zend OPcache'
make_install
run env APP_DIR="$app" "$app/scripts/server/server-install.sh"
assert_succeeded
case "$last_output" in *'not loaded'*) fail "A complete host was rejected: $last_output" ;; esac
end_case

begin_case 'the installer still stops when an extension really is missing'
make_install
run env APP_DIR="$app" FAKE_PHP_EXTENSIONS='pdo_mysql,intl,mbstring,zip,zlib,xsl,Zend OPcache' "$app/scripts/server/server-install.sh"
assert_refused "PHP extension 'gd' not loaded"
run env APP_DIR="$app" FAKE_PHP_EXTENSIONS='pdo_mysql,intl,mbstring,zip,zlib,xsl,gd' "$app/scripts/server/server-install.sh"
assert_refused "PHP extension 'Zend OPcache' not loaded"
end_case

begin_case 'the installer asks real PHP for a name real PHP knows'
if "$real_php" -m | grep -qi '^Zend OPcache$'; then
  # shellcheck disable=SC2016 # PHP source, not a shell expansion.
  "$real_php" -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' -- 'Zend OPcache' || fail 'Real PHP does not answer to the name the installer uses.'
fi
assert_contains "$scripts_dir/server/server-install.sh" '"Zend OPcache"'
end_case

begin_case 'the install template keeps login throttling on and configurable'
make_install
run env APP_DIR="$app" "$app/scripts/server/server-install.sh"
assert_succeeded
assert_contains "$app/backend/.env.local" 'LOGIN_RATE_LIMIT_ENABLED=1'
end_case

begin_case 'the second install round passes the public URL from the env file to the first build'
make_install
printf 'APP_URL=https://from-env-file.invalid\r\n' > "$app/backend/.env.local"
write_script "$app/scripts/server/server-deploy.sh" <<'EOF'
printf 'build app_url=%s\n' "$APP_URL" >> "$EVENTS"
EOF
run env -u APP_URL APP_DIR="$app" "$app/scripts/server/server-install.sh"
assert_succeeded
assert_contains "$events" 'build app_url=https://from-env-file.invalid'
end_case

# --- cases: build-release.sh --------------------------------------------

make_build_checkout() {
  checkout="$sandbox/checkout"
  mkdir -p "$checkout/backend/public" "$checkout/frontend"
  install_scripts "$checkout"
  printf '<?php\n' > "$checkout/backend/public/index.php"
  printf '{}\n' > "$checkout/backend/composer.json"
  printf 'scripts/.env.deploy\nrelease/\n' > "$checkout/.gitignore"
  git -C "$checkout" init -q -b main
  git -C "$checkout" add .
  git -C "$checkout" -c user.name=test -c user.email=test@test.invalid commit -q -m initial
  git init -q --bare "$sandbox/origin.git"
  git -C "$checkout" remote add origin "$sandbox/origin.git"
  git -C "$checkout" push -q origin main
  write_stub docker <<'EOF'
EOF
  {
    printf 'PUBLIC_URL=https://test.invalid\nDEPLOY_CONFIG_MODE=compiled\n'
    printf 'PROD_APP_SECRET=secret\nPROD_APP_DATA_KEY=key\nPROD_DATABASE_URL=mysql://a:b@c/d\n'
    printf 'PROD_CORS_ALLOW_ORIGIN=^https://test\\.invalid$\nPOST_DEPLOY_TOKEN=token\n'
    printf '%s\n' "$@"
  } > "$checkout/scripts/.env.deploy"
}

begin_case 'a compiled Analytics-only build needs no AdSense client'
make_build_checkout PROD_GOOGLE_ANALYTICS_ENABLED=true PROD_GOOGLE_ANALYTICS_MEASUREMENT_ID=G-ABC123 PROD_ADSENSE_ENABLED=false PROD_ADSENSE_CLIENT=
run "$checkout/scripts/build-release.sh" --skip-frontend
assert_succeeded
assert_contains "$checkout/release/backend/.env.prod.local" 'GOOGLE_ANALYTICS_ENABLED="true"'
assert_contains "$checkout/release/backend/.env.prod.local" 'ADSENSE_CLIENT=""'
end_case

begin_case 'a compiled build with advertising on still needs a valid AdSense client'
make_build_checkout PROD_ADSENSE_ENABLED=true PROD_ADSENSE_CLIENT=
run "$checkout/scripts/build-release.sh" --skip-frontend
assert_refused 'PROD_ADSENSE_CLIENT must be ca-pub-'
end_case

begin_case 'a compiled build with Analytics on still needs a valid measurement id'
make_build_checkout PROD_GOOGLE_ANALYTICS_ENABLED=true PROD_GOOGLE_ANALYTICS_MEASUREMENT_ID=UA-1
run "$checkout/scripts/build-release.sh" --skip-frontend
assert_refused 'PROD_GOOGLE_ANALYTICS_MEASUREMENT_ID must be G-'
end_case

begin_case 'a release carries the production .htaccess and the post-deploy runner'
make_build_checkout
run "$checkout/scripts/build-release.sh" --skip-frontend
assert_succeeded
cmp -s "$scripts_dir/deploy/htaccess.dist" "$checkout/release/backend/public/.htaccess" || fail 'The release lacks the production .htaccess.'
assert_exists "$checkout/release/backend/public/_post-deploy.php"
assert_absent "$checkout/release/backend/public/error.php"
end_case

# --- cases: what the repository itself ships ----------------------------

begin_case 'the repository ships no public diagnostic page'
[ ! -e "$repo_dir/backend/public/error.php" ] || fail 'backend/public/error.php is back.'
end_case

begin_case 'a git checkout carries the production rules, identical to the file releases use'
cmp -s "$scripts_dir/deploy/htaccess.dist" "$repo_dir/backend/public/.htaccess" \
  || fail 'backend/public/.htaccess has drifted from scripts/deploy/htaccess.dist.'
end_case

# --- results ------------------------------------------------------------

printf '\nPassed: %d   Failed: %d\n' "$passed" "$failed"
[ "$failed" -eq 0 ] || exit 1
exit 0
