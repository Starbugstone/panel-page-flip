<#
.SYNOPSIS
    Tests for Convert-CbrToCbz.ps1.

.DESCRIPTION
    Deliberately dependency-free rather than Pester, so it runs on a stock
    Windows PowerShell 5.1 with nothing installed but 7-Zip — the same thing the
    script under test already requires.

    Everything happens in a fresh temporary directory that is removed afterwards.

    One caveat worth stating: creating a real RAR archive needs a RAR compressor,
    which is not free software and is not assumed here. The "converts an archive"
    cases therefore use a ZIP file named .cbr. 7-Zip detects archive format from
    content, so the script takes exactly the same path through extract, rebuild
    and rename that a real CBR does.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\Test-ConvertCbrToCbz.ps1
#>
[CmdletBinding()]
param()

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

$script:ScriptUnderTest = Join-Path (Split-Path -Parent $PSScriptRoot) 'Convert-CbrToCbz.ps1'
$script:Passed = 0
$script:Failed = 0

function Get-SevenZip {
    $onPath = Get-Command '7z.exe' -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($onPath) { return $onPath.Source }
    foreach ($root in @($env:ProgramFiles, ${env:ProgramFiles(x86)})) {
        if (-not $root) { continue }
        $candidate = Join-Path $root '7-Zip\7z.exe'
        if (Test-Path -LiteralPath $candidate -PathType Leaf) { return $candidate }
    }
    return $null
}

function New-Sandbox {
    $dir = Join-Path ([System.IO.Path]::GetTempPath()) ("cbr2cbz_test_" + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $dir -Force | Out-Null
    return $dir
}

function New-FakeComic {
    <# A small ZIP archive with the given extension: stands in for a CBR. #>
    param([string] $Directory, [string] $FileName, [int] $PageCount = 2)

    $stage = Join-Path ([System.IO.Path]::GetTempPath()) ("cbr2cbz_stage_" + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $stage -Force | Out-Null
    try {
        for ($i = 1; $i -le $PageCount; $i++) {
            Set-Content -LiteralPath (Join-Path $stage ("page{0:d2}.txt" -f $i)) -Value "page $i" -Encoding ASCII
        }
        $target = Join-Path $Directory $FileName
        & $script:SevenZip a -tzip -mx=0 -bso0 -bsp0 $target (Join-Path $stage '*') | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "Could not build the test archive $FileName" }
        return $target
    }
    finally {
        Remove-Item -LiteralPath $stage -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Invoke-Script {
    param([string[]] $Arguments)

    # Windows PowerShell turns a native command's stderr into ErrorRecords, which
    # $ErrorActionPreference = 'Stop' would raise as an exception — and the cases
    # below deliberately exercise the failure paths. Relaxed just for the call.
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $script:ScriptUnderTest @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previous
    }

    return [pscustomobject]@{
        ExitCode = $exitCode
        Output   = (($output | Out-String) -replace '\s+', ' ').Trim()
    }
}

function Get-TempDirCount {
    <# Temporary working directories the script leaves behind, which should always be none. #>
    return @(Get-ChildItem -LiteralPath ([System.IO.Path]::GetTempPath()) -Directory -Filter 'cbr2cbz_*' -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -notlike 'cbr2cbz_test_*' -and $_.Name -notlike 'cbr2cbz_stage_*' }).Count
}

function Test-Case {
    param([string] $Name, [scriptblock] $Body)

    $sandbox = New-Sandbox
    try {
        & $Body $sandbox
        Write-Host "  PASS  $Name" -ForegroundColor Green
        $script:Passed++
    }
    catch {
        Write-Host "  FAIL  $Name" -ForegroundColor Red
        Write-Host "        $($_.Exception.Message)" -ForegroundColor Red
        $script:Failed++
    }
    finally {
        Remove-Item -LiteralPath $sandbox -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Assert-True {
    param([bool] $Condition, [string] $Message)
    if (-not $Condition) { throw $Message }
}

# --- setup --------------------------------------------------------------

$script:SevenZip = Get-SevenZip
if (-not $script:SevenZip) {
    Write-Host '7-Zip is required to run these tests. Install it from https://www.7-zip.org/.' -ForegroundColor Yellow
    exit 2
}

Write-Host "Testing $script:ScriptUnderTest"
Write-Host "Using 7-Zip: $script:SevenZip"
Write-Host ''

$leakedBefore = Get-TempDirCount

# --- cases --------------------------------------------------------------

Test-Case 'converts one archive and leaves the original in place' {
    param($sandbox)

    $source = New-FakeComic -Directory $sandbox -FileName 'Simple Comic 001.cbr'
    $result = Invoke-Script @('-Path', $sandbox)

    Assert-True ($result.ExitCode -eq 0) "Expected exit code 0, got $($result.ExitCode). $($result.Output)"
    $destination = Join-Path $sandbox 'Simple Comic 001.cbz'
    Assert-True (Test-Path -LiteralPath $destination) 'The CBZ was not created.'
    Assert-True (Test-Path -LiteralPath $source) 'The original CBR was removed.'
    Assert-True ($result.Output -match 'Converted: 1') 'The summary did not report one conversion.'
}

Test-Case 'produces a genuine ZIP rather than a renamed archive' {
    param($sandbox)

    New-FakeComic -Directory $sandbox -FileName 'Zip Check 001.cbr' -PageCount 3 | Out-Null
    Invoke-Script @('-Path', $sandbox) | Out-Null

    $destination = Join-Path $sandbox 'Zip Check 001.cbz'
    $header = [System.IO.File]::ReadAllBytes($destination)[0..1]
    Assert-True ($header[0] -eq 0x50 -and $header[1] -eq 0x4B) 'The CBZ does not start with the ZIP signature PK.'

    $listing = & $script:SevenZip l $destination | Out-String
    foreach ($page in @('page01.txt', 'page02.txt', 'page03.txt')) {
        Assert-True ($listing -match [regex]::Escape($page)) "The CBZ is missing $page."
    }
}

Test-Case 'handles spaces, parentheses and apostrophes in filenames' {
    param($sandbox)

    New-FakeComic -Directory $sandbox -FileName "The Hero's Return (2011) Vol 1 & 2.cbr" | Out-Null
    $result = Invoke-Script @('-Path', $sandbox)

    Assert-True ($result.ExitCode -eq 0) "Expected exit code 0, got $($result.ExitCode). $($result.Output)"
    Assert-True (Test-Path -LiteralPath (Join-Path $sandbox "The Hero's Return (2011) Vol 1 & 2.cbz")) 'The CBZ was not created.'
}

Test-Case 'ignores existing CBZ files and unrelated files' {
    param($sandbox)

    New-FakeComic -Directory $sandbox -FileName 'Already A Cbz.cbz' | Out-Null
    Set-Content -LiteralPath (Join-Path $sandbox 'notes.txt') -Value 'leave me alone'
    Set-Content -LiteralPath (Join-Path $sandbox 'cover.jpg') -Value 'not a comic'

    $result = Invoke-Script @('-Path', $sandbox)

    Assert-True ($result.ExitCode -eq 0) "Expected exit code 0, got $($result.ExitCode)."
    Assert-True ($result.Output -match 'No CBR files found') 'The script did not report an empty folder.'
    Assert-True ((Get-ChildItem -LiteralPath $sandbox -File).Count -eq 3) 'The folder contents changed.'
    Assert-True ((Get-Content -LiteralPath (Join-Path $sandbox 'notes.txt') -Raw).Trim() -eq 'leave me alone') 'An unrelated file was modified.'
}

Test-Case 'skips an archive whose CBZ already exists, without overwriting it' {
    param($sandbox)

    New-FakeComic -Directory $sandbox -FileName 'Existing 001.cbr' | Out-Null
    $destination = Join-Path $sandbox 'Existing 001.cbz'
    Set-Content -LiteralPath $destination -Value 'do not touch' -Encoding ASCII

    $result = Invoke-Script @('-Path', $sandbox)

    Assert-True ($result.ExitCode -eq 0) "Expected exit code 0, got $($result.ExitCode)."
    Assert-True ($result.Output -match 'Skipped: 1') 'The summary did not report one skip.'
    Assert-True ((Get-Content -LiteralPath $destination -Raw).Trim() -eq 'do not touch') 'The existing CBZ was overwritten.'
}

Test-Case 'replaces an existing CBZ when -Overwrite is given' {
    param($sandbox)

    New-FakeComic -Directory $sandbox -FileName 'Existing 001.cbr' | Out-Null
    $destination = Join-Path $sandbox 'Existing 001.cbz'
    Set-Content -LiteralPath $destination -Value 'stale' -Encoding ASCII

    $result = Invoke-Script @('-Path', $sandbox, '-Overwrite')

    Assert-True ($result.ExitCode -eq 0) "Expected exit code 0, got $($result.ExitCode). $($result.Output)"
    Assert-True ($result.Output -match 'Converted: 1') 'The summary did not report one conversion.'
    Assert-True ((Get-Content -LiteralPath $destination -Raw).Trim() -ne 'stale') 'The existing CBZ was not replaced.'
}

Test-Case 'reports a damaged archive as failed and keeps the source' {
    param($sandbox)

    $source = Join-Path $sandbox 'Damaged 001.cbr'
    Set-Content -LiteralPath $source -Value 'this is not an archive at all' -Encoding ASCII

    $result = Invoke-Script @('-Path', $sandbox)

    Assert-True ($result.ExitCode -eq 1) "Expected exit code 1 for a failed conversion, got $($result.ExitCode)."
    Assert-True ($result.Output -match 'Failed: 1') 'The summary did not report one failure.'
    Assert-True (Test-Path -LiteralPath $source) 'The damaged source archive was removed.'
    Assert-True (-not (Test-Path -LiteralPath (Join-Path $sandbox 'Damaged 001.cbz'))) 'A CBZ was left behind for a failed conversion.'
}

Test-Case 'counts converted, skipped and failed across several files' {
    param($sandbox)

    New-FakeComic -Directory $sandbox -FileName 'Good 001.cbr' | Out-Null
    New-FakeComic -Directory $sandbox -FileName 'Good 002.cbr' | Out-Null
    New-FakeComic -Directory $sandbox -FileName 'Skipped 001.cbr' | Out-Null
    Set-Content -LiteralPath (Join-Path $sandbox 'Skipped 001.cbz') -Value 'already here' -Encoding ASCII
    Set-Content -LiteralPath (Join-Path $sandbox 'Broken 001.cbr') -Value 'garbage' -Encoding ASCII

    $result = Invoke-Script @('-Path', $sandbox)

    Assert-True ($result.Output -match 'Converted: 2\s+Skipped: 1\s+Failed: 1') "Unexpected summary. $($result.Output)"
    Assert-True ($result.ExitCode -eq 1) 'A run containing a failure should exit non-zero.'
}

Test-Case 'explains how to install 7-Zip when it cannot be found' {
    param($sandbox)

    New-FakeComic -Directory $sandbox -FileName 'Any 001.cbr' | Out-Null
    $missing = Join-Path $sandbox 'no-such-7z.exe'

    $result = Invoke-Script @('-Path', $sandbox, '-SevenZipPath', $missing)

    Assert-True ($result.ExitCode -eq 1) "Expected exit code 1, got $($result.ExitCode)."
    Assert-True ($result.Output -match '7-Zip was not found') 'The error did not mention 7-Zip.'
    Assert-True (-not (Test-Path -LiteralPath (Join-Path $sandbox 'Any 001.cbz'))) 'A CBZ was produced without 7-Zip.'
}

Test-Case 'reports a missing folder instead of converting the wrong one' {
    param($sandbox)

    $result = Invoke-Script @('-Path', (Join-Path $sandbox 'does-not-exist'))

    Assert-True ($result.ExitCode -eq 1) "Expected exit code 1, got $($result.ExitCode)."
    Assert-True ($result.Output -match 'Folder not found') 'The error did not mention the missing folder.'
}

# --- results ------------------------------------------------------------

$leakedAfter = Get-TempDirCount
if ($leakedAfter -gt $leakedBefore) {
    Write-Host "  FAIL  no temporary directories are left behind" -ForegroundColor Red
    Write-Host "        $($leakedAfter - $leakedBefore) cbr2cbz_* directories remain in TEMP" -ForegroundColor Red
    $script:Failed++
}
else {
    Write-Host "  PASS  no temporary directories are left behind" -ForegroundColor Green
    $script:Passed++
}

Write-Host ''
Write-Host "Passed: $script:Passed   Failed: $script:Failed"
if ($script:Failed -gt 0) { exit 1 }
exit 0
