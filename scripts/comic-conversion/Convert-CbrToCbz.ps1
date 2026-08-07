<#
.SYNOPSIS
    Converts CBR comic archives into CBZ archives.

.DESCRIPTION
    Panel Page Flip reads CBZ (ZIP) archives. This script rebuilds every CBR
    (RAR) archive in a folder as a genuine ZIP archive named .cbz. It runs
    entirely on your own computer, makes no network requests, and never deletes
    or modifies the original CBR files.

    7-Zip must be installed; it is what reads the RAR archives. The script does
    not bundle or redistribute it.

.PARAMETER Path
    Folder to convert. Defaults to the folder the script itself is in, so the
    common case is "drop it next to the comics and run it".

.PARAMETER Overwrite
    Replace an existing .cbz. Without this, an archive whose .cbz already exists
    is reported as skipped and left alone.

.PARAMETER SevenZipPath
    Full path to 7z.exe, for a portable install. Normally left unset: the script
    looks on PATH and then in the standard install folders.

.EXAMPLE
    .\Convert-CbrToCbz.ps1

.EXAMPLE
    .\Convert-CbrToCbz.ps1 -Path 'D:\Comics\Inbox' -Overwrite

.NOTES
    Version:  1.0.0
    Licence:  Same as Panel Page Flip. Provided without warranty; keep backups
              and check the generated files before deleting the originals.
    Exit code 0 when nothing failed, 1 when at least one archive failed.
#>
[CmdletBinding()]
param(
    [string] $Path,
    [switch] $Overwrite,
    [string] $SevenZipPath
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

function Write-Failure {
    <#
        Plain text on stderr rather than Write-Error: this script is aimed at
        people who just want their comics converted, and an ErrorRecord wraps a
        one-line explanation in CategoryInfo noise and hard-wraps it mid-sentence.
    #>
    param([Parameter(Mandatory = $true)][string] $Message)

    [Console]::Error.WriteLine($Message)
}

function Resolve-SevenZip {
    <#
        Ordered lookup, so a machine-specific path never has to be hard-coded:
        an explicit -SevenZipPath, then PATH, then the two standard install
        folders. Returns the executable path or throws with instructions.
    #>
    param([string] $Explicit)

    if ($Explicit) {
        if (Test-Path -LiteralPath $Explicit -PathType Leaf) { return (Resolve-Path -LiteralPath $Explicit).Path }
        throw "7-Zip was not found at the path given with -SevenZipPath: $Explicit"
    }

    $onPath = Get-Command '7z.exe' -CommandType Application -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($onPath) { return $onPath.Source }

    foreach ($root in @($env:ProgramFiles, ${env:ProgramFiles(x86)})) {
        if (-not $root) { continue }
        $candidate = Join-Path $root '7-Zip\7z.exe'
        if (Test-Path -LiteralPath $candidate -PathType Leaf) { return $candidate }
    }

    throw @'
7-Zip was not found.

Install it from https://www.7-zip.org/ and run this script again, or point the
script at a portable copy:

    .\Convert-CbrToCbz.ps1 -SevenZipPath 'D:\Tools\7-Zip\7z.exe'
'@
}

function Invoke-SevenZip {
    <#
        Runs 7-Zip with its arguments as an array. Never builds a command string
        and never uses Invoke-Expression, so a comic called "Vol 1 & 2 (2011).cbr"
        cannot turn into something else on the way to the shell.
    #>
    param(
        [Parameter(Mandatory = $true)][string] $Executable,
        [Parameter(Mandatory = $true)][string[]] $Arguments
    )

    # $ErrorActionPreference = 'Stop' turns anything a native command writes to
    # stderr into a thrown NativeCommandError, which would report a 7-Zip
    # *warning* on an otherwise successful archive as a failed conversion — and
    # would throw right past the exit-code check below. Relaxed for the call so
    # stderr arrives as text and the exit code is what decides.
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & $Executable @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previous
    }

    return [pscustomobject]@{
        ExitCode = $exitCode
        Output   = ($output | Out-String).Trim()
    }
}

function Convert-OneArchive {
    param(
        [Parameter(Mandatory = $true)][System.IO.FileInfo] $Archive,
        [Parameter(Mandatory = $true)][string] $SevenZip,
        [Parameter(Mandatory = $true)][string] $Destination
    )

    # A unique directory per archive, so two runs (or two archives whose contents
    # share filenames) cannot mix their pages together.
    $workingDir = Join-Path ([System.IO.Path]::GetTempPath()) ("cbr2cbz_" + [guid]::NewGuid().ToString('N'))
    $stagedZip = "$Destination.partial"

    try {
        New-Item -ItemType Directory -Path $workingDir -Force | Out-Null

        $extract = Invoke-SevenZip -Executable $SevenZip -Arguments @(
            'x', $Archive.FullName, "-o$workingDir", '-y', '-bso0', '-bsp0'
        )
        if ($extract.ExitCode -ne 0) {
            throw "7-Zip could not read the archive (exit code $($extract.ExitCode)). $($extract.Output)"
        }

        $extracted = Get-ChildItem -LiteralPath $workingDir -Recurse -File
        if ($extracted.Count -eq 0) {
            throw 'The archive contained no files.'
        }

        # Rebuild as a real ZIP rather than renaming the RAR. Store rather than
        # deflate: comic pages are already-compressed images, so compressing them
        # again costs time and saves nothing.
        if (Test-Path -LiteralPath $stagedZip) { Remove-Item -LiteralPath $stagedZip -Force }
        $compress = Invoke-SevenZip -Executable $SevenZip -Arguments @(
            'a', '-tzip', '-mx=0', $stagedZip, (Join-Path $workingDir '*'), '-bso0', '-bsp0'
        )
        if ($compress.ExitCode -ne 0) {
            throw "7-Zip could not build the CBZ (exit code $($compress.ExitCode)). $($compress.Output)"
        }

        # Only now does the destination appear, so an interrupted run never
        # leaves a half-written .cbz that looks like a finished one.
        Move-Item -LiteralPath $stagedZip -Destination $Destination -Force
    }
    finally {
        # Runs after success and failure alike: no temporary directory is left
        # behind either way.
        if (Test-Path -LiteralPath $workingDir) {
            Remove-Item -LiteralPath $workingDir -Recurse -Force -ErrorAction SilentlyContinue
        }
        if (Test-Path -LiteralPath $stagedZip) {
            Remove-Item -LiteralPath $stagedZip -Force -ErrorAction SilentlyContinue
        }
    }
}

# --- main ---------------------------------------------------------------

$folder = if ($Path) { $Path } elseif ($PSScriptRoot) { $PSScriptRoot } else { (Get-Location).Path }

if (-not (Test-Path -LiteralPath $folder -PathType Container)) {
    Write-Failure "Folder not found: $folder"
    exit 1
}
$folder = (Resolve-Path -LiteralPath $folder).Path

try {
    $sevenZip = Resolve-SevenZip -Explicit $SevenZipPath
}
catch {
    Write-Failure $_.Exception.Message
    exit 1
}

Write-Host "Converting CBR archives in: $folder"
Write-Host "Using 7-Zip: $sevenZip"
Write-Host ''

# Only .cbr, only this folder. Filter (rather than -Include) so the match is
# done by the provider and cannot be widened by a wildcard in the folder name.
$archives = @(Get-ChildItem -LiteralPath $folder -File |
    Where-Object { $_.Extension -ieq '.cbr' } |
    Sort-Object Name)

if ($archives.Count -eq 0) {
    Write-Host 'No CBR files found here. Nothing to do.'
    exit 0
}

$converted = 0
$skipped = 0
$failed = 0

foreach ($archive in $archives) {
    $destination = Join-Path $folder ([System.IO.Path]::GetFileNameWithoutExtension($archive.Name) + '.cbz')

    if ((Test-Path -LiteralPath $destination) -and -not $Overwrite) {
        Write-Host "SKIP    $($archive.Name) - a CBZ of that name already exists"
        $skipped++
        continue
    }

    try {
        Convert-OneArchive -Archive $archive -SevenZip $sevenZip -Destination $destination
        Write-Host "OK      $($archive.Name)"
        $converted++
    }
    catch {
        # The source archive is never touched, so a failure costs nothing but time.
        Write-Failure "FAILED  $($archive.Name) - $($_.Exception.Message)"
        $failed++
    }
}

Write-Host ''
Write-Host "Converted: $converted   Skipped: $skipped   Failed: $failed"
Write-Host 'The original .cbr files have been left where they are.'

if ($failed -gt 0) { exit 1 }
exit 0
