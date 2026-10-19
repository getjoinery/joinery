# Run the client's whole test suite once, on this computer's own disk: the
# kit's first job (make_pc_kit.sh), before any soak run.
#
# Every test program cargo built for the kit (tests\programs.txt), one after
# another, each with three hours to finish (the sweep took 46 minutes in the
# VM, under x64 emulation). A test program that hangs is ended and counted as
# failed, not waited on for ever.
#
# Runs as the task SoakWin-Tests, as the soak account soak-a (install.ps1): a
# test that recycles a file or stores a credential does it in that account's
# Recycle Bin and Credential Manager, never in the person's own.
#
# Writes tests\results\<program>.log, then tests\summary.txt, and removes
# tests\RUNNING last; forever.ps1 waits for that before its first run, and
# mails the summary with the logs of every program that failed.
$ErrorActionPreference = 'Continue'
$S = 'C:\soak-win'
$T = "$S\tests"
$R = "$T\results"
$utf8 = New-Object Text.UTF8Encoding $false
# Slept-Since and Stay-Awake: the suite keeps the computer awake while it
# runs, and a suite it slept through anyway says so (forever.ps1 runs it again).
. "$S\power.ps1"
$began = Get-Date
Stay-Awake $true
New-Item -ItemType Directory -Force $R | Out-Null
Get-ChildItem $R -File -ErrorAction SilentlyContinue | Remove-Item -Force

$passed = 0; $failed = 0; $ignored = 0
$failures = @()
$programs = @(Get-Content "$T\programs.txt" | Where-Object { $_.Trim() })
try {
    foreach ($p in $programs) {
        $log = "$R\$([IO.Path]::GetFileNameWithoutExtension($p)).log"
        $err = "$log.err"
        # A test's own temporary files go where this account's do.
        $proc = Start-Process -FilePath "$T\bin\$p" -WorkingDirectory $env:TEMP -NoNewWindow -PassThru `
            -RedirectStandardOutput $log -RedirectStandardError $err
        # Asked for now, or the exit code is never kept: PowerShell 5.1 gives
        # a process it started this way no exit code unless its handle was
        # read while it ran, and every program then looked like one that failed.
        $null = $proc.Handle
        if (-not $proc.WaitForExit(3 * 3600 * 1000)) {
            $proc.Kill()
            $failed++
            $failures += "$p (still running after three hours; ended)"
            continue
        }
        Add-Content -LiteralPath $log -Value (Get-Content -LiteralPath $err -ErrorAction SilentlyContinue)
        Remove-Item -LiteralPath $err -Force -ErrorAction SilentlyContinue
        $text = Get-Content -LiteralPath $log -ErrorAction SilentlyContinue
        $counted = $false
        foreach ($l in $text) {
            if ($l -match '^test result: \w+\. ([0-9]+) passed; ([0-9]+) failed; ([0-9]+) ignored') {
                $passed += [int]$Matches[1]; $failed += [int]$Matches[2]; $ignored += [int]$Matches[3]
                $counted = $true
            }
            if ($l -match '^test (.+) \.\.\. FAILED$') { $failures += "$p :: $($Matches[1])" }
        }
        # A program that ended without the line every test program prints
        # crashed, whatever its exit code says.
        if (-not $counted) { $failed++; $failures += "$p (exit $($proc.ExitCode), no result line)" }
        elseif ($proc.ExitCode -ne 0 -and -not ($failures | Where-Object { $_ -like "$p ::*" })) {
            $failed++; $failures += "$p (exit $($proc.ExitCode))"
        }
    }
} finally {
    $source = Get-Content "$S\bin\SOURCE" -ErrorAction SilentlyContinue | Select-Object -First 1
    $summary = @()
    $slept = Slept-Since $began
    if ($slept) { $summary += "INTERRUPTED: the computer slept during the suite ($slept), so its timings are not the client's" }
    $summary += @(
        "tests on $($env:COMPUTERNAME) ($([Environment]::OSVersion.VersionString)), client source: $source",
        "$($programs.Count) test programs: passed $passed, failed $failed, ignored $ignored",
        ''
    ) + $failures
    [IO.File]::WriteAllLines("$T\summary.txt", [string[]]$summary, $utf8)
    Remove-Item "$T\RUNNING" -Force -ErrorAction SilentlyContinue
    Stay-Awake $false
}
