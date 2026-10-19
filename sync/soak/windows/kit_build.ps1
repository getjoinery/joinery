# Build what the personal-computer kit carries (make_pc_kit.sh): the x64
# client and orchestrator, and the whole workspace's tests, for a person's
# own Windows computer rather than this VM.
#
# The source is C:\soak-win\kit-src.tgz (make_pc_kit.sh puts it there),
# unpacked in C:\src\kit, apart from the line's own tree, and everything is
# built into C:\soak-win\tests\target: the path the kit installs the tests
# at on the other computer. Two of the tests start the client by the path
# cargo built it at (CARGO_BIN_EXE_joinery-drive), which is fixed when they
# are compiled, so they are compiled where they will run. One target folder
# for all three builds, so the dependencies they share are compiled once.
#
# The client the kit soaks is built on its own, as build.ps1 builds the
# line's: cargo turns on in a shared dependency every feature any crate in one
# invocation asks for, and the client that ships is built without the rig's.
#
# Runs as the task SoakWin-KitBuild (registered by make_pc_kit.sh), holding
# C:\sr-lock-kit.txt like every build here. Writes C:\soak-win\kit\ (the
# files the kit needs) and ends its log with "=== kit build exit N".
$ErrorActionPreference = 'Continue'
$S = 'C:\soak-win'
$Log = "$S\kit-build.log"
$Tree = 'C:\src\kit'
$Out = "$S\kit"
$Triple = 'x86_64-pc-windows-msvc'
$TestTarget = "$S\tests\target"
$Lock = 'C:\sr-lock-kit.txt'

function Note($m) {
    [IO.File]::AppendAllText($Log, "[$((Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ'))] $m`n")
}

[IO.File]::WriteAllText($Log, '')
$said = ''
while ($true) {
    $others = Get-ChildItem C:\sr-lock-*.txt -ErrorAction SilentlyContinue | Where-Object { $_.FullName -ne $Lock }
    if (-not $others) { break }
    $now = $others.Name -join ', '
    if ($now -ne $said) { Note "waiting for $now"; $said = $now }
    Start-Sleep 5
}
[IO.File]::WriteAllText($Lock, "kit build in $Tree since $((Get-Date).ToUniversalTime().ToString('s'))Z`n")

$rc = 1
try {
    $env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')
    $llvm = 'C:\Program Files (x86)\Microsoft Visual Studio\2022\BuildTools\VC\Tools\Llvm'
    foreach ($d in @("$llvm\x64\bin", "$llvm\ARM64\bin", "$llvm\bin")) {
        if (Test-Path "$d\clang.exe") { $env:Path = "$d;" + $env:Path; break }
    }
    $env:CARGO_TERM_COLOR = 'never'
    $env:CARGO_BUILD_JOBS = '2'

    if (Test-Path $Tree) { Remove-Item $Tree -Recurse -Force }
    New-Item -ItemType Directory -Force $Tree | Out-Null
    tar -xmzf "$S\kit-src.tgz" -C $Tree
    Remove-Item "$S\kit-src.tgz" -Force
    Set-Location "$Tree\sync"
    Note "=== kit build start ($(Get-Content SOURCE -ErrorAction SilentlyContinue))"

    # The client and the orchestrator, each alone (see above), and each taken
    # as soon as it is built: the test build below builds the client again,
    # at the same path, with every crate's features.
    Remove-Item $Out -Recurse -Force -ErrorAction SilentlyContinue
    New-Item -ItemType Directory -Force "$Out\bin", "$Out\tests\bin" | Out-Null
    Copy-Item SOURCE "$Out\bin\SOURCE" -Force
    $rc = 0
    foreach ($built in @(@('jd-daemon', 'joinery-drive.exe'), @('jd-soak', 'jd-soak.exe'))) {
        if ($rc -eq 0) {
            cmd /d /c "cargo build --release --target $Triple --target-dir `"$TestTarget`" -p $($built[0]) >> `"$Log`" 2>&1"
            $rc = $LASTEXITCODE
            Note "build $($built[0]) exit $rc"
            if ($rc -eq 0) { Copy-Item "$TestTarget\$Triple\release\$($built[1])" "$Out\bin\$($built[1])" -Force }
        }
    }
    if ($rc -eq 0) {

        # The tests, compiled where they will run. --message-format names
        # every test program cargo made, so none is guessed at or missed.
        $json = "$S\kit-tests.jsonl"
        cmd /d /c "cargo test --release --workspace --no-run --target $Triple --target-dir `"$TestTarget`" --message-format=json > `"$json`" 2>> `"$Log`""
        $rc = $LASTEXITCODE
        Note "test build exit $rc"
    }
    if ($rc -eq 0) {
        $names = @()
        foreach ($line in Get-Content $json) {
            if ($line -notmatch '"executable":"') { continue }
            $m = $line | ConvertFrom-Json
            if (-not $m.executable -or -not $m.profile.test) { continue }
            Copy-Item $m.executable "$Out\tests\bin\" -Force
            $names += Split-Path $m.executable -Leaf
        }
        [IO.File]::WriteAllLines("$Out\tests\programs.txt", [string[]]$names)
        Note "$($names.Count) test programs"
        # What the two tests that start the client look for, where they look.
        $client = "$TestTarget\$Triple\release\joinery-drive.exe"
        if (-not (Test-Path $client)) { Note "no $client after the test build"; $rc = 1 }
        else { Copy-Item $client "$Out\tests\joinery-drive.exe" -Force }
    }
} finally {
    # The test build is large and the VM's disk is a file on the mini's.
    Remove-Item "$S\tests" -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item $Tree -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item $Lock -Force -ErrorAction SilentlyContinue
    Note "=== kit build exit $rc"
}
