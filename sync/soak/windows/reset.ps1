# Return the Windows soak devices to a fresh state under a given account.
# Archives first, like the Mac line's reset.sh, then wipes and re-links.
#
# The devices are stopped before the archive rather than after it: a state
# store copied with its write-ahead log after its writer has gone is a
# consistent copy, and Windows has no sqlite3 command to take a live backup.
#
# Usage: reset.ps1 <label> <account>     (called by forever.ps1)
param([Parameter(Mandatory = $true)][string]$Label, [Parameter(Mandatory = $true)][string]$Account)
$ErrorActionPreference = 'Stop'
# The log this writes to, and the output read back from jd-soak, are UTF-8.
try { [Console]::OutputEncoding = New-Object Text.UTF8Encoding $false } catch {}
$S = 'C:\soak-win'
$RUN = "$S\run"
$Devices = 'a', 'b'
$archive = "$S\evidence\$Label-$(Get-Date -Format yyyyMMdd-HHmmss)"
$utf8 = New-Object Text.UTF8Encoding $false

# Remove a tree whatever the length of the paths in it. The actors and the
# daemon write paths past 260 characters (a conflict copy of a file deep in
# renamed folders), which Windows PowerShell's own Remove-Item cannot reach;
# robocopy can, so it first mirrors an empty folder over the tree.
function Wipe([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    if (-not (Test-Path -LiteralPath $Path -PathType Container)) { Remove-Item -LiteralPath $Path -Force; return }
    $empty = Join-Path $env:TEMP "soak-win-empty-$PID"
    New-Item -ItemType Directory -Force $empty | Out-Null
    robocopy $empty $Path /MIR /R:2 /W:1 /NFL /NDL /NJH /NJS /NP | Out-Null
    $mirrored = $LASTEXITCODE
    Remove-Item -LiteralPath $empty -Force
    if ($mirrored -ge 8) { throw "robocopy could not empty $Path ($mirrored)" }
    Remove-Item -LiteralPath $Path -Recurse -Force
}

function GZip([string]$From, [string]$To) {
    $in = [IO.File]::OpenRead($From)
    $out = [IO.File]::Create($To)
    $gz = New-Object IO.Compression.GZipStream($out, [IO.Compression.CompressionMode]::Compress)
    try { $in.CopyTo($gz) } finally { $gz.Dispose(); $out.Dispose(); $in.Dispose() }
}

# What the Recycle Bin holds from under $RUN, in every account's folder of it
# (each device recycles into its own account's): each item's `$I` record
# names where it came from (version 2 since Windows 10: a length and then the
# path; version 1: the path in a fixed 520 bytes).
function Recycled-From-Run {
    $records = Get-ChildItem -LiteralPath 'C:\$Recycle.Bin' -Directory -Force -ErrorAction SilentlyContinue |
        ForEach-Object { Get-ChildItem -LiteralPath $_.FullName -Force -Filter '$I*' -ErrorAction SilentlyContinue }
    foreach ($i in $records) {
        $bin = $i.DirectoryName
        try {
            $b = [IO.File]::ReadAllBytes($i.FullName)
            $version = [BitConverter]::ToInt64($b, 0)
            if ($version -ge 2) {
                $chars = [BitConverter]::ToInt32($b, 24)
                $from = [Text.Encoding]::Unicode.GetString($b, 28, [Math]::Max(0, ($chars - 1) * 2))
            } else {
                $from = [Text.Encoding]::Unicode.GetString($b, 24, 520).TrimEnd([char]0)
            }
        } catch { continue }
        if ($from.StartsWith("$RUN\", [StringComparison]::OrdinalIgnoreCase)) {
            [pscustomobject]@{
                From = $from
                Size = [BitConverter]::ToInt64($b, 8)
                Record = $i.FullName
                Item = Join-Path $bin ('$R' + $i.Name.Substring(2))
            }
        }
    }
}

"== stopping the devices"
foreach ($d in $Devices) {
    Stop-ScheduledTask -TaskName "SoakWin-device-$d" -ErrorAction SilentlyContinue
}
# Ending a task ends its loop and leaves the daemon the loop started running,
# so each daemon is ended by the path it runs from, which names one device.
$deadline = (Get-Date).AddSeconds(30)
do {
    $left = @(Get-Process joinery-drive -ErrorAction SilentlyContinue | Where-Object {
            $p = $_.Path; $Devices | Where-Object { $p -eq "$S\bin\device-$_\joinery-drive.exe" } })
    $left | Stop-Process -Force -ErrorAction SilentlyContinue
    if ($left.Count -gt 0) { Start-Sleep 1 }
} while ($left.Count -gt 0 -and (Get-Date) -lt $deadline)
if ($left.Count -gt 0) { throw "a device's daemon will not stop: $($left.Id -join ', ')" }
foreach ($d in $Devices) { Remove-Item "$RUN\device-$d.loop.pid" -Force -ErrorAction SilentlyContinue }
# A partition an interrupted campaign never lifted would cut the next run's
# device off from the start. Deleting a rule that is not there only fails.
foreach ($d in $Devices) {
    cmd /d /c "netsh advfirewall firewall delete rule name=jd-soak-partition-device-$d dir=out >nul 2>&1"
}

"== archiving to $archive"
New-Item -ItemType Directory -Force $archive | Out-Null
if (Test-Path "$RUN\bundles") { Copy-Item "$RUN\bundles\*" $archive -Recurse -Force -ErrorAction SilentlyContinue }
if (Test-Path "$RUN\journal") { Copy-Item "$RUN\journal" "$archive\journal" -Recurse -Force -ErrorAction SilentlyContinue }
foreach ($d in $Devices) {
    $home_ = "$RUN\device-$d\home"
    foreach ($suffix in '', '-wal', '-shm') {
        $db = "$home_\state\state.db$suffix"
        if (Test-Path $db) { Copy-Item $db "$archive\device-$d-state.db$suffix" -Force }
    }
    if (Test-Path "$home_\logs\daemon.log") { Copy-Item "$home_\logs\daemon.log" "$archive\device-$d-daemon.log" -Force }
    # The pass journal: what each pass saw and decided, the pass before a
    # burst included. Up to 128MB a device, so compressed.
    foreach ($f in Get-ChildItem "$home_\logs\passes.jsonl*" -ErrorAction SilentlyContinue) {
        GZip $f.FullName "$archive\device-$d-$($f.Name).gz"
    }
    if (Test-Path "$RUN\device-$d\root") {
        $tree = @(Get-ChildItem -LiteralPath "$RUN\device-$d\root" -Recurse -Force -ErrorAction SilentlyContinue | ForEach-Object FullName)
        [IO.File]::WriteAllLines("$archive\device-$d-tree.txt", [string[]]$tree, $utf8)
    }
}
# What the devices recycled, by where it came from. The items themselves go:
# the bin is this line's to keep bounded, and the next run's verifier must
# not find the last run's contents in it.
$recycled = @(Recycled-From-Run)
$listed = @($recycled | ForEach-Object { "$($_.Size)`t$($_.From)" })
[IO.File]::WriteAllLines("$archive\recycled.txt", [string[]]$listed, $utf8)
foreach ($r in $recycled) {
    try { Wipe $r.Item } catch { "   could not remove $($r.Item): $_" }
    Remove-Item -LiteralPath $r.Record -Force -ErrorAction SilentlyContinue
}
"   $($recycled.Count) recycled item(s) listed and removed"
# Keep the newest 20 archives: the VM's disk is a file on the Mac mini's.
Get-ChildItem "$S\evidence" -Directory | Sort-Object LastWriteTime -Descending | Select-Object -Skip 20 |
    ForEach-Object { Wipe $_.FullName }

"== wiping"
foreach ($p in "$RUN\device-a", "$RUN\device-b", "$RUN\journal", "$RUN\bundles") { Wipe $p }
foreach ($p in "$RUN\journal", "$RUN\bundles") { New-Item -ItemType Directory -Force $p | Out-Null }
foreach ($d in $Devices) {
    New-Item -ItemType Directory -Force "$RUN\device-$d\root", "$RUN\device-$d\home\logs" | Out-Null
}

"== the client"
# A build stages its binaries as .new (build.ps1); they take over here, with
# nothing running from them.
foreach ($f in 'joinery-drive.exe', 'jd-soak.exe', 'SOURCE') {
    if (Test-Path "$S\bin\$f.new") { Move-Item "$S\bin\$f.new" "$S\bin\$f" -Force; "   $f updated" }
}
foreach ($d in $Devices) {
    New-Item -ItemType Directory -Force "$S\bin\device-$d" | Out-Null
    Copy-Item "$S\bin\joinery-drive.exe" "$S\bin\device-$d\joinery-drive.exe" -Force
}

"== provisioning under $Account"
# Each device links as its own account (provision.ps1, through its task). The
# password goes in a file only that account and this one can read, made
# empty and closed first, and gone as soon as it has been read.
$password = (Get-Content "$S\soak.env" | Where-Object { $_ -match '^JD_SOAK_PASSWORD=' } | Select-Object -First 1)
if (-not $password) { throw "no JD_SOAK_PASSWORD in $S\soak.env" }
foreach ($d in $Devices) {
    $secret = "$RUN\device-$d\provision.env"
    $exit = "$RUN\device-$d\provision.exit"
    Remove-Item $exit -Force -ErrorAction SilentlyContinue
    New-Item -ItemType File -Force $secret | Out-Null
    icacls $secret /inheritance:r /grant:r "$($env:USERNAME):F" "soak-$($d):F" | Out-Null
    [IO.File]::WriteAllText($secret, "JD_SOAK_ACCOUNT=$Account`n$password`n")
    try {
        Start-ScheduledTask -TaskName "SoakWin-provision-$d"
        $deadline = (Get-Date).AddSeconds(180)
        while (-not (Test-Path $exit) -and (Get-Date) -lt $deadline) { Start-Sleep 1 }
    } finally {
        Remove-Item $secret -Force -ErrorAction SilentlyContinue
    }
    Get-Content "$RUN\device-$d\provision.log" -Encoding UTF8 -ErrorAction SilentlyContinue
    $rc = Get-Content $exit -ErrorAction SilentlyContinue
    if ("$rc".Trim() -ne '0') { throw "device-$d could not be linked ($(if ($rc) { "exit $rc" } else { 'no answer' }))" }
}

"== starting the devices"
foreach ($d in $Devices) { Start-ScheduledTask -TaskName "SoakWin-device-$d" }
Start-Sleep 5
foreach ($d in $Devices) {
    $loop = Get-Content "$RUN\device-$d.loop.pid" -ErrorAction SilentlyContinue
    if (-not ($loop -and (Get-Process -Id $loop -ErrorAction SilentlyContinue))) { throw "device-$d did not start" }
    "   device-$d up"
}
