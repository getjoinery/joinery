# The Windows soak line

The soak rig (`../README.md`) on real NTFS: the shipping `joinery-drive`
daemon and the `jd-soak` orchestrator, both built natively for Windows 11
ARM64, running two devices in the Windows VM on the Mac mini against the soak
rig's instance (`https://drivetest.getjoinery.com`). It is the third line
beside the ext4 rig on 45.33.72.32 and the Mac mini's APFS and FAT lines, and
it runs the Mac lines' campaign shape: one fresh account per run, 2 cycles of
120 s storms, a 900 s settle deadline, a fault every 60 s on average, a 4 s
actor pace, at most one run every two hours, seeds from 29001.

## What is here

| | |
|---|---|
| `install.ps1` | One-time setup in the VM: `C:\soak-win\`, the device accounts `soak-a` and `soak-b`, and the scheduled tasks the line runs as. Turns off automatic Windows updates. |
| `power.ps1` | Whether the computer slept during a run, and keeping it awake while one runs; dot-sourced by `forever.ps1` and `run_tests.ps1`. |
| `forever.ps1` | Runs campaigns back to back (task `SoakWin-Forever`, which also starts with the VM) and writes one line per run to `C:\soak-win\ledger.txt`. |
| `reset.ps1` | Stops the devices, archives the last run to `C:\soak-win\evidence\`, wipes, provisions the next account and starts the devices. Called by `forever.ps1`. |
| `device.ps1` | One device's supervisor (tasks `SoakWin-device-a`, `SoakWin-device-b`, as `soak-a` and `soak-b`): runs its daemon and starts it again whenever it exits. |
| `provision.ps1` | Links one device to the run's account as that device's own account (tasks `SoakWin-provision-a`, `-b`). Called by `reset.ps1`. |
| `build.ps1` | Builds the client and the orchestrator in `C:\src\sync`, runs jd-soak's tests, and stages both binaries for the next run (task `SoakWin-Build`). |
| `fleet.json` | The fleet: two devices, each with its own copy of the program. |
| `push.sh` | From the dev box: copies these scripts into the VM, sends it a source tree (the repo's HEAD by default) and starts a build. |
| `soak_win_pool.sh` | From the dev box: makes the next `soak-mac-win-N` accounts on the rig, adds them to the VM's `accounts.txt`, and writes the VM's `soak.env` if it has none. |
| `make_pc_kit.sh` | From the dev box: makes the kit that runs this line on a person's own x64 Windows computer (see below). |
| `kit_build.ps1` | Run in the VM by `make_pc_kit.sh`: builds the kit's x64 programs and test programs. |
| `install.cmd`, `install_pc.ps1` | The kit's installer: checks the computer, copies the kit to `C:\soak-win`, runs `install.ps1 -PersonalComputer` and starts it. |
| `run_tests.ps1` | The kit's first job: every test program, once (task `SoakWin-Tests`, as `soak-a`). |
| `uninstall.cmd`, `uninstall.ps1` | Takes the kit off the computer again. |
| `read_mailbox.sh` | From the dev box: fetches what the kit's computer has sent. |
| `kit_readme.txt` | The kit's `README.txt`, for the person installing it. |

## Shape

```
Windows VM (UTM on the Mac mini; ssh -J macmini joinery@<utmctl ip-address Windows>)
  C:\soak-win\
    bin\joinery-drive.exe, jd-soak.exe    the build's output, md5 in each ledger line
    bin\device-a\joinery-drive.exe        device-a's own copy of the program
    bin\device-b\joinery-drive.exe
    run\device-a\root                     the sync root, on NTFS
    run\device-a\home                     JOINERY_DRIVE_HOME: config, state, spool, logs
    run\journal, run\bundles              the journals and any frozen world
    run\runN.log, logs\runN.log           each run's orchestrator output
    evidence\runN-<time>\                 the newest 20 archived runs
    accounts.txt, next_account            the pool, and the next one to use
    soak.env                              JD_SOAK_PASSWORD, readable by the VM's account only
    ledger.txt, forever.log, reset.log, build.log
```

Every long-lived process is a scheduled task, because Windows OpenSSH ends
every process a session started when the session closes. The VM account's
tasks log on S4U, with no password; the device accounts' tasks log on with a
random password that `install.ps1` sets and hands to the Task Scheduler and
writes nowhere (registering an S4U task for another account is refused with
"Access is denied", even elevated).

Each device runs as its own Windows account, `soak-a` and `soak-b`, as each
ext4 rig device runs as its own unix account, and is linked as that account
(`provision.ps1`). Its daemon keeps its credential in its account's
Credential Manager, the custody a person's computer gives it, and recycles
into its account's own Recycle Bin. Each installation keeps its keys under a
name of its own (`com.joinery.drive.<16 hex digits>`), so the accounts are not
what keeps the two links apart: they give each device that custody and that
Recycle Bin to itself, as two computers have. The loop, the actors, the
fault agent and the verifier run as the VM's own account, elevated; the device
accounts are administrators, so their daemons can be ended, frozen and
measured from it.

Each device runs its own copy of the program. Windows will not show one
process another's environment, so the home a daemon was started with cannot
tell two devices apart there (on Linux and macOS it does), and the Windows
firewall cuts a program rather than an account. The fleet names each copy
(`"program"`), and the fault agent finds, kills, freezes and partitions a
device by it:

| Fault | On this line |
|---|---|
| kill | `TerminateProcess` on the device's daemon; its task's loop starts it again |
| freeze | every thread of the daemon suspended, then resumed |
| partition | a firewall rule blocking the device's program from the server's IPv4 and IPv6 addresses, removed afterwards |
| restart | the device's task ended and run again |

The verifier finds a recycled file in the Recycle Bin at the root of the sync
root's volume, in whichever account's folder of it. `reset.ps1` lists what
the devices recycled into the run's archive (`recycled.txt`) and then removes
those items, and only those: the bin stays bounded and the next run's
verifier never meets the last run's contents in it.

The VM's disk is a file on the Mac mini's disk, which has little room, so the
build reuses `C:\src\sync\target`, the line keeps 20 archives, and automatic
updates are off (an update's restart would also end the run in flight).

## Standing it up

```bash
# From the dev box, with the VM running:
bash sync/soak/windows/push.sh --scripts-only
ssh -J macmini joinery@<ip> 'powershell -File C:\soak-win\install.ps1'
bash sync/soak/windows/soak_win_pool.sh 30     # accounts + soak.env
bash sync/soak/windows/push.sh                 # build HEAD; wait for "=== build exit 0" in build.log
ssh -J macmini joinery@<ip> 'Start-ScheduledTask SoakWin-Forever'
```

`build.ps1` holds `C:\sr-lock-<who>.txt` while it builds in `C:\src\sync`
and waits while anyone else holds one.

## Watching it

```bash
ip=$(ssh macmini /Applications/UTM.app/Contents/MacOS/utmctl ip-address Windows | head -1)
ssh -J macmini joinery@$ip 'Get-Content C:\soak-win\ledger.txt -Tail 10'
ssh -J macmini joinery@$ip 'Get-Content C:\soak-win\run\journal\report.txt'
```

The ledger has the Mac line's format, one line per run:

```
<ended> | run N | <account> | client <md5> | seed S | violations V | ops O | kills K | faults F refused R | green
```

`green` needs at least one assertion to have passed; a run that checked
nothing says `NO VERDICT`, and a broken invariant puts the first `FAIL` line
from its bundle there instead. `RESET FAILED` and `SMOKE FAILED` mean the run
never started (see `reset.log`, `forever.log`).

## Changing it

- **A new client:** `bash push.sh` (HEAD) or `bash push.sh DIR`. The build stages
  `bin\*.new`; the next reset moves them into place and copies the program to
  each device, so a run in flight is never touched.
- **Stop:** `New-Item C:\soak-win\STOP` ends the loop after the run in flight
  (or at once, between runs). Remove the file and start `SoakWin-Forever` to
  resume.
- **More accounts:** `bash soak_win_pool.sh [count]`. The line stops with
  `pool used up` in the ledger when it runs out.

## On a person's own computer

A computer nobody can reach runs the same line from a kit. `make_pc_kit.sh`
makes it: the x64 client and orchestrator and every test program, built in
the VM; this folder's scripts; a pool of `soak-rig-pc-N` accounts (240 by
default, twenty days at a run every two hours); and two passwords, the soak
accounts' and the mailbox's. The person unzips it and double-clicks
`install.cmd`. `install_pc.ps1` changes nothing unless the computer has an
x64 processor, an NTFS `C:` with 15 GB free and no line installed; then it
copies the kit to `C:\soak-win`, keeps the passwords readable by that
account only and removes them from the kit's folder, and runs `install.ps1
-PersonalComputer`. That leaves automatic updates alone, registers no build
task, hides `soak-a` and `soak-b` from the sign-in screen, and registers
`SoakWin-Tests`.

The test suite runs first, once, as `soak-a`, so that what a test recycles or
stores goes into that account's Recycle Bin and Credential Manager. The two
tests that start the client find it at the path it was compiled at, so the
kit's test programs are compiled at `C:\soak-win\tests\target` in the VM and
the client is installed there. `forever.ps1` waits for the suite, and then
runs the line with `line.env`'s seeds (39001 on).

Nobody can read that computer's ledger, so it sends it. With a `report.env`,
`forever.ps1` sends each ledger line, with the run's report, to the mailbox
(`soak-mailbox@getjoinery.com` on the soak instance, through `jd-soak send`),
and the evidence of every run that is not green. `install_pc.ps1` says when
it is in, and `uninstall.ps1` sends the whole ledger before it removes
everything. Read it from the dev box:

```bash
bash sync/soak/windows/read_mailbox.sh       # into ~/drive-work/soak-mailbox
```

A person's computer sleeps and restarts. A run in flight keeps it awake (a
request held by the loop, which the computer's settings never see; `power.ps1`),
and a run it went to sleep in anyway is ledgered `INTERRUPTED`, as is one it
restarted in (`run\IN-FLIGHT`, read when the loop starts again). Neither
counts, but what one found is still ledgered and its evidence still sent: a
sleep breaks no invariant. The test suite is kept awake the same way, and one
the computer slept through or restarted in is run again, three times at most.
A message that does not reach the mailbox leaves `logs\RESEND-LEDGER`, and the
next one that does takes the whole ledger with it; the suite's summary is sent
again at every turn until it goes.

`C:\soak-win` is writable by SYSTEM and Administrators only: the tasks run its
scripts elevated.
