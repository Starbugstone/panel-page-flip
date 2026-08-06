# CBR to CBZ conversion tools

Panel Page Flip reads CBZ archives. CBZ is a ZIP file; CBR is a RAR file, and
RAR is not a format the server can be relied upon to open. These two optional
scripts rebuild CBR archives as CBZ archives **on your own computer**, so you
can upload the result.

| Script | Platform | Download |
| --- | --- | --- |
| `Convert-CbrToCbz.ps1` | Windows (PowerShell 5.1+) | `frontend/public/tools/convert-cbr-to-cbz-windows.zip` |
| `convert-cbr-to-cbz.sh` | Linux and macOS (bash) | `frontend/public/tools/convert-cbr-to-cbz-linux.zip` |

Both are offered from the bottom of the Settings page in the app. The sources
here are what the downloads are built from — see "Building the downloads" below.

## What they do

For every `.cbr` file in one folder, and nothing else:

1. Extract it into a unique temporary directory.
2. Build a genuine ZIP archive from what came out.
3. Name that archive `.cbz`, next to the original.

They do **not** rename a RAR file to `.cbz`; that produces a file the reader
cannot open. They do **not** recurse into subfolders, touch `.cbz` or unrelated
files, or delete anything. The original `.cbr` files are left exactly where they
are.

## Requirements

[7-Zip](https://www.7-zip.org/) — it is what reads the RAR archives. Neither
script bundles or redistributes it.

On Linux, RAR support may be a separate package:

```sh
sudo apt install p7zip-full p7zip-rar    # Debian / Ubuntu
sudo dnf install p7zip p7zip-plugins     # Fedora
brew install sevenzip                    # macOS
```

## Usage

### Windows

```powershell
# Windows blocks scripts downloaded from the internet until you unblock them.
Unblock-File .\Convert-CbrToCbz.ps1

.\Convert-CbrToCbz.ps1                              # convert this folder
.\Convert-CbrToCbz.ps1 -Path 'D:\Comics' -Overwrite # another folder, replacing existing .cbz
.\Convert-CbrToCbz.ps1 -SevenZipPath 'D:\7z\7z.exe' # a portable 7-Zip
```

If your execution policy still refuses, run that one process with it relaxed —
read the script first:

```powershell
powershell -ExecutionPolicy Bypass -File .\Convert-CbrToCbz.ps1
```

Do not weaken the machine-wide execution policy for this.

### Linux and macOS

```sh
chmod +x convert-cbr-to-cbz.sh

./convert-cbr-to-cbz.sh                       # convert this folder
./convert-cbr-to-cbz.sh -p ~/Comics --overwrite
./convert-cbr-to-cbz.sh --seven-zip /opt/7zz
```

Both scripts print a `Converted / Skipped / Failed` summary and exit non-zero if
anything failed.

## Disclaimer

These are optional convenience tools supplied without warranty. They only create
files on your own computer. Original CBR files are kept, but keep backups anyway
and check the generated CBZ files open correctly before deleting anything.
Password-protected, damaged, multipart and otherwise unusual RAR archives may
fail to convert.

## Tests

No test framework to install; both suites need only their platform's shell and
7-Zip.

```powershell
powershell -ExecutionPolicy Bypass -File .\tests\Test-ConvertCbrToCbz.ps1
```

```sh
./tests/test-convert-cbr-to-cbz.sh
```

Creating a real RAR archive needs a RAR compressor, which is proprietary and not
assumed here, so the conversion cases use a ZIP file named `.cbr`. 7-Zip detects
archive format from content, so the scripts take exactly the same path through
extract, rebuild and rename that a real CBR does.

Run the bash suite on Linux or macOS. Under Git Bash on Windows it fails
spuriously: MSYS rewrites the `-o/tmp/...` argument before the Windows `7z.exe`
sees it, and the extraction lands somewhere else.

## Building the downloads

The app serves each script as a zip so a browser cannot try to render or run it.
After changing either script, rebuild the bundles and the checksums the Settings
page displays:

```sh
cd frontend
npm run build:tools
```

That regenerates `public/tools/*.zip` and `src/lib/conversion-tools.js`, both of
which are committed. `npm run check:tools` verifies they match the sources
without writing anything, which is what CI should run.
