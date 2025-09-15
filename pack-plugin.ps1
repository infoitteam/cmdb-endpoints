# pack-plugin.ps1
# Zips the plugin into release\<slug>-<version>.zip using absolute paths.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# --- Config ---
$PluginSlug = 'cmdb-endpoints'  # folder name for the plugin inside the zip
$MainFile   = 'cmdb-endpoints.php'  # plugin main file where Version header lives

# --- Paths (ABSOLUTE, based on script location) ---
$ScriptRoot = Split-Path -Parent $PSCommandPath   # same as $PSScriptRoot but works in older PS
$ProjectRoot = $ScriptRoot
$ReleaseDir  = Join-Path $ProjectRoot 'release'
$StageRoot   = Join-Path ([IO.Path]::GetTempPath()) ("$PluginSlug-pack-" + (Get-Date -Format 'yyyyMMddHHmmss'))
$StageDir    = Join-Path $StageRoot $PluginSlug

# --- Ensure release dir ---
if (-not (Test-Path $ReleaseDir)) {
    New-Item -Path $ReleaseDir -ItemType Directory | Out-Null
}

# --- Derive version from main plugin file header ---
$Version = '0.0.0'
$MainPath = Join-Path $ProjectRoot $MainFile
if (Test-Path $MainPath) {
    $verLine = Select-String -Path $MainPath -Pattern '^\s*\*\s*Version:\s*(.+)$' -SimpleMatch:$false | Select-Object -First 1
    if ($verLine) {
        $m = [regex]::Match($verLine.Line, 'Version:\s*([0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9\.-]+)?)')
        if ($m.Success) { $Version = $m.Groups[1].Value }
    }
}

# --- Stage files (exclude dev/build stuff) ---
New-Item -Path $StageDir -ItemType Directory -Force | Out-Null

# Use robocopy for robust copying and exclusions (returns nonstandard exit codes; we'll tolerate 0-7)
$excludeDirs = @('.git', '.github', '.vscode', 'release', 'node_modules', 'vendor/bin', '.idea', 'tests', 'test', '.gitlab')
$excludeFiles = @('pack-plugin.ps1', '.gitignore', '.gitattributes', 'composer.lock', 'package-lock.json')

$robolog = Join-Path $StageRoot 'robocopy.log'
$xd = $excludeDirs | ForEach-Object { @('/XD', (Join-Path $ProjectRoot $_)) } | ForEach-Object { $_ }
$xf = $excludeFiles | ForEach-Object { @('/XF', (Join-Path $ProjectRoot $_)) } | ForEach-Object { $_ }

# Mirror only the project root into staging subfolder
$cmd = @(
  'robocopy',
  $ProjectRoot,
  $StageDir,
  '/MIR', '/NFL', '/NDL', '/NJH', '/NJS', '/NP', '/R:1', '/W:1'
) + $xd + $xf

# --- Start robocopy (quoted args; handles spaces and hyphens in paths) ---
$xdArgs = @()
foreach ($d in $excludeDirs) {
    $xdArgs += '/XD'
    $xdArgs += "`"$([IO.Path]::Combine($ProjectRoot, $d))`""
}
$xfArgs = @()
foreach ($f in $excludeFiles) {
    $xfArgs += '/XF'
    $xfArgs += "`"$([IO.Path]::Combine($ProjectRoot, $f))`""
}

$robocopyArgs = @(
    "`"$ProjectRoot`"",
    "`"$StageDir`"",
    '/MIR','/NFL','/NDL','/NJH','/NJS','/NP','/R:1','/W:1'
) + $xdArgs + $xfArgs

# Run robocopy and capture its exit code
& robocopy @robocopyArgs | Out-Null
$rc = $LASTEXITCODE
# Accept 0–7 per robocopy semantics
if ($rc -gt 7) {
    Write-Host "Robocopy failed with exit code $rc"
    Write-Host "Args:`n$($robocopyArgs -join ' ' )"
    throw "Robocopy failed"
}


# --- Build zip path (ABSOLUTE) ---
$ZipPath = Join-Path $ReleaseDir ("$PluginSlug-$Version.zip")

# If zip exists, remove so CreateFromDirectory doesn't fail
if (Test-Path $ZipPath) { Remove-Item $ZipPath -Force }

# --- Create zip ---
Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory($StageDir, $ZipPath)

# --- Hashes & output ---
$sha256 = (Get-FileHash -Algorithm SHA256 -Path $ZipPath).Hash
$sha1   = (Get-FileHash -Algorithm SHA1   -Path $ZipPath).Hash

Write-Host ""
Write-Host "Built: $ZipPath"
Write-Host "SHA256: $sha256"
Write-Host "SHA1  : $sha1"

# --- Cleanup staging ---
Remove-Item $StageRoot -Recurse -Force
