# Link one soak device to the run's account, as the device's own Windows
# account, so its credential lands in that account's Credential Manager where
# its daemon looks for it (install.ps1 says why each device has an account).
#
# Runs as the task SoakWin-provision-<L>, started by reset.ps1, which leaves
# the account and its password in run\device-<L>\provision.env, readable by
# this account and the line's only, and reads the outcome from
# run\device-<L>\provision.exit and provision.log.
#
# Usage: provision.ps1 a|b
param([Parameter(Mandatory = $true)][string]$L)
$S = 'C:\soak-win'
$D = "$S\run\device-$L"
$log = "$D\provision.log"
$utf8 = New-Object Text.UTF8Encoding $false
$rc = 99
try {
    # jd-soak links every device in the fleet it is given, so it is given a
    # fleet of this device alone.
    $fleet = Get-Content "$S\fleet.json" -Raw | ConvertFrom-Json
    $fleet.devices = @($fleet.devices | Where-Object { $_.name -eq "device-$L" })
    [IO.File]::WriteAllText("$D\fleet.json", ($fleet | ConvertTo-Json -Depth 6), $utf8)
    $secret = "$D\provision.env"
    foreach ($line in Get-Content $secret) {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') { Set-Item "env:$($Matches[1])" $Matches[2] }
    }
    Remove-Item $secret -Force
    cmd /d /c "`"$S\bin\jd-soak.exe`" provision `"$D\fleet.json`" > `"$log`" 2>&1"
    $rc = $LASTEXITCODE
} catch {
    [IO.File]::AppendAllText($log, "provision.ps1: $_`n", $utf8)
} finally {
    Remove-Item "$D\provision.env" -Force -ErrorAction SilentlyContinue
    [IO.File]::WriteAllText("$D\provision.exit", "$rc")
}
