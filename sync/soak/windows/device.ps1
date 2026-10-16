# One Windows soak device: run its daemon, and start it again whenever it exits.
#
# This loop is the device's supervisor, the job systemd's Restart=always does
# on the ext4 rig and device.sh does on the Mac: a kill is a fault the client
# must recover from, not a device that left the fleet. It runs as the
# scheduled task SoakWin-device-<L>, as the device's own account (install.ps1
# says why a task, and why that account). It ends with the task; the daemon
# it started does not, so whatever ends the task ends the daemon too
# (reset.ps1, and jd-soak's restart fault).
#
# Usage: device.ps1 a|b     (started and stopped by reset.ps1, through its task)
param([Parameter(Mandatory = $true)][string]$L)
$S = 'C:\soak-win'
$RUN = "$S\run"
$env:JOINERY_DRIVE_HOME = "$RUN\device-$L\home"
# The per-pass decision journal (logs\passes.jsonl). Soak devices only: it
# records file names, so no user's client turns it on.
$env:JOINERY_DRIVE_PASS_JOURNAL = '1'
# This device's own copy of the program: the fault agent finds this daemon by
# it, and the firewall cuts this daemon alone by it (fleet.json "program").
$exe = "$S\bin\device-$L\joinery-drive.exe"
$log = "$env:JOINERY_DRIVE_HOME\logs\daemon.log"
New-Item -ItemType Directory -Force (Split-Path $log) | Out-Null
[IO.File]::WriteAllText("$RUN\device-$L.loop.pid", "$PID")
while ($true) {
    # cmd does the redirection, appending the daemon's bytes as written:
    # PowerShell 5.1 would re-encode them and wrap stderr lines in error records.
    cmd /d /c "`"$exe`" daemon >> `"$log`" 2>&1"
    $rc = $LASTEXITCODE
    $at = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    [IO.File]::AppendAllText($log, "[$at] daemon exited ($rc), restarting`n")
    Start-Sleep 2
}
