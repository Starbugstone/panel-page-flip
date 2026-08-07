#!/usr/bin/env bash
#
# Tests for convert-cbr-to-cbz.sh.
#
# Dependency-free on purpose: it needs bash and 7-Zip, which is what the script
# under test already requires. Everything happens in a temporary directory that
# is removed afterwards.
#
# One caveat worth stating: creating a real RAR archive needs a RAR compressor,
# which is not free software and is not assumed here. The "converts an archive"
# cases therefore use a ZIP file named .cbr. 7-Zip detects archive format from
# content, so the script takes exactly the same path through extract, rebuild
# and rename that a real CBR does.
#
# Usage: ./test-convert-cbr-to-cbz.sh

set -uo pipefail

tests_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
script_under_test="$(dirname -- "$tests_dir")/convert-cbr-to-cbz.sh"

passed=0
failed=0
sandbox=""
last_output=""
last_exit=0

find_seven_zip() {
  local candidate
  for candidate in 7z 7zz 7za; do
    if command -v "$candidate" >/dev/null 2>&1; then
      printf '%s' "$candidate"
      return 0
    fi
  done
  return 1
}

if ! seven_zip="$(find_seven_zip)"; then
  printf '7-Zip is required to run these tests. Install p7zip-full (or sevenzip).\n' >&2
  exit 2
fi

# Homebrew's coreutils installs GNU timeout as gtimeout to keep it clear of the
# BSD tools, so macOS is checked under both names.
find_timeout() {
  local candidate
  for candidate in timeout gtimeout; do
    if command -v "$candidate" >/dev/null 2>&1; then
      printf '%s' "$candidate"
      return 0
    fi
  done
  return 1
}

# Every run of the script under test is bounded, so there is no point starting a
# suite that cannot bound them: an unbounded run would hang instead of failing,
# which is the one outcome this whole arrangement exists to avoid.
if ! timeout_cmd="$(find_timeout)"; then
  printf 'A timeout command is required to run these tests. Install coreutils (gtimeout on macOS).\n' >&2
  exit 2
fi

# A small ZIP archive under the given name: stands in for a CBR.
make_fake_comic() {
  local target="$1" pages="${2:-2}" stage i status
  stage="$(mktemp -d "${TMPDIR:-/tmp}/cbr2cbz_stage.XXXXXXXX")"
  for (( i = 1; i <= pages; i++ )); do
    printf 'page %d\n' "$i" > "$(printf '%s/page%02d.txt' "$stage" "$i")"
  done
  "$seven_zip" a -tzip -mx=0 -bso0 -bsp0 -- "$target" "${stage}/*" >/dev/null 2>&1
  status=$?
  rm -rf -- "$stage"

  # A fixture that failed to build would surface as a confusing assertion
  # failure about the script under test, so it stops the run instead.
  if [ $status -ne 0 ]; then
    printf 'Could not build the test fixture %s (7-Zip exit %d).\n' "$target" "$status" >&2
    exit 2
  fi
}

# Bounded, so a regression that makes the script wait forever fails the suite
# rather than hanging it.
run_script() {
  last_output="$("$timeout_cmd" 60 "$script_under_test" "$@" 2>&1)"
  last_exit=$?
}

# Temporary working directories the script leaves behind, which should be none.
count_leaked_temp_dirs() {
  find "${TMPDIR:-/tmp}" -maxdepth 1 -type d -name 'cbr2cbz.*' 2>/dev/null | wc -l | tr -d ' '
}

fail() {
  printf '  FAIL  %s\n' "$current_case"
  printf '        %s\n' "$1"
  failed=$((failed + 1))
  case_ok=0
}

assert_true() {
  [ "$1" -eq 0 ] 2>/dev/null || { fail "$2"; return 1; }
  return 0
}

assert_contains() {
  case "$last_output" in
    *"$1"*) return 0 ;;
    *) fail "${2:-Expected output to contain: $1}"; return 1 ;;
  esac
}

begin_case() {
  current_case="$1"
  case_ok=1
  sandbox="$(mktemp -d "${TMPDIR:-/tmp}/cbr2cbz_test.XXXXXXXX")"
}

end_case() {
  rm -rf -- "$sandbox"
  if [ "$case_ok" -eq 1 ]; then
    printf '  PASS  %s\n' "$current_case"
    passed=$((passed + 1))
  fi
}

printf 'Testing %s\n' "$script_under_test"
printf 'Using 7-Zip: %s\n\n' "$seven_zip"

leaked_before="$(count_leaked_temp_dirs)"

# --- cases --------------------------------------------------------------

begin_case 'converts one archive and leaves the original in place'
make_fake_comic "$sandbox/Simple Comic 001.cbr"
run_script -p "$sandbox"
assert_true "$last_exit" "Expected exit code 0, got $last_exit. $last_output"
[ -f "$sandbox/Simple Comic 001.cbz" ] || fail 'The CBZ was not created.'
[ -f "$sandbox/Simple Comic 001.cbr" ] || fail 'The original CBR was removed.'
assert_contains 'Converted: 1'
end_case

begin_case 'produces a genuine ZIP rather than a renamed archive'
make_fake_comic "$sandbox/Zip Check 001.cbr" 3
run_script -p "$sandbox"
signature="$(head -c 2 "$sandbox/Zip Check 001.cbz")"
[ "$signature" = "PK" ] || fail 'The CBZ does not start with the ZIP signature PK.'
listing="$("$seven_zip" l "$sandbox/Zip Check 001.cbz" 2>/dev/null)"
for page in page01.txt page02.txt page03.txt; do
  case "$listing" in
    *"$page"*) ;;
    *) fail "The CBZ is missing $page." ;;
  esac
done
end_case

begin_case 'handles spaces, parentheses and apostrophes in filenames'
make_fake_comic "$sandbox/The Hero's Return (2011) Vol 1 & 2.cbr"
run_script -p "$sandbox"
assert_true "$last_exit" "Expected exit code 0, got $last_exit. $last_output"
[ -f "$sandbox/The Hero's Return (2011) Vol 1 & 2.cbz" ] || fail 'The CBZ was not created.'
end_case

begin_case 'ignores existing CBZ files and unrelated files'
make_fake_comic "$sandbox/Already A Cbz.cbz"
printf 'leave me alone\n' > "$sandbox/notes.txt"
printf 'not a comic\n' > "$sandbox/cover.jpg"
run_script -p "$sandbox"
assert_true "$last_exit" "Expected exit code 0, got $last_exit."
assert_contains 'No CBR files found'
[ "$(find "$sandbox" -maxdepth 1 -type f | wc -l | tr -d ' ')" = "3" ] || fail 'The folder contents changed.'
[ "$(cat "$sandbox/notes.txt")" = "leave me alone" ] || fail 'An unrelated file was modified.'
end_case

begin_case 'skips an archive whose CBZ already exists, without overwriting it'
make_fake_comic "$sandbox/Existing 001.cbr"
printf 'do not touch\n' > "$sandbox/Existing 001.cbz"
run_script -p "$sandbox"
assert_true "$last_exit" "Expected exit code 0, got $last_exit."
assert_contains 'Skipped: 1'
[ "$(cat "$sandbox/Existing 001.cbz")" = "do not touch" ] || fail 'The existing CBZ was overwritten.'
end_case

begin_case 'replaces an existing CBZ when --overwrite is given'
make_fake_comic "$sandbox/Existing 001.cbr"
printf 'stale\n' > "$sandbox/Existing 001.cbz"
run_script -p "$sandbox" --overwrite
assert_true "$last_exit" "Expected exit code 0, got $last_exit. $last_output"
assert_contains 'Converted: 1'
# Checking the ZIP signature rather than diffing the placeholder text: the
# replacement is binary, and reading it into a variable would drop null bytes.
[ "$(head -c 2 "$sandbox/Existing 001.cbz")" = "PK" ] || fail 'The existing CBZ was not replaced with a real archive.'
end_case

begin_case 'reports a damaged archive as failed and keeps the source'
printf 'this is not an archive at all\n' > "$sandbox/Damaged 001.cbr"
run_script -p "$sandbox"
[ "$last_exit" -eq 1 ] || fail "Expected exit code 1 for a failed conversion, got $last_exit."
assert_contains 'Failed: 1'
[ -f "$sandbox/Damaged 001.cbr" ] || fail 'The damaged source archive was removed.'
[ ! -e "$sandbox/Damaged 001.cbz" ] || fail 'A CBZ was left behind for a failed conversion.'
end_case

begin_case 'counts converted, skipped and failed across several files'
make_fake_comic "$sandbox/Good 001.cbr"
make_fake_comic "$sandbox/Good 002.cbr"
make_fake_comic "$sandbox/Skipped 001.cbr"
printf 'already here\n' > "$sandbox/Skipped 001.cbz"
printf 'garbage\n' > "$sandbox/Broken 001.cbr"
run_script -p "$sandbox"
assert_contains 'Converted: 2   Skipped: 1   Failed: 1' "Unexpected summary. $last_output"
[ "$last_exit" -eq 1 ] || fail 'A run containing a failure should exit non-zero.'
end_case

begin_case 'explains how to install 7-Zip when it cannot be found'
make_fake_comic "$sandbox/Any 001.cbr"
run_script -p "$sandbox" --seven-zip "$sandbox/no-such-7z"
[ "$last_exit" -eq 1 ] || fail "Expected exit code 1, got $last_exit."
assert_contains '7-Zip was not found'
[ ! -e "$sandbox/Any 001.cbz" ] || fail 'A CBZ was produced without 7-Zip.'
end_case

begin_case 'reports a missing folder instead of converting the wrong one'
run_script -p "$sandbox/does-not-exist"
[ "$last_exit" -eq 1 ] || fail "Expected exit code 1, got $last_exit."
assert_contains 'Folder not found'
end_case

begin_case 'rejects an unknown option instead of guessing'
run_script -p "$sandbox" --delete-originals
[ "$last_exit" -eq 2 ] || fail "Expected exit code 2, got $last_exit."
assert_contains 'Unknown option'
end_case

begin_case 'rejects a value-taking option with no value instead of hanging'
run_script -p
[ "$last_exit" -eq 2 ] || fail "Expected exit code 2, got $last_exit."
assert_contains 'needs a value'
run_script --seven-zip
[ "$last_exit" -eq 2 ] || fail "Expected exit code 2, got $last_exit."
end_case

begin_case 'prints only the header comment for --help'
run_script --help
assert_true "$last_exit" "Expected exit code 0, got $last_exit."
assert_contains 'Usage:'
case "$last_output" in
  *'set -uo pipefail'*) fail 'The help text leaked script code.' ;;
esac
end_case

# --- results ------------------------------------------------------------

leaked_after="$(count_leaked_temp_dirs)"
if [ "$leaked_after" -gt "$leaked_before" ]; then
  printf '  FAIL  no temporary directories are left behind\n'
  printf '        %d cbr2cbz.* directories remain\n' "$((leaked_after - leaked_before))"
  failed=$((failed + 1))
else
  printf '  PASS  no temporary directories are left behind\n'
  passed=$((passed + 1))
fi

printf '\nPassed: %d   Failed: %d\n' "$passed" "$failed"
[ "$failed" -eq 0 ] || exit 1
exit 0
