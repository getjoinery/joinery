# Windows soak campaigns, back to back, on real NTFS: a separate campaign from
# the ext4 rig on 45.33.72.32 and the two Mac lines, with its own accounts and
# its own ledger, in the same format as the Mac line's.
#
# Each run takes the next account from accounts.txt (fresh account per run, as
# the rig does) and the line stops when the pool is used up. The pool is fixed
# because this VM cannot create accounts on the soak server, and a fixed pool
# also caps what these runs leave on that server's disk.
#
# On a person's own computer (the kit, make_pc_kit.sh) two files change what
# it does, and the VM has neither:
#   line.env    LINE=<name> and SEED=<base>, so that line draws its own seeds
#   report.env  JD_REPORT_ACCOUNT and JD_REPORT_PASSWORD: the line's mailbox
#               (jd-soak send), where every ledger line goes, with the
#               evidence of any run that is not green, because nobody can
#               reach that computer to read them
#
# A run the computer slept through, or restarted in, is ledgered INTERRUPTED
# and counts for nothing either way: its timings are not the client's.
#
# Start: Start-ScheduledTask SoakWin-Forever   (also starts with the VM)
# Stop:  New-Item C:\soak-win\STOP   (finishes the run in flight, then exits)
# Read:  Get-Content C:\soak-win\ledger.txt
$ErrorActionPreference = 'Continue'
try { [Console]::OutputEncoding = New-Object Text.UTF8Encoding $false } catch {}
$S = 'C:\soak-win'
$RUN = "$S\run"
$LEDGER = "$S\ledger.txt"
$POOL = "$S\accounts.txt"
$NEXT = "$S\next_account"
$STOP = "$S\STOP"
$utf8 = New-Object Text.UTF8Encoding $false

# The Mac line's intensity: lower than the ext4 rig's (6 cycles, 2 s pace),
# per the soak spec.
$CYCLES = 2
$STORM = 120
$SETTLE = 900
$FAULTS = 60
$PACE = 4000
$SEED = 29000
$LINE = 'vm'
if (Test-Path "$S\line.env") {
    foreach ($l in Get-Content "$S\line.env") {
        if ($l -match '^\s*SEED=([0-9]+)\s*$') { $SEED = [int]$Matches[1] }
        if ($l -match '^\s*LINE=([a-z0-9-]+)\s*$') { $LINE = $Matches[1] }
    }
}
$INFLIGHT = "$S\run\IN-FLIGHT"
# One run starts every INTERVAL seconds at most.
$INTERVAL = 7200

function Stamp { (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ') }
function Note($m) { [IO.File]::AppendAllText("$S\forever.log", "[$(Stamp)] $m`n", $utf8) }
function Ledger($m) { [IO.File]::AppendAllText($LEDGER, "$(Stamp) | $m`n", $utf8) }
function Md5Of($f) { (Get-FileHash -Algorithm MD5 $f).Hash.Substring(0, 8).ToLower() }

# Leave a file in the line's mailbox, when it has one. The password is in the
# environment for the one command and gone after it; a send that fails is
# noted and never stops the line.
#
# Says whether it went. A message that did not go leaves RESEND-LEDGER, and
# the next one that does takes the whole ledger with it, so a line lost to a
# network blip is not lost for good.
$RESEND = "$S\logs\RESEND-LEDGER"
function Send-Mail([string]$File, [string]$Name) {
    if (-not (Test-Path "$S\report.env")) { return $false }
    $server = (Get-Content "$S\fleet.json" -Raw | ConvertFrom-Json).server
    foreach ($l in Get-Content "$S\report.env") {
        if ($l -match '^\s*(JD_REPORT_[A-Z]+)=(.*)$') { Set-Item "env:$($Matches[1])" $Matches[2] }
    }
    try {
        $said = cmd /d /c "`"$S\bin\jd-soak.exe`" send `"$File`" --server $server --name `"$Name`" 2>&1"
        $sent = $LASTEXITCODE -eq 0
        Note "mailbox: $Name ($LASTEXITCODE) $said"
        if (-not $sent) { [IO.File]::WriteAllText($RESEND, "$(Stamp)`n") }
        elseif ((Test-Path $RESEND) -and $File -ne $LEDGER) {
            $whole = cmd /d /c "`"$S\bin\jd-soak.exe`" send `"$LEDGER`" --server $server --name `"$LINE-$($env:COMPUTERNAME.ToLower())-ledger-$((Get-Date).ToUniversalTime().ToString('yyyyMMdd-HHmmss')).txt`" 2>&1"
            Note "mailbox: the whole ledger, after a message that did not go ($LASTEXITCODE) $whole"
            if ($LASTEXITCODE -eq 0) { Remove-Item $RESEND -Force -ErrorAction SilentlyContinue }
        }
        $sent
    } finally {
        Remove-Item env:JD_REPORT_PASSWORD, env:JD_REPORT_ACCOUNT -ErrorAction SilentlyContinue
    }
}

# The ledger line, and the run's evidence when it is not green, to the mailbox.
function Mail-Run([int]$N, [string]$Line, [bool]$Green) {
    if (-not (Test-Path "$S\report.env")) { return }
    $stamp = (Get-Date).ToUniversalTime().ToString('yyyyMMdd-HHmmss')
    $base = "$LINE-$($env:COMPUTERNAME.ToLower())-run$N-$stamp"
    $note = "$S\logs\$base.txt"
    $body = @($Line, '') + @(Get-Content "$RUN\journal\report.txt" -ErrorAction SilentlyContinue)
    [IO.File]::WriteAllLines($note, [string[]]$body, $utf8)
    $null = Send-Mail $note "$base-$(if ($Green) { 'green' } else { 'NOT-GREEN' }).txt"
    Remove-Item $note -Force -ErrorAction SilentlyContinue
    if ($Green) { return }
    # What reset.ps1 would archive, from where it still stands: the bundles,
    # the journal, each device's store and logs. The pass journals only when
    # they are small; the archive on this computer keeps them whatever.
    $pack = "$S\logs\$base-evidence"
    Remove-Item $pack -Recurse -Force -ErrorAction SilentlyContinue
    New-Item -ItemType Directory -Force $pack | Out-Null
    Copy-Item "$RUN\run$N.log" $pack -ErrorAction SilentlyContinue
    foreach ($d in 'journal', 'bundles') {
        if (Test-Path "$RUN\$d") { cmd /d /c "robocopy `"$RUN\$d`" `"$pack\$d`" /E /R:1 /W:1 /NFL /NDL /NJH /NJS /NP >nul" }
    }
    foreach ($dev in 'a', 'b') {
        $h = "$RUN\device-$dev\home"
        foreach ($f in 'state\state.db', 'state\state.db-wal', 'logs\daemon.log') {
            if (Test-Path "$h\$f") { Copy-Item "$h\$f" "$pack\device-$dev-$(Split-Path $f -Leaf)" -ErrorAction SilentlyContinue }
        }
        $passes = @(Get-ChildItem "$h\logs\passes.jsonl*" -ErrorAction SilentlyContinue)
        if (($passes | Measure-Object Length -Sum).Sum -lt 40MB) { $passes | Copy-Item -Destination $pack -ErrorAction SilentlyContinue }
    }
    $zip = "$pack.tgz"
    cmd /d /c "tar -czf `"$zip`" -C `"$S\logs`" `"$base-evidence`" >nul 2>&1"
    if (Test-Path $zip) { $null = Send-Mail $zip "$base-evidence.tgz" } else { Note "mailbox: the evidence for run $N could not be packed" }
    Remove-Item $pack, $zip -Recurse -Force -ErrorAction SilentlyContinue
}

# Slept-Since and Stay-Awake.
. "$S\power.ps1"

# The smoke test reads the device's state store, as the Mac line's does with
# sqlite3. Windows has no sqlite3 command, but it ships SQLite itself.
Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
using System.Text;
public static class SoakStore {
    [DllImport("winsqlite3.dll")] static extern int sqlite3_open_v2(byte[] name, out IntPtr db, int flags, IntPtr vfs);
    [DllImport("winsqlite3.dll")] static extern int sqlite3_prepare_v2(IntPtr db, byte[] sql, int n, out IntPtr stmt, IntPtr tail);
    [DllImport("winsqlite3.dll")] static extern int sqlite3_step(IntPtr stmt);
    [DllImport("winsqlite3.dll")] static extern int sqlite3_finalize(IntPtr stmt);
    [DllImport("winsqlite3.dll")] static extern int sqlite3_close(IntPtr db);
    static byte[] Z(string s) { return Encoding.UTF8.GetBytes(s + "\0"); }
    // 1 when the query returns a row, 0 when it returns none, -1 when the
    // store cannot be read.
    public static int AnyRow(string path, string sql) {
        IntPtr db;
        if (sqlite3_open_v2(Z(path), out db, 1 /* SQLITE_OPEN_READONLY */, IntPtr.Zero) != 0) { sqlite3_close(db); return -1; }
        try {
            IntPtr stmt;
            if (sqlite3_prepare_v2(db, Z(sql), -1, out stmt, IntPtr.Zero) != 0) return -1;
            try {
                int rc = sqlite3_step(stmt);
                return rc == 100 ? 1 : rc == 101 ? 0 : -1;
            } finally { sqlite3_finalize(stmt); }
        } finally { sqlite3_close(db); }
    }
}
'@

# A scheduled task runs one instance at a time; this is for a loop started by hand.
if (Test-Path "$S\.driver.pid") {
    $other = Get-Content "$S\.driver.pid" -ErrorAction SilentlyContinue
    if ($other -and $other -ne "$PID" -and (Get-Process -Id $other -ErrorAction SilentlyContinue | Where-Object ProcessName -eq 'powershell')) {
        Note "another driver is running; exiting"; exit 0
    }
}
[IO.File]::WriteAllText("$S\.driver.pid", "$PID")
if (-not (Test-Path $LEDGER)) { [IO.File]::WriteAllText($LEDGER, '') }
if (-not (Test-Path $NEXT)) { [IO.File]::WriteAllText($NEXT, "1`n") }
# A run still marked in flight is one this loop never finished: the computer
# restarted under it. Said, so the ledger has no silent gaps.
if (Test-Path $INFLIGHT) {
    $was = (Get-Content $INFLIGHT -ErrorAction SilentlyContinue | Select-Object -First 1)
    Remove-Item $INFLIGHT -Force
    Ledger "$was | INTERRUPTED: the computer restarted during the run, not counted"
    $was_n = if ($was -match '^run ([0-9]+)') { [int]$Matches[1] } else { 0 }
    Mail-Run $was_n "$(Stamp) | $was | INTERRUPTED: the computer restarted during the run, not counted" $true
}

# The kit's test suite (run_tests.ps1, task SoakWin-Tests) goes first, on a
# quiet computer, and is seen to its end: a suite the computer restarted in
# (its RUNNING mark left with no task running) or slept through (its summary
# says INTERRUPTED) is started again, three times at most, and each such
# interruption is ledgered. Its summary goes to the mailbox once, retried at
# every turn of the loop until it has gone. The VM has no such task.
$TESTS = "$S\tests"
function Suite-Alive { "$((Get-ScheduledTask SoakWin-Tests -ErrorAction SilentlyContinue).State)" -in 'Running', 'Queued' }
function Suite-Head { "$(Get-Content "$TESTS\summary.txt" -ErrorAction SilentlyContinue | Select-Object -First 1)" }
function Mail-Suite {
    if (-not (Test-Path "$TESTS\summary.txt") -or (Test-Path "$TESTS\RUNNING") -or (Test-Path "$TESTS\mailed")) { return }
    $base = "$LINE-$($env:COMPUTERNAME.ToLower())-tests-$((Get-Date).ToUniversalTime().ToString('yyyyMMdd-HHmmss'))"
    $text = Get-Content "$TESTS\summary.txt" -Raw
    # From the programs line: the line above it names the client's source,
    # whose commit subject may say "failed" too.
    $failing = if ($text -match '(?m)^[0-9]+ test programs: passed [0-9]+, failed ([0-9]+)') { [int]$Matches[1] } else { -1 }
    $green = $failing -eq 0 -and -not (Suite-Head).StartsWith('INTERRUPTED')
    if (-not (Send-Mail "$TESTS\summary.txt" "$base-$(if ($green) { 'green' } else { 'NOT-GREEN' }).txt")) { return }
    if ($failing -ne 0) {
        $zip = "$S\logs\$base-logs.tgz"
        cmd /d /c "tar -czf `"$zip`" -C `"$TESTS`" results >nul 2>&1"
        if (Test-Path $zip) { $null = Send-Mail $zip "$base-logs.tgz"; Remove-Item $zip -Force }
    }
    [IO.File]::WriteAllText("$TESTS\mailed", "$(Stamp)`n")
}
if (Get-ScheduledTask SoakWin-Tests -ErrorAction SilentlyContinue) {
    # install_pc.ps1 starts the suite a moment before this loop; give its
    # task time to show as running before reading it as one that ended.
    Start-Sleep 30
    $tries = 0
    while (-not (Test-Path $STOP)) {
        if (Suite-Alive) { Start-Sleep 60; continue }
        $whole = (Test-Path "$TESTS\summary.txt") -and -not (Test-Path "$TESTS\RUNNING") -and -not (Suite-Head).StartsWith('INTERRUPTED')
        if ($whole -or $tries -ge 3) { break }
        if (Test-Path "$TESTS\RUNNING") { Ledger "test suite | INTERRUPTED: the computer restarted during it, started again" }
        elseif ((Suite-Head).StartsWith('INTERRUPTED')) { Ledger "test suite | $(Suite-Head), started again" }
        $tries++
        Remove-Item "$TESTS\summary.txt", "$TESTS\mailed" -Force -ErrorAction SilentlyContinue
        [IO.File]::WriteAllText("$TESTS\RUNNING", "$(Stamp) attempt $tries`n")
        Start-ScheduledTask SoakWin-Tests
        Start-Sleep 30
    }
    Remove-Item "$TESTS\RUNNING" -Force -ErrorAction SilentlyContinue
    Mail-Suite
}

while ($true) {
    if (Get-ScheduledTask SoakWin-Tests -ErrorAction SilentlyContinue) { Mail-Suite }
    if (Test-Path $STOP) { Note "STOP present, exiting"; Remove-Item "$S\.driver.pid" -Force; exit 0 }
    $started = Get-Date
    $n = [int](Get-Content $NEXT)
    $ACCOUNT = (Get-Content $POOL | Where-Object { $_.Trim() } | Select-Object -Index ($n - 1))
    if (-not $ACCOUNT) {
        Note "account pool used up after $($n - 1) run(s); exiting"
        Ledger "pool used up after $($n - 1) run(s); line stopped"
        Remove-Item "$S\.driver.pid" -Force; exit 0
    }
    $ACCOUNT = $ACCOUNT.Trim()
    [IO.File]::WriteAllText($NEXT, "$($n + 1)`n")
    Note "=== run $n under $ACCOUNT"
    Stay-Awake $true

    cmd /d /c "powershell -NoProfile -ExecutionPolicy Bypass -File `"$S\reset.ps1`" run$($n - 1) $ACCOUNT > `"$S\reset.log`" 2>&1"
    if ($LASTEXITCODE -ne 0) {
        Note "reset failed (see reset.log)"
        Ledger "run $n | $ACCOUNT | RESET FAILED"
        Stay-Awake $false
        $null = Send-Mail "$S\reset.log" "$LINE-$($env:COMPUTERNAME.ToLower())-run$n-$((Get-Date).ToUniversalTime().ToString('yyyyMMdd-HHmmss'))-RESET-FAILED.txt"
        Start-Sleep 600; continue
    }
    $CLIENT = Md5Of "$S\bin\joinery-drive.exe"

    $D = "$RUN\device-a\root\smoke$n"
    New-Item -ItemType Directory -Force $D | Out-Null
    [IO.File]::WriteAllText("$D\probe.txt", "smoke v1`n")
    Start-Sleep 45
    [IO.File]::WriteAllText("$D\probe.txt", "smoke v2`n")
    Start-Sleep 45
    $synced = [SoakStore]::AnyRow("$RUN\device-a\home\state\state.db",
        "SELECT 1 FROM entries WHERE remote_name = 'probe.txt' AND server_id > 0 AND local_status = 'synced' LIMIT 1;")
    if ($synced -ne 1) {
        Note "smoke test failed ($synced)"
        Ledger "run $n | $ACCOUNT | client $CLIENT | SMOKE FAILED"
        Stay-Awake $false
        Mail-Run $n "$(Stamp) | run $n | $ACCOUNT | client $CLIENT | SMOKE FAILED" $false
        Start-Sleep 600; continue
    }

    $RUN_SEED = $SEED + $n
    [IO.File]::WriteAllText($INFLIGHT, "run $n | $ACCOUNT | client $CLIENT | seed $RUN_SEED`n")
    $runlog = "$RUN\run$n.log"
    $built = (Get-Item "$S\bin\joinery-drive.exe").LastWriteTime.ToString('yyyy-MM-dd HH:mm')
    [IO.File]::WriteAllText($runlog, "client $CLIENT (built $built from $(Get-Content "$S\bin\SOURCE" -ErrorAction SilentlyContinue))`norchestrator $(Md5Of "$S\bin\jd-soak.exe")`n", $utf8)
    foreach ($line in Get-Content "$S\soak.env") {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') { Set-Item "env:$($Matches[1])" $Matches[2] }
    }
    $env:JD_SOAK_ACCOUNT = $ACCOUNT
    cmd /d /c "`"$S\bin\jd-soak.exe`" orchestrate `"$S\fleet.json`" --cycles $CYCLES --storm-seconds $STORM --settle-seconds $SETTLE --fault-seconds $FAULTS --pace-ms $PACE --seed $RUN_SEED >> `"$runlog`" 2>&1"
    $RC = $LASTEXITCODE
    Remove-Item env:JD_SOAK_PASSWORD -ErrorAction SilentlyContinue
    Copy-Item $runlog "$S\logs\" -Force

    $R = Get-Content "$RUN\journal\report.txt" -ErrorAction SilentlyContinue
    function First($pattern) { $m = $R | Select-String -Pattern $pattern | Select-Object -First 1; if ($m) { $m.Matches[0].Groups[1].Value } }
    $VIOL = First '^INVARIANT VIOLATIONS: ([0-9]+)'
    $OPS = First '^Actor operations ([0-9]+) committed'
    $KILLS = 0; foreach ($l in $R) { if ($l -match '^  kill \(') { $KILLS += [int](($l -split '\s+')[-1]) } }
    $FAULTS_N = First '^Faults injected *([0-9]+)'
    $REFUSED = First '^Faults injected.*\(([0-9]+) could not be injected\)'
    if (-not $REFUSED) { $REFUSED = 0 }
    $ASSERT = First '^Assertions passed ([0-9]+)'
    $FAIL = Get-ChildItem "$RUN\bundles\*\verdicts.txt" -ErrorAction SilentlyContinue | Get-Content |
        Where-Object { $_ -like 'FAIL *' } | Select-Object -First 1
    # A run the computer slept through counts for nothing, but what it found
    # is still said, and its evidence still sent: a sleep alone breaks no
    # invariant, so a violation there is one worth reading.
    $slept = Slept-Since $started
    if ($slept) {
        $STATUS = "INTERRUPTED: the computer slept during the run ($slept), not counted"
        if ($FAIL) { $STATUS += " | it found: $FAIL" }
    }
    elseif ($FAIL) { $STATUS = $FAIL }
    elseif ([int]("0$ASSERT") -gt 0) { $STATUS = 'green' }
    else { $STATUS = "NO VERDICT: 0 assertions (rc=$RC), not a pass" }
    function Or($v) { if ($v) { $v } else { '?' } }

    $entry = "run $n | $ACCOUNT | client $CLIENT | seed $RUN_SEED | violations $(Or $VIOL) | ops $(Or $OPS) | kills $KILLS | faults $(Or $FAULTS_N) refused $REFUSED | $STATUS"
    Ledger $entry
    Remove-Item $INFLIGHT -Force -ErrorAction SilentlyContinue
    Stay-Awake $false
    Note "run $n done: $(Or $VIOL) violations"
    Mail-Run $n "$(Stamp) | $entry" ($STATUS -eq 'green' -or ($STATUS -like 'INTERRUPTED*' -and -not $FAIL))

    # The rest of the interval, a minute at a time so STOP is seen promptly.
    while (((Get-Date) - $started).TotalSeconds -lt $INTERVAL -and -not (Test-Path $STOP)) { Start-Sleep 60 }
}
