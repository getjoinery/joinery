# One-time setup of the Windows soak line in C:\soak-win: its folders, one
# Windows account per device, and the scheduled tasks everything runs as.
# Safe to run again.
#
# Everything long-lived is a scheduled task because Windows OpenSSH ends every
# process a session started when the session closes.
#
# Each device runs as its own Windows account (soak-a, soak-b), as each ext4
# rig device runs as its own unix account. Each installation keeps its keys
# under a name of its own (com.joinery.drive.<16 hex digits>), so two devices
# under one account would not overwrite each other's link; the accounts are
# there so that each device has what a person's computer gives it: a
# Credential Manager of its own, where its daemon keeps its credential, and a
# Recycle Bin of its own, as two computers do. The device accounts are
# administrators: an administrator's elevated processes can be ended, frozen
# and measured by the fault agent's account, which runs elevated as this one.
#
# This account's tasks log on S4U, with no password. A device account's tasks
# log on with a password: registering an S4U task for another account is
# refused ("Access is denied"), even elevated. The password is made here, set
# on the account, handed to the Task Scheduler and written nowhere; running
# this again makes a new one.
#
# On a person's own computer (-PersonalComputer, from the kit's
# install_pc.ps1) three things differ. Automatic updates stay on: it is their
# computer, and a run an update restarts is ledgered INTERRUPTED. There is no
# build task: the kit brings its programs built. And the test suite gets its
# task, as soak-a (run_tests.ps1 says why that account). The two device
# accounts are also kept off the sign-in screen, where nobody is meant to
# choose them.
#
# Run elevated, from the line's folder:  powershell -File C:\soak-win\install.ps1
param([switch]$PersonalComputer)
$ErrorActionPreference = 'Stop'
$S = 'C:\soak-win'
$Devices = 'a', 'b'
foreach ($d in 'bin', 'run', 'logs', 'evidence') { New-Item -ItemType Directory -Force "$S\$d" | Out-Null }

function Register-Line([string]$Name, [string]$Script, [string]$Arguments = '', [string]$User = '',
    [string]$Password = '', [int]$Priority = 4, [switch]$AtStartup) {
    $action = New-ScheduledTaskAction -Execute powershell.exe `
        -Argument "-ExecutionPolicy Bypass -NoProfile -File $S\$Script $Arguments" -WorkingDirectory $S
    # No time limit (the default ends a task after 72 hours), one instance at
    # a time, and normal priority: a task otherwise runs below normal, and a
    # daemon a person starts at login does not.
    $settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew `
        -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -Priority $Priority
    $task = @{ TaskName = $Name; Action = $action; Settings = $settings }
    if ($User) {
        $task.User = $User; $task.Password = $Password; $task.RunLevel = 'Highest'
    } else {
        $task.Principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType S4U -RunLevel Highest
    }
    if ($AtStartup) { $task.Trigger = New-ScheduledTaskTrigger -AtStartup }
    Register-ScheduledTask @task -Force | Out-Null
    "registered $Name ($(if ($User) { $User } else { $env:USERNAME }))"
}

# The loop starts again after the VM restarts; reset.ps1 then starts the
# devices. A run the restart cut short leaves its evidence to the next reset.
Register-Line SoakWin-Forever forever.ps1 -AtStartup
foreach ($d in $Devices) {
    $name = "soak-$d"
    $bytes = New-Object byte[] 24
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $plain = [Convert]::ToBase64String($bytes) + 'aA1!'
    $secure = ConvertTo-SecureString $plain -AsPlainText -Force
    if (Get-LocalUser $name -ErrorAction SilentlyContinue) {
        Set-LocalUser $name -Password $secure
    } else {
        New-LocalUser $name -Password $secure -PasswordNeverExpires -AccountNeverExpires `
            -Description "Joinery Drive soak device $d" | Out-Null
        "created account $name"
    }
    # Added and told "already", rather than looked up first: listing the
    # group throws on a member whose account is gone (a computer once in a
    # domain or Azure AD keeps such entries), and the install would stop there.
    try { Add-LocalGroupMember Administrators $name -ErrorAction Stop }
    catch { if ($_.FullyQualifiedErrorId -notlike 'MemberExists*') { throw } }
    Register-Line "SoakWin-device-$d" device.ps1 $d -User $name -Password $plain
    # Linking runs as the device's account too, so the credential lands in
    # that account's Credential Manager, where its daemon will look.
    Register-Line "SoakWin-provision-$d" provision.ps1 $d -User $name -Password $plain
    if ($PersonalComputer -and $d -eq 'a') {
        Register-Line SoakWin-Tests run_tests.ps1 -User $name -Password $plain
        New-Item -ItemType Directory -Force "$S\tests" | Out-Null
        icacls "$S\tests" /grant "$($name):(OI)(CI)M" | Out-Null
    }
    if ($PersonalComputer) {
        $hide = 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\SpecialAccounts\UserList'
        # Made only when missing: New-Item -Force over a key that exists
        # empties it, and this one may hold another program's entries.
        if (-not (Test-Path $hide)) { New-Item $hide -Force | Out-Null }
        Set-ItemProperty $hide $name 0 -Type DWord
    }
    $plain = $null
}
if ($PersonalComputer) {
    'automatic updates left as they are; no build task'
    exit 0
}
# Below normal: a build should not slow a run in flight.
Register-Line SoakWin-Build build.ps1 -Priority 7

# No automatic updates. The VM's disk is a file on the Mac mini's, which has
# little room, and an update's restart ends the run in flight. Undo with:
#   Remove-ItemProperty HKLM:\SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate\AU NoAutoUpdate
$au = 'HKLM:\SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate\AU'
New-Item -Force $au | Out-Null
Set-ItemProperty $au NoAutoUpdate 1 -Type DWord
'automatic updates off'
