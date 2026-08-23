<#
    release.ps1 — build and publish a new release of the AQM Contact Form.

    Put this file in the same folder as aqmcontactform.php, then run:

        powershell -ExecutionPolicy Bypass -File release.ps1

    It reads the version out of the plugin header, checks that the
    AQM_CF_VERSION constant agrees with it, builds a correctly-structured
    ZIP, and publishes a GitHub release with that ZIP attached.

    Requires the GitHub CLI:  winget install --id GitHub.cli
    Sign in once with:        gh auth login
#>

param(
    [string]$Notes = ""
)

$ErrorActionPreference = 'Stop'

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
Write-Host "  AQM Contact Form — release builder" -ForegroundColor Cyan
Write-Host "  ----------------------------------"

# --- Checks -----------------------------------------------------------------

if (-not (Test-Path $pluginFile)) {
    Fail "aqmcontactform.php was not found. Run this from the folder that contains it."
}

if (-not (Get-Command gh -ErrorAction SilentlyContinue)) {
    Fail "The GitHub CLI is not installed. Run:  winget install --id GitHub.cli"
}

gh auth status *> $null
if ($LASTEXITCODE -ne 0) {
    Fail "You are not signed in to GitHub. Run:  gh auth login"
}

# --- Work out the version ---------------------------------------------------

$headerMatch = Select-String -Path $pluginFile -Pattern '^\s*\*\s*Version:\s*(\S+)' | Select-Object -First 1
if (-not $headerMatch) {
    Fail "Could not find a 'Version:' line in the plugin header."
}
$version = $headerMatch.Matches[0].Groups[1].Value.Trim()

$constMatch = Select-String -Path $pluginFile -Pattern "AQM_CF_VERSION',\s*'([^']+)'" | Select-Object -First 1
if (-not $constMatch) {
    Fail "Could not find the AQM_CF_VERSION constant."
}
$constVersion = $constMatch.Matches[0].Groups[1].Value.Trim()

# This is the mistake that silently breaks updates, so refuse to build.
if ($version -ne $constVersion) {
    Fail "Version mismatch: the header says $version but AQM_CF_VERSION says $constVersion. Make them match, then run this again."
}

$tag = "v$version"
Write-Host "  Version:  $version"
Write-Host "  Tag:      $tag"

$existing = gh release view $tag *>&1
if ($LASTEXITCODE -eq 0) {
    Fail "A release tagged $tag already exists. Bump the version in the plugin file first."
}

# --- Build ------------------------------------------------------------------

if (Test-Path $buildDir) { Remove-Item $buildDir -Recurse -Force }
if (Test-Path $zipPath)  { Remove-Item $zipPath -Force }

$target = Join-Path $buildDir $slug
New-Item -ItemType Directory -Path $target -Force | Out-Null
Copy-Item $pluginFile -Destination $target

# Pointing at the folder (not its contents) keeps the aqm-contact-form/
# directory inside the ZIP, which is what WordPress needs to update in place.
Compress-Archive -Path $target -DestinationPath $zipPath -Force
Remove-Item $buildDir -Recurse -Force

$sizeKb = [math]::Round((Get-Item $zipPath).Length / 1KB, 1)
Write-Host "  Built:    $slug.zip ($sizeKb KB)" -ForegroundColor Green

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
Write-Host "  Sites will see it within 12 hours, or immediately via"
Write-Host "  'Check for updates' on the Plugins screen."
Write-Host ""
