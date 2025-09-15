# bin\pack-plugin.ps1
# Build ZIP: bin\release\cmdb-endpoints-<version>.zip
# Internal top-level folder inside the ZIP is always: cmdb-endpoints/

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# --- Config ---
$PluginSlug = 'cmdb-endpoints'      # internal folder name in ZIP
$MainFile   = 'cmdb-endpoints.php'  # file with "Version:" header

# --- Paths (absolute, relative to script) ---
$ScriptRoot  = Split-Path -Parent $PSCommandPath
$ProjectRoot = $ScriptRoot         # adjust if your script lives elsewhere
$OutDir      = Join-Path $ProjectRoot 'bin\release'
$TempRoot    = Join-Path ([IO.Path]::GetTempPath()) ("$PluginSlug-build-" + (Get-Date -Format 'yyyyMMddHHmmss'))
$StageDir    = Join-Path $TempRoot $PluginSlug      # <- NOTE: we stage into ...\<temp>\cmdb-endpoints\

# --- Ensure output dir ---
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

# --- Read version from plugin header ---
$MainPath = Join-Path $ProjectRoot $MainFile
if (!(Test-Path $MainPath)) { throw "Cannot find $MainFile in $ProjectRoot" }
$verMatch = (Select-String -Path $MainPath -Pattern '^\s*\*\s*Version:\s*([0-9][0-9\.]*)').Matches
if ($verMatch.Count -lt 1) { throw "Couldn't read Version from $MainFile" }
$Version = $verMatch[0].Groups[1].Value

# --- Stage files (exclude dev/build stuff) ---
New-Item -ItemType Directory -Force -Path $StageDir | Out-Null

$excludeDirs  = @('.git', '.github', '.vscode', 'bin', 'vendor', 'node_modules', '.idea', 'tests', 'test', '.gitlab')
$excludeFiles = @('.gitignore', '.gitattributes', 'composer.lock', 'package-lock.json', 'pack-plugin.ps1', 'build.ps1')

# Build robocopy args with proper quoting
$xdArgs = @()
foreach ($d in $excludeDirs) { $xdArgs += '/XD'; $xdArgs += "`"$([IO.Path]::Combine($ProjectRoot, $d))`"" }
$xfArgs = @()
foreach ($f in $excludeFiles) { $xfArgs += '/XF'; $xfArgs += "`"$([IO.Path]::Combine($ProjectRoot, $f))`"" }

$robocopyArgs = @(
  "`"$ProjectRoot`"",
  "`"$StageDir`"",
  '/MIR','/NFL','/NDL','/NJH','/NJS','/NP','/R:1','/W:1'
) + $xdArgs + $xfArgs

& robocopy @robocopyArgs | Out-Null
$rc = $LASTEXITCODE
if ($rc -gt 7) { throw "Robocopy failed with exit code $rc" }

# --- Create ZIP (zip the PARENT to embed 'cmdb-endpoints/' as the top folder) ---
Add-Type -AssemblyName System.IO.Compression.FileSystem

$ZipPath = Join-Path $OutDir ("$PluginSlug-$Version.zip")
if (Test-Path $ZipPath) { Remove-Item $ZipPath -Force }

# CRITICAL: zip $TempRoot (which contains the cmdb-endpoints folder), not $StageDir.
[System.IO.Compression.ZipFile]::CreateFromDirectory($TempRoot, $ZipPath)

# --- Optional: quick sanity check to ensure paths aren't flattened/backslashed ---
$ok = $true
$zip = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
foreach ($e in $zip.Entries) {
  if ($e.FullName -match '\\') { $ok = $false; Write-Warning "Entry has backslash: $($e.FullName)" }
  if ($e.FullName -notmatch "^$PluginSlug/") { $ok = $false; Write-Warning "Entry not rooted in $PluginSlug/: $($e.FullName)" }
}
$zip.Dispose()

Write-Host ""
Write-Host "Built: $ZipPath"
if ($ok) { Write-Host "ZIP structure looks good ✔" } else { Write-Warning "ZIP structure had anomalies (see warnings above)" }

# --- Cleanup ---
Remove-Item $TempRoot -Recurse -Force
