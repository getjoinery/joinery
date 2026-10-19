# Install the Windows soak line on a person's own computer, from the kit
# make_pc_kit.sh makes. install.cmd, beside this file, starts it elevated.
#
# Checks the computer first and changes nothing if any check fails: an x64
# processor (the kit's programs are x64), an NTFS C: drive with room, and no
# line here already. Then it copies the kit to C:\soak-win, keeps the two
# passwords where only this account can read them (and removes them from the
# kit's folder), runs install.ps1 -PersonalComputer, and starts the test suite
# and, after it, the soak loop. Everything after that runs by itself and
# reports to the line's mailbox; uninstall.cmd takes it all away again.
$ErrorActionPreference = 'Stop'
$Kit = $PSScriptRoot
$S = 'C:\soak-win'
$utf8 = New-Object Text.UTF8Encoding $false

function Refuse([string]$why) {
    Write-Host ''
    Write-Host "Not installed: $why" -ForegroundColor Red
    Write-Host 'Nothing on this computer was changed.'
    exit 1
}

# Once anything has been changed, a failure says so, and how to undo it.
function Broke([string]$why) {
    Write-Host ''
    Write-Host "Not installed: $why" -ForegroundColor Red
    Write-Host 'Part of it was installed. Double-click C:\soak-win\uninstall.cmd to remove it, then try again.'
    exit 1
}

Write-Host '== checking this computer'
$me = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $me.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Refuse 'this needs administrator rights. Double-click install.cmd and answer Yes.'
}
$arch = (Get-CimInstance Win32_Processor | Select-Object -First 1).Architecture
if ($arch -ne 9) { Refuse "this kit is for x64 processors, and this one is not (Win32_Processor architecture $arch)." }
$c = Get-Volume -DriveLetter C
if ($c.FileSystem -ne 'NTFS') { Refuse "C: is $($c.FileSystem), and the soak runs on NTFS." }
if ($c.SizeRemaining -lt 15GB) { Refuse "C: has $([math]::Round($c.SizeRemaining / 1GB, 1)) GB free; the soak needs 15 GB." }
if (Get-ScheduledTask -TaskName 'SoakWin-*' -ErrorAction SilentlyContinue) {
    Refuse 'the soak is installed here already. Run uninstall.cmd first to start again.'
}
foreach ($f in 'files\forever.ps1', 'files\power.ps1', 'files\uninstall.ps1', 'files\uninstall.cmd',
    'files\bin\joinery-drive.exe', 'files\bin\jd-soak.exe', 'files\tests\programs.txt', 'files\tests\joinery-drive.exe',
    'files\accounts.txt', 'secrets\soak.env', 'secrets\report.env') {
    if (-not (Test-Path "$Kit\$f")) { Refuse "the kit is missing $f. Unzip the whole kit and run install.cmd from inside it." }
}
'   x64, NTFS, room on C:, nothing installed yet'

Write-Host '== copying the kit to C:\soak-win'
# Files from a download carry a mark that makes Windows ask before running
# them; these are run by scheduled tasks, where nobody would see the question.
Get-ChildItem $Kit -Recurse -File | Unblock-File
New-Item -ItemType Directory -Force $S | Out-Null
# Writable by SYSTEM and Administrators only, and so everything copied into
# it: the scheduled tasks run its scripts elevated, and a folder any user
# could write (what C:\ gives a new folder) would let any program on this
# computer change what they run.
icacls $S /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null
if ($LASTEXITCODE -ne 0) { Broke "$S could not be closed to other accounts (icacls $LASTEXITCODE)." }
robocopy "$Kit\files" $S /E /R:1 /W:1 /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { Broke "the kit could not be copied to $S (robocopy $LASTEXITCODE)." }
# The two tests that start the client find it where it was built.
$client = "$S\tests\target\x86_64-pc-windows-msvc\release"
New-Item -ItemType Directory -Force $client | Out-Null
Move-Item "$S\tests\joinery-drive.exe" "$client\joinery-drive.exe" -Force

Write-Host '== the passwords, readable by this account only'
foreach ($f in 'soak.env', 'report.env') {
    # Made empty and closed to everyone else first, then written.
    New-Item -ItemType File -Force "$S\$f" | Out-Null
    icacls "$S\$f" /inheritance:r /grant:r "$($env:USERNAME):F" | Out-Null
    [IO.File]::WriteAllText("$S\$f", [IO.File]::ReadAllText("$Kit\secrets\$f"), $utf8)
}
Remove-Item "$Kit\secrets" -Recurse -Force
'   copied, and removed from the kit folder'

Write-Host '== accounts and tasks'
try { & "$S\install.ps1" -PersonalComputer } catch { Broke "the accounts and tasks could not be set up: $_" }

Write-Host '== starting'
[IO.File]::WriteAllText("$S\tests\RUNNING", "$((Get-Date).ToUniversalTime().ToString('s'))Z`n")
try {
    Start-ScheduledTask SoakWin-Tests
    Start-ScheduledTask SoakWin-Forever
} catch { Broke "the soak could not be started: $_" }
'   the test suite is running; the soak starts when it is done'
# Everything is in. Nothing below may stop the script short of saying so:
# a mailbox that cannot be reached is a warning, not a failed install.
$ErrorActionPreference = 'Continue'

# A computer with modern standby (most laptops now) goes into standby when its
# screen turns off, and nothing runs then: no keep-awake request stops it.
# Such a computer only soaks with its screen kept on while plugged in, which
# is the person's own setting to make; this says whether it is made. Two
# timeouts turn the screen off: the ordinary one, and a hidden one that
# applies while the computer is locked (a minute, unless changed), so locking
# it stops the soak a minute later. Modern standby is read from the power
# capabilities (AoAc, byte 20 of SYSTEM_POWER_CAPABILITIES; S3 is byte 5),
# the timeouts from the active power scheme, neither from text that changes
# with the language.
Add-Type -Namespace SoakPower -Name Caps -MemberDefinition @'
[DllImport("powrprof.dll")] [return: MarshalAs(UnmanagedType.U1)] public static extern bool GetPwrCapabilities(byte[] capabilities);
'@
$caps = New-Object byte[] 128
$read = $false
try { $read = [SoakPower.Caps]::GetPwrCapabilities($caps) } catch {}
$modern = if (-not $read) { $null } else { $caps[20] -ne 0 }
$s3 = if (-not $read) { $null } else { $caps[5] -ne 0 }
# A setting's value when plugged in: the last two values `powercfg /qh`
# prints for a setting are its AC and DC values; -1 when it says neither.
# /qh, not /query: /query leaves out hidden settings, the lock one among them.
function AC-Value([string]$Sub, [string]$Setting) {
    $hex = @(powercfg /qh SCHEME_CURRENT $Sub $Setting 2>$null | Select-String -AllMatches '0x[0-9a-fA-F]{8}' | ForEach-Object { $_.Matches.Value })
    if ($hex.Count -ge 2) { [Convert]::ToInt64($hex[-2], 16) } else { -1 }
}
function Minutes([long]$Seconds) { switch ($Seconds) { -1 { 'unknown' } 0 { 'never' } default { "after $([math]::Round($_ / 60, 1)) min" } } }
function YesNo($v) { if ($null -eq $v) { 'unknown' } elseif ($v) { 'yes' } else { 'no' } }
$screen_ac = AC-Value SUB_VIDEO VIDEOIDLE
$locked_ac = AC-Value SUB_VIDEO VIDEOCONLOCK
$sleep_ac = AC-Value SUB_SLEEP STANDBYIDLE
$lid_ac = switch (AC-Value SUB_BUTTONS LIDACTION) { 0 { 'nothing' } 1 { 'sleep' } 2 { 'hibernate' } 3 { 'shut down' } default { 'unknown' } }
$warned = $modern -and ($screen_ac -ne 0 -or $locked_ac -gt 0)
if ($warned) {
    Write-Host ''
    Write-Host 'This computer goes into standby when its screen turns off, and the soak cannot run then.' -ForegroundColor Yellow
    if ($screen_ac -ne 0) {
        Write-Host 'Settings > System > Power & battery > Screen, sleep & hibernate timeouts:' -ForegroundColor Yellow
        Write-Host "set 'When plugged in, turn off my screen after' to Never (it is now: $(Minutes $screen_ac))." -ForegroundColor Yellow
    }
    if ($locked_ac -gt 0) {
        Write-Host "While it is locked, its screen also turns off $(Minutes $locked_ac) (a setting Windows hides)." -ForegroundColor Yellow
        Write-Host 'To keep it on while plugged in, run these two lines in an administrator PowerShell:' -ForegroundColor Yellow
        Write-Host '    powercfg /setacvalueindex SCHEME_CURRENT SUB_VIDEO VIDEOCONLOCK 0' -ForegroundColor Yellow
        Write-Host '    powercfg /setactive SCHEME_CURRENT' -ForegroundColor Yellow
    }
}

# Tell the mailbox, so the people reading it know this computer is in.
$server = (Get-Content "$S\fleet.json" -Raw | ConvertFrom-Json).server
foreach ($l in Get-Content "$S\report.env") {
    if ($l -match '^\s*(JD_REPORT_[A-Z]+)=(.*)$') { Set-Item "env:$($Matches[1])" $Matches[2] }
}
$line = 'pc'
foreach ($l in Get-Content "$S\line.env" -ErrorAction SilentlyContinue) { if ($l -match '^\s*LINE=([a-z0-9-]+)') { $line = $Matches[1] } }
$hello = "$S\logs\installed.txt"
[IO.File]::WriteAllLines($hello, [string[]]@(
        "installed on $($env:COMPUTERNAME) at $((Get-Date).ToUniversalTime().ToString('s'))Z",
        "Windows $([Environment]::OSVersion.VersionString), $([math]::Round((Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory / 1GB)) GB memory, $(Get-CimInstance Win32_Processor | Select-Object -First 1 -ExpandProperty Name)",
        "C: $([math]::Round($c.SizeRemaining / 1GB)) GB free",
        "client: $(Get-Content "$S\bin\SOURCE" -ErrorAction SilentlyContinue | Select-Object -First 1)",
        "accounts in the pool: $((Get-Content "$S\accounts.txt" | Where-Object { $_.Trim() }).Count)",
        "modern standby: $(YesNo $modern), S3 sleep: $(YesNo $s3)",
        "plugged in: screen off $(Minutes $screen_ac), screen off while locked $(Minutes $locked_ac), sleep $(Minutes $sleep_ac), lid closed: $lid_ac"), $utf8)
# cmd does the redirection: PowerShell 5.1 turns a native program's stderr
# line, redirected by PowerShell itself, into an error record.
$said = cmd /d /c "`"$S\bin\jd-soak.exe`" send `"$hello`" --server $server --name `"$line-$($env:COMPUTERNAME.ToLower())-installed-$((Get-Date).ToUniversalTime().ToString('yyyyMMdd-HHmmss')).txt`" 2>&1"
Remove-Item env:JD_REPORT_PASSWORD, env:JD_REPORT_ACCOUNT -ErrorAction SilentlyContinue
if ($LASTEXITCODE -eq 0) { '   the mailbox has been told' }
else { Write-Host "   the mailbox could not be reached ($said); the soak runs anyway and tries again after each run" -ForegroundColor Yellow }

Write-Host ''
if ($warned) {
    # Said last as well, since it is the last thing read: the screen setting
    # above decides whether this computer soaks at all.
    Write-Host 'Installed, but it will not run while the screen setting above is unchanged.' -ForegroundColor Yellow
    Write-Host 'Change it as shown, and the soak starts by itself; it reports every result.'
} else {
    Write-Host 'Installed. Nothing else to do: it runs by itself and reports every result.' -ForegroundColor Green
    Write-Host 'It keeps the computer awake while a run is in progress (about 40 minutes in every 2 hours), and'
    Write-Host 'a run cut short by sleep or a restart is simply not counted.'
}
Write-Host 'To remove everything: double-click C:\soak-win\uninstall.cmd'
