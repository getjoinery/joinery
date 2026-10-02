# The soak rig

The real `joinery-drive` daemon, unmodified, on real disks, against a real
Joinery instance, driven by application write patterns that break sync clients,
with real faults injected on a schedule, for weeks. Specified in
`public_html/specs/drive_sync_soak.md`; the code is `../jd-soak/`.

`jd-sim` proves the engine's logic. This proves the program.

## What is here

| | |
|---|---|
| `setup-host.sh` | Creates the accounts, directories, fleet description and units. Run once, as root. |
| `soak-device@.service` | Written by `setup-host.sh`. One instance per device, `Restart=always`. |
| `jd-soak.service` | Written by `setup-host.sh`. The campaign. |
| `soak_forever.sh` | Runs campaigns back to back, each on a fresh account, and writes one line per run to `/soak/ledger.txt`. Copied to `/root/` on the box. |
| `soak_reset.sh` | Archives the last run's evidence to `/root/soak-evidence/`, wipes the devices and provisions the next `soak-rig-N` account. Called by `soak_forever.sh`. |
| `soak_clear_partitions.sh` | Lifts any partition a killed campaign left in iptables. Called by `soak_forever.sh`. |
| `soak_account_setup.php` | Creates a soak account on the soak instance through the model classes. Run inside the instance's container by `soak_reset.sh`. |
| `purge_soak.php` | Purges the Drive data of retired soak accounts so the instance's volume does not fill, keeping the newest accounts still holding files in each pool (`soak-rig-*`, `soak-mac-*`). Run inside the container by `soak_forever.sh`. |
| `soak_mac_pool.sh` | Run from the dev box: makes the next `soak-mac-N` accounts on the rig for the Mac mini's campaign, adds them to the mini's `~/soak-mac/accounts.txt`, and installs `purge_soak.php`. |
| `soak_clock.py` | Reads the ledger and reports how far the current client build is along the release bar. |

## Shape

```
soak host
  ├── /soak/device-a/root     a real ext4 directory — the sync root
  ├── /soak/device-a/home     config, state store, spool, logs
  ├── /soak/device-b/…
  ├── /soak/journal           the three journals + report.txt
  └── /soak/bundles           a frozen world per violation
soak instance (separate box)  a standard container install, never dev
```

One daemon per device, each running as its **own unix account**. That is what
makes a per-device network fault possible: `iptables -m owner --uid-owner
soak-a` cuts exactly one daemon's traffic to the server and leaves the other
syncing. Devices are host processes rather than containers because the daemon
binds its control channel to loopback — correctly, since the alternative puts a
client's sync controls on the network — and a daemon inside a container is
therefore unreachable by the verifier that has to ask it when it stopped working.

The actors, the fault agent and the verifier all run as root on the same host.
The actors write into the sync roots exactly as a person at a keyboard would.
Nothing in the rig ever opens a device's state store.

## Standing it up

```bash
# On the soak VPS, as root, with joinery-drive and jd-soak in /usr/local/bin:
./setup-host.sh --server https://drivetest.getjoinery.com --devices 2

# Put the soak account credentials in /etc/jd-soak.env, then:
set -a; . /etc/jd-soak.env; set +a
jd-soak provision /soak/fleet.json

systemctl start soak-device@a soak-device@b
systemctl start jd-soak
```

The soak account is an ordinary account on the soak instance. It needs Drive
enabled and nothing else. Give it its own account, not yours: the rig will
create, rename, trash and restore tens of thousands of files under it.

## Watching it

```bash
cat /soak/journal/report.txt        # the rolling report
jd-soak report /soak/fleet.json     # the same thing, rebuilt on demand
ls /soak/bundles                    # one directory per violation, or empty
```

The only number that matters is the first line: **INVARIANT VIOLATIONS**, which
must read 0. Everything under it says whether the run was worth anything — how
much the actors did, how many faults actually landed, and what convergence
looked like at the tail rather than on average.

If the report says *no fault was injected in this window*, stop and fix that
before believing anything else it says. A green run with no adversary in it
proves nothing.

### The ledger and the release bar

`soak_forever.sh` appends one line per run to `/soak/ledger.txt`: the account,
the client and its seed, the violation count, stuck ops, daemon CPU, when the
run ended, its actor ops and daemon kills, and the verdict (`no-loss green`, or
the first `FAIL` line).

```bash
python3 /root/soak_clock.py         # the release-bar stretch for the newest build
```

The release bar (spec `drive_sync_soak.md`, S8) is one unbroken stretch of
clean runs on one client build: 7 days, at least 1M actor ops and 100 daemon
kills, zero violations. The clock counts from the build's first run, or from
the last run that was not clean, whichever is later, so deploying a new client
starts it again. A smoke failure or a run that checked nothing breaks the
stretch like any failure. The bar's other terms (three filesystem
personalities, every persona and drill, every deadline miss diagnosed) are not
in the ledger, and the clock prints them as open rather than counting them.

## Doing one thing by hand

When something is wrong and a whole campaign is the wrong instrument:

```bash
jd-soak actor  /soak/fleet.json --device device-a --persona office --seconds 120
jd-soak chaos  /soak/fleet.json --device device-a --fault partition --seconds 60
jd-soak verify /soak/fleet.json     # one settle, six assertions, no storm
```

`verify` exits non-zero when an invariant is broken, so it can be used as a gate
on its own.

## When something breaks

The campaign freezes the world and writes a bundle to `/soak/bundles/`:

```
violation-cycle-41-1754…/
  verdicts.txt      what failed, and which file
  timeline.txt      every actor op and fault on one line of time
  journal/          all three journals, whole
  device-a/         state.db, config.json, daemon.log, tree.txt
  fleet.json
```

`timeline.txt` is the point. Faults are marked `!!!!!!` so the one that was in
flight when a file was last seen can be found by eye. That correlation is what
replaces seed replay, and the next step after reading it is always the same:
**encode it as a frozen `jd-sim` scenario**, so the bug becomes a fast
deterministic regression instead of something that might happen again in a
fortnight.

## Not yet here

Phase A. Deliberately absent, and landing in Phase B: the loopback filesystem
images (ext4-casefold and vfat personalities, and a volume that can really be
yanked), disk-pressure faults, the state-store abuse drills, feed-reset and
upload-token-sweep acceleration, and the remaining nine personas. Phase C adds
the mac mini as a fourth device on real APFS and turns on the encrypted lane.
