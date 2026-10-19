# Take the Windows soak line off a person's computer again: everything
# install_pc.ps1 and install.ps1 -PersonalComputer put there. uninstall.cmd
# starts it elevated. Safe to run again, and on a half-finished install.
#
# The ledger goes to the mailbox first, so the record outlives the computer's
# copy. The accounts the runs used on the soak server stay there; the rig's
# purge empties them.
$ErrorActionPreference = 'Continue'
$S = 'C:\soak-win'
$Devices = 'a', 'b'
Set-Location $env:TEMP

$me = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $me.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host 'This needs administrator rights: double-click uninstall.cmd and answer Yes.' -ForegroundColor Red
    exit 1
}

Write-Host '== stopping'
if (Test-Path $S) { New-Item -ItemType File -Force "$S\STOP" | Out-Null }
foreach ($t in @(Get-ScheduledTask -TaskName 'SoakWin-*' -ErrorAction SilentlyContinue)) {
    Stop-ScheduledTask -TaskName $t.TaskName -ErrorAction SilentlyContinue
}
# Ending a task leaves what its script started running, so everything else is
# ended by where it runs from -- the daemons, the orchestrator, a test program
# mid-suite -- and the scripts' own PowerShells by what they were started on.
# Nothing outside C:\soak-win is touched, and this script is not ended.
Get-Process -ErrorAction SilentlyContinue |
    Where-Object { $_.Path -and $_.Path.StartsWith("$S\", [StringComparison]::OrdinalIgnoreCase) } |
    Stop-Process -Force -ErrorAction SilentlyContinue
Get-CimInstance Win32_Process -Filter "Name = 'powershell.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.ProcessId -ne $PID -and $_.CommandLine -and $_.CommandLine.IndexOf("$S\", [StringComparison]::OrdinalIgnoreCase) -ge 0 } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
foreach ($d in $Devices) {
    cmd /d /c "netsh advfirewall firewall delete rule name=jd-soak-partition-device-$d dir=out >nul 2>&1"
}
'   stopped'

Write-Host '== the ledger, to the mailbox'
if ((Test-Path "$S\report.env") -and (Test-Path "$S\ledger.txt") -and (Test-Path "$S\bin\jd-soak.exe")) {
    $server = (Get-Content "$S\fleet.json" -Raw | ConvertFrom-Json).server
    foreach ($l in Get-Content "$S\report.env") {
        if ($l -match '^\s*(JD_REPORT_[A-Z]+)=(.*)$') { Set-Item "env:$($Matches[1])" $Matches[2] }
    }
    $line = 'pc'
    foreach ($l in Get-Content "$S\line.env" -ErrorAction SilentlyContinue) { if ($l -match '^\s*LINE=([a-z0-9-]+)') { $line = $Matches[1] } }
    $said = & "$S\bin\jd-soak.exe" send "$S\ledger.txt" --server $server --name "$line-$($env:COMPUTERNAME.ToLower())-uninstalled-ledger-$((Get-Date).ToUniversalTime().ToString('yyyyMMdd-HHmmss')).txt" 2>&1
    Remove-Item env:JD_REPORT_PASSWORD, env:JD_REPORT_ACCOUNT -ErrorAction SilentlyContinue
    "   $said"
} else { '   nothing to send' }

Write-Host '== the tasks'
foreach ($t in @(Get-ScheduledTask -TaskName 'SoakWin-*' -ErrorAction SilentlyContinue)) {
    Unregister-ScheduledTask -TaskName $t.TaskName -Confirm:$false
    "   removed $($t.TaskName)"
}

Write-Host '== the two soak accounts'
$hide = 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\SpecialAccounts\UserList'
foreach ($d in $Devices) {
    $name = "soak-$d"
    $user = Get-LocalUser $name -ErrorAction SilentlyContinue
    if ($user) {
        $sid = $user.SID.Value
        # Its Recycle Bin, its profile (and the credentials kept in it), then
        # the account itself.
        if (Test-Path "C:\`$Recycle.Bin\$sid") { cmd /d /c "rd /s /q `"C:\`$Recycle.Bin\$sid`"" }
        Get-CimInstance Win32_UserProfile | Where-Object { $_.SID -eq $sid } | Remove-CimInstance -ErrorAction SilentlyContinue
        Remove-LocalUser $name
        "   removed $name"
    }
    Remove-ItemProperty $hide $name -ErrorAction SilentlyContinue
}

Write-Host '== C:\soak-win'
if (Test-Path $S) {
    # robocopy reaches paths past 260 characters, which the runs make and
    # Remove-Item cannot; it mirrors an empty folder over the tree first.
    $empty = Join-Path $env:TEMP "soak-win-empty-$PID"
    New-Item -ItemType Directory -Force $empty | Out-Null
    robocopy $empty $S /MIR /R:2 /W:1 /NFL /NDL /NJH /NJS /NP | Out-Null
    Remove-Item $empty -Force
    Remove-Item $S -Recurse -Force -ErrorAction SilentlyContinue
}
if (Test-Path $S) { Write-Host "   $S could not be removed completely; delete it by hand" -ForegroundColor Yellow }
else { '   removed' }

Write-Host ''
Write-Host 'Uninstalled.' -ForegroundColor Green
