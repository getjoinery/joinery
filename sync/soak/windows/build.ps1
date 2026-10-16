# Build the Windows soak line's client and orchestrator, test the
# orchestrator, and stage both for the next run.
#
# The source arrives as C:\soak-win\sync-src.tgz (push.sh puts it there) and
# replaces the crates in C:\src\sync, whose target\ is kept so only what
# changed is rebuilt: the VM's disk is a file on the Mac mini's, which has
# little room to spare.
#
# Runs as the scheduled task SoakWin-Build (Windows OpenSSH ends every process
# a session started when the session closes, and a build outlives a command).
# C:\src\sync is the VM's one build tree, shared by everyone who builds here,
# so the build holds C:\sr-lock-<who>.txt while it runs and waits for anyone
# else's to go first.
#
# The binaries land in bin\ as joinery-drive.exe.new and jd-soak.exe.new and
# are moved into place by reset.ps1 at the start of the next run, never under
# a run in flight: the devices run their own copies (bin\device-a\...), made by
# reset.ps1 from bin\joinery-drive.exe.
#
# Start: Start-ScheduledTask SoakWin-Build
# Read:  Get-Content C:\soak-win\build.log -Tail 30   (ends with "=== build exit N")
param([string]$Who = 'soak-win')
$ErrorActionPreference = 'Continue'
$S = 'C:\soak-win'
$Log = "$S\build.log"
$Tree = 'C:\src\sync'
$Lock = "C:\sr-lock-$Who.txt"

function Note($m) {
    $line = "[$((Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ'))] $m"
    [IO.File]::AppendAllText($Log, "$line`n")
}

[IO.File]::WriteAllText($Log, '')
# Asked every few seconds, so a build that has waited is not overtaken by
# whoever happens to look in the moment a lock comes off.
$said = ''
while ($true) {
    $others = Get-ChildItem C:\sr-lock-*.txt -ErrorAction SilentlyContinue | Where-Object { $_.FullName -ne $Lock }
    if (-not $others) { break }
    $now = $others.Name -join ', '
    if ($now -ne $said) { Note "waiting for $now"; $said = $now }
    Start-Sleep 5
}
[IO.File]::WriteAllText($Lock, "$Who building the Windows soak line in $Tree since $((Get-Date).ToUniversalTime().ToString('s'))Z`n")

try {
    $env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')
    # ring assembles its ARM64 code with clang, and Visual Studio's LLVM
    # component is not on PATH by default.
    $llvm = 'C:\Program Files (x86)\Microsoft Visual Studio\2022\BuildTools\VC\Tools\Llvm'
    foreach ($d in @("$llvm\ARM64\bin", "$llvm\x64\bin", "$llvm\bin")) {
        if (Test-Path "$d\clang.exe") { $env:Path = "$d;" + $env:Path; break }
    }
    $env:CARGO_TERM_COLOR = 'never'
    # Two jobs: the VM has 4 GB, and four rustc processes linking with LTO
    # at once page the guest, whose page file is also on the mini's disk.
    $env:CARGO_BUILD_JOBS = '2'
    $tarball = "$S\sync-src.tgz"
    if (Test-Path $tarball) {
        Get-ChildItem $Tree -Force | Where-Object { $_.Name -ne 'target' } | Remove-Item -Recurse -Force
        # Unpacked with the time of unpacking (-m), not the times in the
        # archive: cargo calls a crate fresh when its sources are older than
        # its last build, and a commit's files carry the commit's time, which
        # is older than the build of the tree before it. A new release's engine
        # was otherwise left unbuilt and the old client staged under its name.
        tar -xmzf $tarball -C (Split-Path $Tree)
        Remove-Item $tarball -Force
        Note "source replaced from $tarball"
    }
    Set-Location $Tree
    Note "=== build start in $Tree ($(Get-Content "$Tree\SOURCE" -ErrorAction SilentlyContinue))"

    # cmd does the redirection: PowerShell 5.1 would wrap every line cargo
    # writes to stderr in an error record, and write the log as UTF-16.
    # The client on its own first: cargo turns on, in a shared dependency,
    # every feature any crate in one invocation asks for, and the client the
    # line soaks is the one that ships, without the rig's (jd-soak asks
    # windows-sys for process and handle calls the daemon never makes).
    cmd /d /c "cargo build --release -p jd-daemon >> `"$Log`" 2>&1"
    $built = $LASTEXITCODE
    if ($built -eq 0) {
        cmd /d /c "cargo build --release -p jd-soak >> `"$Log`" 2>&1"
        $built = $LASTEXITCODE
    }
    Note "release build exit $built"
    if ($built -eq 0) {
        cmd /d /c "cargo test -p jd-soak >> `"$Log`" 2>&1"
        Note "jd-soak tests exit $LASTEXITCODE"
        New-Item -ItemType Directory -Force "$S\bin" | Out-Null
        foreach ($exe in 'joinery-drive.exe', 'jd-soak.exe') {
            Copy-Item "$Tree\target\release\$exe" "$S\bin\$exe.new" -Force
            $md5 = (Get-FileHash -Algorithm MD5 "$S\bin\$exe.new").Hash.Substring(0, 8).ToLower()
            Note "staged $exe.new ($md5)"
        }
        Copy-Item "$Tree\SOURCE" "$S\bin\SOURCE.new" -Force -ErrorAction SilentlyContinue
    }
    Note "=== build exit $built"
} finally {
    Remove-Item $Lock -Force -ErrorAction SilentlyContinue
}
