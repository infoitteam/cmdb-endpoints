# bin\pack-plugin.ps1
# Build ZIP: bin\release\cmdb-endpoints-<version>.zip
# Internal top-level folder inside the ZIP is always: cmdb-endpoints/

param(
  [string]$VersionOverride = ""
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# --- Config ---
$PluginSlug = 'cmdb-endpoints'       # internal folder name in ZIP
$MainFile   = 'cmdb-endpoints.php'   # file with "Version:" header

# --- Paths (absolute, repo root is parent of /bin) ---
$ScriptRoot  = Split-Path -Parent $PSCommandPath
$ProjectRoot = Split-Path -Parent $ScriptRoot   # repo root
$OutDir      = Join-Path $ProjectRoot 'bin\release'
$TempRoot    = Join-Path ([IO.Path]::GetTempPath()) ("$PluginSlug-build-" + (Get-Date -Format 'yyyyMMddHHmmss'))
$StageDir    = Join-Path $TempRoot $PluginSlug    # stage into ...\temp\cmdb-endpoints\

# --- Ensure output dir ---
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

# --- Read version from plugin header (or override) ---
$MainPath = Join-Path $ProjectRoot $MainFile
if (!(Test-Path $MainPath)) { throw "Cannot find $MainFile in $ProjectRoot" }
$verMatch = (Select-String -Path $MainPath -Pattern '^\s*\*\s*Version:\s*([0-9][0-9\.]*)').Matches
if ($verMatch.Count -lt 1 -and -not $VersionOverride) { throw "Couldn't read Version from $MainFile and no -VersionOverride provided." }
$Version = if ($VersionOverride) { $VersionOverride } else { $verMatch[0].Groups[1].Value }

# --- Stage files (exclude dev/build stuff) ---
if (Test-Path $TempRoot) { Remove-Item $TempRoot -Recurse -Force }
New-Item -ItemType Directory -Force -Path $StageDir | Out-Null

$excludeDirs = @(
  '.git','.github','.vscode','.idea',
  'bin','release','node_modules','vendor','tests','test','.gitlab'
)
$excludeFiles = @(
  '.gitignore','.gitattributes','composer.lock','package-lock.json',
  'pack-plugin.ps1','build.ps1'
)

# Build robocopy args with proper quoting
$xdArgs = @()
foreach ($d in $excludeDirs) { $xdArgs += '/XD'; $xdArgs += "`"$([IO.Path]::Combine($ProjectRoot,$d))`"" }
$xfArgs = @()
foreach ($f in $excludeFiles) { $xfArgs += '/XF'; $xfArgs += "`"$([IO.Path]::Combine($ProjectRoot,$f))`"" }

$robocopyArgs = @(
  "`"$ProjectRoot`"","`"$StageDir`"",
  '/MIR','/NFL','/NDL','/NJH','/NJS','/NP','/R:1','/W:1'
) + $xdArgs + $xfArgs

& robocopy @robocopyArgs | Out-Null
$rc = $LASTEXITCODE
if ($rc -gt 7) { throw "Robocopy failed with exit code $rc" }

# --- Create ZIP (manual ZipArchive with forward slashes) ---
Add-Type -AssemblyName System.IO.Compression.FileSystem

$ZipPath = Join-Path $OutDir ("$PluginSlug-$Version.zip")
if (Test-Path $ZipPath) { Remove-Item $ZipPath -Force }

# Write zip with normalized entry names
$zipStream = [System.IO.File]::Open($ZipPath, [System.IO.FileMode]::CreateNew)
$zip = New-Object System.IO.Compression.ZipArchive($zipStream, [System.IO.Compression.ZipArchiveMode]::Create)

# Add files
$baseLen = $StageDir.Length + 1
Get-ChildItem -Path $StageDir -Recurse -File | ForEach-Object {
  $rel = $_.FullName.Substring($baseLen) -replace '\\','/'             # force forward slashes
  $entryPath = "$PluginSlug/$rel"
  $null = $zip.CreateEntryFromFile($_.FullName, $entryPath, [System.IO.Compression.CompressionLevel]::Optimal)
}

$zip.Dispose()
$zipStream.Dispose()

# --- Sanity check ---
$ok = $true
$zip = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
foreach ($e in $zip.Entries) {
  if ($e.FullName -match '\\') { $ok = $false; Write-Warning ("Entry has backslash: " + $e.FullName) }
  if ($e.FullName -notmatch "^$PluginSlug/") { $ok = $false; Write-Warning ("Entry not rooted in " + $PluginSlug + "/: " + $e.FullName) }
}
$zip.Dispose()

# --- Hashes (saved alongside the zip, NOT included inside it) ---
$sha256 = (Get-FileHash -Algorithm SHA256 -Path $ZipPath).Hash
$sha1   = (Get-FileHash -Algorithm SHA1   -Path $ZipPath).Hash
$sha256Path = "$ZipPath.sha256"
$sha1Path   = "$ZipPath.sha1"
$sha256 | Out-File -Encoding ASCII -FilePath $sha256Path
$sha1   | Out-File -Encoding ASCII -FilePath $sha1Path

Write-Host ""
Write-Host ("Built: " + $ZipPath)
Write-Host ("SHA256: " + $sha256)
Write-Host ("SHA1  : " + $sha1)
if ($ok) {
  Write-Host "ZIP structure looks good"
} else {
  Write-Warning "ZIP structure had anomalies (see warnings above)"
}

# --- Cleanup ---
Remove-Item $TempRoot -Recurse -Force
