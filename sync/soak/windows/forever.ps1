# Windows soak campaigns, back to back, on real NTFS: a separate campaign from
# the ext4 rig on 45.33.72.32 and the two Mac lines, with its own accounts and
# its own ledger, in the same format as the Mac line's.
#
# Each run takes the next account from accounts.txt (fresh account per run, as
# the rig does) and the line stops when the pool is used up. The pool is fixed
# because this VM cannot create accounts on the soak server, and a fixed pool
# also caps what these runs leave on that server's disk.
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
# One run starts every INTERVAL seconds at most.
$INTERVAL = 7200

function Stamp { (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ') }
function Note($m) { [IO.File]::AppendAllText("$S\forever.log", "[$(Stamp)] $m`n", $utf8) }
function Ledger($m) { [IO.File]::AppendAllText($LEDGER, "$(Stamp) | $m`n", $utf8) }
function Md5Of($f) { (Get-FileHash -Algorithm MD5 $f).Hash.Substring(0, 8).ToLower() }

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

while ($true) {
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

    cmd /d /c "powershell -NoProfile -ExecutionPolicy Bypass -File `"$S\reset.ps1`" run$($n - 1) $ACCOUNT > `"$S\reset.log`" 2>&1"
    if ($LASTEXITCODE -ne 0) {
        Note "reset failed (see reset.log)"
        Ledger "run $n | $ACCOUNT | RESET FAILED"
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
        Start-Sleep 600; continue
    }

    $RUN_SEED = $SEED + $n
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
    if ($FAIL) { $STATUS = $FAIL }
    elseif ([int]("0$ASSERT") -gt 0) { $STATUS = 'green' }
    else { $STATUS = "NO VERDICT: 0 assertions (rc=$RC), not a pass" }
    function Or($v) { if ($v) { $v } else { '?' } }

    Ledger "run $n | $ACCOUNT | client $CLIENT | seed $RUN_SEED | violations $(Or $VIOL) | ops $(Or $OPS) | kills $KILLS | faults $(Or $FAULTS_N) refused $REFUSED | $STATUS"
    Note "run $n done: $(Or $VIOL) violations"

    # The rest of the interval, a minute at a time so STOP is seen promptly.
    while (((Get-Date) - $started).TotalSeconds -lt $INTERVAL -and -not (Test-Path $STOP)) { Start-Sleep 60 }
}
