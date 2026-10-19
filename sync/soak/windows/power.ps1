# The computer's sleep, as the line sees it: dot-sourced by forever.ps1 and
# run_tests.ps1. A run, or the test suite, that the computer slept through is
# not a measurement -- its deadlines ran while nothing could -- so each asks
# whether it did, and keeps the computer awake while it runs.

# How the computer went to sleep, or hibernated, since $Since -- the event's
# id and time as a few words, for the ledger -- or nothing when it did not.
# The moment it went is recorded as it goes (Kernel-Power 42 for sleep and
# hibernation, 506 for a laptop's modern standby). Waking is not asked about:
# its records are written seconds after the wake, so a run started straight
# after one would find it inside itself. Said in full so that a laptop that
# records 506 while it is held awake shows as that in the first line it
# ledgers, and not as a run of unexplained interruptions.
function Slept-Since([datetime]$Since) {
    $went = Get-WinEvent -FilterHashtable @{ LogName = 'System'; StartTime = $Since;
        ProviderName = 'Microsoft-Windows-Kernel-Power'; Id = 42, 506 } -MaxEvents 1 -ErrorAction SilentlyContinue
    if ($went) { "Kernel-Power $($went.Id) at $($went.TimeCreated.ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ'))" }
}

# Keep the computer awake, and let it sleep again. The request is this
# process's, the computer's own settings are not touched, and it goes with
# the process. The flags are a signed int here because PowerShell 5.1 reads
# 0x80000001 as one, and refuses to pass it as an unsigned one.
Add-Type -Namespace SoakPower -Name Awake -MemberDefinition @'
[DllImport("kernel32.dll")] public static extern uint SetThreadExecutionState(int flags);
'@
function Stay-Awake([bool]$On) {
    # ES_CONTINUOUS | ES_SYSTEM_REQUIRED, or ES_CONTINUOUS alone to let go.
    [void][SoakPower.Awake]::SetThreadExecutionState($(if ($On) { 0x80000001 } else { 0x80000000 }))
}
