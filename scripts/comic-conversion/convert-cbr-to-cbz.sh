#!/usr/bin/env bash
#
# Converts CBR comic archives into CBZ archives.
#
# Panel Page Flip reads CBZ (ZIP) archives. This script rebuilds every CBR (RAR)
# archive in a folder as a genuine ZIP archive named .cbz. It runs entirely on
# your own computer, makes no network requests, and never deletes or modifies
# the original CBR files.
#
# 7-Zip must be installed; it is what reads the RAR archives. On some
# distributions RAR support is a separate package (p7zip-rar / p7zip-full).
# The script does not bundle or redistribute 7-Zip.
#
# Usage:
#   ./convert-cbr-to-cbz.sh                    # convert the script's own folder
#   ./convert-cbr-to-cbz.sh -p ~/Comics        # convert another folder
#   ./convert-cbr-to-cbz.sh --overwrite        # replace existing .cbz files
#   ./convert-cbr-to-cbz.sh -s /opt/7zz        # use a specific 7-Zip binary
#
# Version: 1.0.0
# Provided without warranty; keep backups and check the generated files before
# deleting the originals.
# Exits 0 when nothing failed, 1 when at least one archive failed.

set -uo pipefail

VERSION="1.0.0"
target_dir=""
overwrite=0
seven_zip=""

usage() {
  sed -n '3,26p' "$0" | sed 's/^# \{0,1\}//'
}

while [ $# -gt 0 ]; do
  case "$1" in
    -p|--path)      target_dir="${2:-}"; shift 2 ;;
    -s|--seven-zip) seven_zip="${2:-}"; shift 2 ;;
    -o|--overwrite) overwrite=1; shift ;;
    -h|--help)      usage; exit 0 ;;
    -v|--version)   echo "$VERSION"; exit 0 ;;
    *) printf 'Unknown option: %s\n\n' "$1" >&2; usage >&2; exit 2 ;;
  esac
done

# Default to the folder the script itself lives in, so the common case is
# "drop it next to the comics and run it".
if [ -z "$target_dir" ]; then
  target_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
fi

if [ ! -d "$target_dir" ]; then
  printf 'Folder not found: %s\n' "$target_dir" >&2
  exit 1
fi
target_dir="$(cd -- "$target_dir" && pwd)"

# Ordered lookup so no machine-specific path has to be hard-coded: an explicit
# --seven-zip, then whichever of the usual command names is on PATH.
resolve_seven_zip() {
  if [ -n "$seven_zip" ]; then
    if [ -x "$seven_zip" ] || command -v "$seven_zip" >/dev/null 2>&1; then
      printf '%s' "$seven_zip"
      return 0
    fi
    printf '7-Zip was not found at the path given with --seven-zip: %s\n' "$seven_zip" >&2
    return 1
  fi

  local candidate
  for candidate in 7z 7zz 7za; do
    if command -v "$candidate" >/dev/null 2>&1; then
      printf '%s' "$candidate"
      return 0
    fi
  done

  cat >&2 <<'EOF'
7-Zip was not found.

Install it and run this script again, for example:

    sudo apt install p7zip-full p7zip-rar    # Debian / Ubuntu
    sudo dnf install p7zip p7zip-plugins     # Fedora
    brew install sevenzip                    # macOS

Or point the script at a specific binary:

    ./convert-cbr-to-cbz.sh --seven-zip /opt/7-zip/7zz
EOF
  return 1
}

if ! seven_zip="$(resolve_seven_zip)"; then
  exit 1
fi

printf 'Converting CBR archives in: %s\n' "$target_dir"
printf 'Using 7-Zip: %s\n\n' "$seven_zip"

converted=0
skipped=0
failed=0
found=0

# Rebuild one archive. Every path is quoted, so spaces, apostrophes, parentheses
# and non-ASCII characters in comic filenames are handled as-is.
convert_one() {
  local archive="$1" destination="$2"
  local work_dir staged status

  # A unique directory per archive, so two archives whose contents share
  # filenames cannot mix their pages together.
  work_dir="$(mktemp -d "${TMPDIR:-/tmp}/cbr2cbz.XXXXXXXX")" || return 1
  staged="${destination}.partial"
  status=0

  # Runs on success and failure alike: no temporary directory is left behind.
  cleanup() { rm -rf -- "$work_dir"; rm -f -- "$staged"; }
  trap 'cleanup' RETURN

  if ! "$seven_zip" x -y -bso0 -bsp0 "-o${work_dir}" -- "$archive" >/dev/null 2>&1; then
    printf '7-Zip could not read the archive.\n' >&2
    return 1
  fi

  if [ -z "$(find "$work_dir" -type f -print -quit)" ]; then
    printf 'The archive contained no files.\n' >&2
    return 1
  fi

  # Rebuild as a real ZIP rather than renaming the RAR. Stored rather than
  # deflated: comic pages are already-compressed images, so compressing them
  # again costs time and saves nothing. The wildcard stays quoted so 7-Zip
  # expands it relative to the working directory and the entries inside the
  # CBZ come out as "page01.jpg" rather than a copy of the temporary path.
  rm -f -- "$staged"
  if ! "$seven_zip" a -tzip -mx=0 -bso0 -bsp0 -- "$staged" "${work_dir}/*" >/dev/null 2>&1; then
    printf '7-Zip could not build the CBZ.\n' >&2
    return 1
  fi

  # Only now does the destination appear, so an interrupted run never leaves a
  # half-written .cbz that looks like a finished one.
  mv -f -- "$staged" "$destination" || status=1
  return $status
}

# Only .cbr, only this folder, no recursion. -print0 so odd filenames survive.
while IFS= read -r -d '' archive; do
  found=$((found + 1))
  name="$(basename -- "$archive")"
  destination="${archive%.*}.cbz"

  if [ -e "$destination" ] && [ "$overwrite" -eq 0 ]; then
    printf 'SKIP    %s - a CBZ of that name already exists\n' "$name"
    skipped=$((skipped + 1))
    continue
  fi

  if convert_one "$archive" "$destination"; then
    printf 'OK      %s\n' "$name"
    converted=$((converted + 1))
  else
    # The source archive is never touched, so a failure costs nothing but time.
    printf 'FAILED  %s\n' "$name" >&2
    failed=$((failed + 1))
  fi
done < <(find "$target_dir" -maxdepth 1 -type f \( -iname '*.cbr' \) -print0 | sort -z)

if [ "$found" -eq 0 ]; then
  printf 'No CBR files found here. Nothing to do.\n'
  exit 0
fi

printf '\nConverted: %d   Skipped: %d   Failed: %d\n' "$converted" "$skipped" "$failed"
printf 'The original .cbr files have been left where they are.\n'

[ "$failed" -eq 0 ] || exit 1
exit 0
