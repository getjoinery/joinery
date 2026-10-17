<#
Build the Windows installers, one per architecture. Runs on Windows.

  powershell -ExecutionPolicy Bypass -File sync\packaging\windows\build.ps1 [-Arch x64,arm64]
      -> sync\target\packaging\windows\JoineryDrive-<version>-windows-<arch>-setup.exe

The binaries are built with the MSVC toolchain for both x64 and ARM64, natively
on Windows: ring, the TLS library's crypto, needs a C compiler for the target,
and Visual Studio's is the one that targets both. The tray carries its icon and
version resource from jd-shell's build script; the installer takes the same
icon. The installer is NSIS, compiled with `RequestExecutionLevel user`, so it
installs and uninstalls with no administrator and no UAC prompt (see
installer.nsi).

Prerequisites: rustup with the x86_64-pc-windows-msvc and aarch64-pc-windows-msvc
targets; Visual Studio 2022 Build Tools with the MSVC tools for both
architectures and the Clang component (ring builds its ARM64 assembly with
clang); NSIS 3 (makensis on PATH, or NSIS_HOME naming its folder).

Signing runs only when a certificate is configured, and is skipped otherwise:

  WINDOWS_SIGN_CERT_SHA1      thumbprint of a code-signing certificate in the
                              current user's certificate store
  WINDOWS_SIGN_TIMESTAMP_URL  RFC 3161 timestamp server
                              (default http://timestamp.digicert.com)
  SIGNTOOL                    signtool.exe, if not the newest Windows SDK one

With it, the two programs are signed before they are packed, the uninstaller
while makensis writes it, and the installer last.

Beside each installer goes the update payload the installed client's updater
applies (`joinery-drive-release pack` of the two programs, signed when signing
is on). The feed manifest that lists them and its signature are the
publisher's step (docs/drive_sync.md § Updates).
#>
param(
    [string[]]$Arch = @('x64', 'arm64'),
    [string]$OutDir,
    [string]$TargetDir
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$Sync = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
if (-not $TargetDir) { $TargetDir = if ($env:CARGO_TARGET_DIR) { $env:CARGO_TARGET_DIR } else { Join-Path $Sync 'target' } }
if (-not $OutDir) { $OutDir = Join-Path $TargetDir 'packaging\windows' }
$Triples = @{ 'x64' = 'x86_64-pc-windows-msvc'; 'arm64' = 'aarch64-pc-windows-msvc' }

function Invoke-Native {
    param([string]$Exe, [string[]]$Arguments)
    & $Exe @Arguments
    if ($LASTEXITCODE -ne 0) { throw "$Exe $($Arguments -join ' ') exited with $LASTEXITCODE" }
}

# The workspace version, as cargo reads it (sync/packaging/version.sh does the
# same for macOS and Linux): the one place a version is written.
$meta = cargo metadata --no-deps --format-version 1 --manifest-path (Join-Path $Sync 'Cargo.toml') | ConvertFrom-Json
if ($LASTEXITCODE -ne 0) { throw 'cargo metadata failed' }
$Version = ($meta.packages | Where-Object { $_.name -eq 'jd-daemon' }).version
if ($Version -notmatch '^\d+\.\d+\.\d+(-(alpha|beta|rc)(\.\d+)?)?$') {
    throw "workspace version '$Version' is not MAJOR.MINOR.PATCH[-alpha|beta|rc.N]"
}
$VersionNumeric = $Version -replace '-.*$', ''
Write-Host "=== Joinery Drive $Version for Windows ($($Arch -join ', ')) ==="

# ring assembles its ARM64 code with clang. Visual Studio installs one with its
# Clang component but does not put it on PATH.
if (-not (Get-Command clang.exe -ErrorAction SilentlyContinue)) {
    $vswhere = Join-Path ${env:ProgramFiles(x86)} 'Microsoft Visual Studio\Installer\vswhere.exe'
    if (Test-Path $vswhere) {
        $vs = & $vswhere -latest -products * -property installationPath
        $llvmArch = if ($env:PROCESSOR_ARCHITECTURE -eq 'ARM64') { 'ARM64' } else { 'x64' }
        foreach ($d in "$vs\VC\Tools\Llvm\$llvmArch\bin", "$vs\VC\Tools\Llvm\x64\bin", "$vs\VC\Tools\Llvm\bin") {
            if (Test-Path (Join-Path $d 'clang.exe')) { $env:Path = "$d;$env:Path"; break }
        }
    }
}

function Find-MakeNsis {
    if ($env:NSIS_HOME) { return (Join-Path $env:NSIS_HOME 'makensis.exe') }
    $onPath = Get-Command makensis.exe -ErrorAction SilentlyContinue
    if ($onPath) { return $onPath.Source }
    $installed = Join-Path ${env:ProgramFiles(x86)} 'NSIS\makensis.exe'
    if (Test-Path $installed) { return $installed }
    throw 'makensis not found: install NSIS 3, or set NSIS_HOME'
}
$MakeNsis = Find-MakeNsis

$Signing = [bool]$env:WINDOWS_SIGN_CERT_SHA1
if ($Signing) {
    $SignTool = $env:SIGNTOOL
    if (-not $SignTool) {
        $hostArch = if ($env:PROCESSOR_ARCHITECTURE -eq 'ARM64') { 'arm64' } else { 'x64' }
        $SignTool = Get-ChildItem (Join-Path ${env:ProgramFiles(x86)} "Windows Kits\10\bin\*\$hostArch\signtool.exe") |
            Sort-Object FullName | Select-Object -Last 1 -ExpandProperty FullName
    }
    if (-not $SignTool) { throw 'signtool.exe not found: install the Windows SDK, or set SIGNTOOL' }
    $TimestampUrl = if ($env:WINDOWS_SIGN_TIMESTAMP_URL) { $env:WINDOWS_SIGN_TIMESTAMP_URL } else { 'http://timestamp.digicert.com' }
}
function Invoke-Sign([string]$Path) {
    Invoke-Native $SignTool @('sign', '/sha1', $env:WINDOWS_SIGN_CERT_SHA1, '/fd', 'sha256', '/tr', $TimestampUrl, '/td', 'sha256', $Path)
}

New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
$built = @()
foreach ($a in $Arch) {
    $triple = $Triples[$a]
    if (-not $triple) { throw "unknown architecture '$a' (x64 or arm64)" }
    if (-not ((rustup target list --installed) -contains $triple)) {
        throw "the $triple standard library is not installed: rustup target add $triple"
    }

    Write-Host "--- cargo build $triple ---"
    # JSON on stdout, so the build script's output folder can be read back:
    # jd-shell's build.rs draws the app icon there, and the installer uses it.
    $messages = & cargo build --release --locked --message-format=json-render-diagnostics `
        --manifest-path (Join-Path $Sync 'Cargo.toml') --target-dir $TargetDir --target $triple `
        -p jd-daemon -p jd-shell
    if ($LASTEXITCODE -ne 0) { throw "cargo build for $triple exited with $LASTEXITCODE" }
    $shellOut = $messages | ForEach-Object { $_ | ConvertFrom-Json } |
        Where-Object { $_.reason -eq 'build-script-executed' -and $_.package_id -match 'jd-shell' } |
        Select-Object -Last 1 -ExpandProperty out_dir
    $icon = Join-Path $shellOut 'joinery-drive.ico'
    if (-not (Test-Path $icon)) { throw "jd-shell's build script left no icon at $icon" }

    $bin = Join-Path $TargetDir "$triple\release"
    $stage = Join-Path $OutDir "stage-$a"
    Remove-Item -Recurse -Force $stage -ErrorAction SilentlyContinue
    New-Item -ItemType Directory -Force -Path $stage | Out-Null
    foreach ($exe in 'joinery-drive.exe', 'joinery-drive-tray.exe') {
        Copy-Item (Join-Path $bin $exe) $stage
    }

    # A built binary must report the version being stamped on its installer.
    # The tray is a windowed program, so its answer is read through a pipe.
    $daemonSaid = (& (Join-Path $stage 'joinery-drive.exe') --version | Out-String).Trim()
    $traySaid = (& (Join-Path $stage 'joinery-drive-tray.exe') --version | Out-String).Trim()
    if ($daemonSaid -ne "joinery-drive $Version" -or $traySaid -ne "joinery-drive-tray $Version") {
        throw "binaries report '$daemonSaid' / '$traySaid', expected version $Version"
    }

    $nsisArgs = @("/DVERSION=$Version", "/DVERSION_NUMERIC=$VersionNumeric", "/DARCH=$a", "/DBINDIR=$stage", "/DICON=$icon")
    if ($Signing) {
        Write-Host "--- sign the programs ($a) ---"
        foreach ($exe in 'joinery-drive.exe', 'joinery-drive-tray.exe') { Invoke-Sign (Join-Path $stage $exe) }
        # makensis runs this for the uninstaller it writes; one argument, the file.
        $signCmd = Join-Path $stage 'sign-uninstaller.cmd'
        "@`"$SignTool`" sign /sha1 $env:WINDOWS_SIGN_CERT_SHA1 /fd sha256 /tr $TimestampUrl /td sha256 %1" |
            Set-Content -Encoding ASCII $signCmd
        $nsisArgs += "/DSIGN_COMMAND=$signCmd"
    }

    $setup = Join-Path $OutDir "JoineryDrive-$Version-windows-$a-setup.exe"
    Write-Host "--- makensis ($a) ---"
    Invoke-Native $MakeNsis (@('/V2') + $nsisArgs + @("/DOUTFILE=$setup", (Join-Path $PSScriptRoot 'installer.nsi')))
    if ($Signing) { Invoke-Sign $setup }
    $built += $setup

    Write-Host "--- update payload ($a) ---"
    $payload = Join-Path $OutDir "JoineryDrive-$Version-windows-$a.update.tar.gz"
    Invoke-Native (Join-Path $bin 'joinery-drive-release.exe') @('pack', '--out', $payload,
        (Join-Path $stage 'joinery-drive.exe'), (Join-Path $stage 'joinery-drive-tray.exe'))
    $built += $payload
}

$sums = foreach ($f in $built) {
    "$((Get-FileHash -Algorithm SHA256 $f).Hash.ToLower())  $(Split-Path -Leaf $f)"
}
$sums | Set-Content -Encoding ASCII (Join-Path $OutDir 'SHA256SUMS')
Write-Host 'built:'
$sums | ForEach-Object { Write-Host "  $_" }
