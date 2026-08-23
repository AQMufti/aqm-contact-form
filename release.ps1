<#
    release.ps1 - build and publish a new release of the AQM Contact Form.

    Put this file in the same folder as aqmcontactform.php, then run:

        powershell -ExecutionPolicy Bypass -File release.ps1

    It reads the version out of the plugin header, checks that the
    AQM_VERSION constant agrees with it, builds a correctly-structured
    ZIP, and publishes a GitHub release with that ZIP attached.

    Requires the GitHub CLI:  winget install --id GitHub.cli
    Sign in once with:        gh auth login

    NOTE: this file is deliberately plain ASCII. Windows PowerShell reads
    scripts as ANSI unless they carry a BOM, so a stray accented character
    or dash can corrupt a string and break parsing.
#>

param(
    [string]$Notes = ""
)

# The gh CLI writes normal status messages to the error stream, including
# the "release not found" that we deliberately go looking for. Leaving this
# at Continue and checking exit codes by hand avoids treating those as
# failures. The build section below opts into Stop where it is wanted.
$ErrorActionPreference = 'Continue'

$root       = $PSScriptRoot
$pluginFile = Join-Path $root 'aqmcontactform.php'
$slug       = 'aqm-contact-form'
$buildDir   = Join-Path $root 'build'
$zipPath    = Join-Path $root "$slug.zip"

function Fail($message) {
    Write-Host ""
    Write-Host "  $message" -ForegroundColor Red
    Write-Host ""
    exit 1
}

Write-Host ""
Write-Host "  AQM Contact Form - release builder" -ForegroundColor Cyan
Write-Host "  ----------------------------------"

# --- Checks -----------------------------------------------------------------

if (-not (Test-Path $pluginFile)) {
    Fail "aqmcontactform.php was not found. Run this from the folder that contains it."
}

if (-not (Get-Command gh -ErrorAction SilentlyContinue)) {
    Fail "The GitHub CLI is not installed. Run:  winget install --id GitHub.cli"
}

cmd /c "gh auth status >nul 2>&1"
if ($LASTEXITCODE -ne 0) {
    Fail "You are not signed in to GitHub. Run:  gh auth login"
}

# --- Work out the version ---------------------------------------------------

$headerPattern = '^\s*\*\s*Version:\s*(\S+)'
$headerMatch = Select-String -Path $pluginFile -Pattern $headerPattern | Select-Object -First 1
if (-not $headerMatch) {
    Fail "Could not find a Version line in the plugin header."
}
$version = $headerMatch.Matches[0].Groups[1].Value.Trim()

$constPattern = "AQM_VERSION',\s*'([^']+)'"
$constMatch = Select-String -Path $pluginFile -Pattern $constPattern | Select-Object -First 1
if (-not $constMatch) {
    Fail "Could not find the AQM_VERSION constant."
}
$constVersion = $constMatch.Matches[0].Groups[1].Value.Trim()

# This is the mistake that silently breaks updates, so refuse to build.
if ($version -ne $constVersion) {
    Fail "Version mismatch: the header says $version but AQM_VERSION says $constVersion. Make them match, then run this again."
}

$tag = "v$version"
Write-Host "  Version:  $version"
Write-Host "  Tag:      $tag"

# Exit code 0 here means the tag already exists, which is the problem case.
cmd /c "gh release view $tag >nul 2>&1"
if ($LASTEXITCODE -eq 0) {
    Fail "A release tagged $tag already exists. Bump the version in the plugin file first."
}

# --- Build ------------------------------------------------------------------

try {
    $ErrorActionPreference = 'Stop'

    if (Test-Path $buildDir) { Remove-Item $buildDir -Recurse -Force }
    if (Test-Path $zipPath)  { Remove-Item $zipPath -Force }

    $target = Join-Path $buildDir $slug
    New-Item -ItemType Directory -Path $target -Force | Out-Null
    Copy-Item $pluginFile -Destination $target

    # Pointing at the folder (not its contents) keeps the aqm-contact-form
    # directory inside the ZIP, which is what WordPress needs to update in place.
    Compress-Archive -Path $target -DestinationPath $zipPath -Force
    Remove-Item $buildDir -Recurse -Force
}
catch {
    $ErrorActionPreference = 'Continue'
    Fail "Could not build the ZIP: $($_.Exception.Message)"
}
$ErrorActionPreference = 'Continue'

$sizeKb = [math]::Round((Get-Item $zipPath).Length / 1KB, 1)
Write-Host "  Built:    $slug.zip, $sizeKb KB" -ForegroundColor Green

# --- Publish ----------------------------------------------------------------

if ([string]::IsNullOrWhiteSpace($Notes)) {
    $Notes = Read-Host "  Release notes (shown in the WordPress update screen)"
}
if ([string]::IsNullOrWhiteSpace($Notes)) {
    $Notes = "Release $version"
}

Write-Host "  Publishing to GitHub..."
gh release create $tag $zipPath --title $tag --notes $Notes

if ($LASTEXITCODE -ne 0) {
    Fail "The release could not be published. The ZIP is still here if you want to upload it by hand."
}

Write-Host ""
Write-Host "  Published $tag" -ForegroundColor Green
Write-Host "  Sites will see it within 12 hours, or immediately via the"
Write-Host "  Check for updates link on the Plugins screen."
Write-Host ""
