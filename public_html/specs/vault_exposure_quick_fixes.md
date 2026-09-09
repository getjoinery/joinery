# Vault exposure: quick fixes

**Status:** Built 2026-09-09, uncommitted. Q1–Q4 and Q6 are complete and
tested on dev. Q5 is applied on both fleet hosts that needed it (see Q5 § Rollout);
a reboot proof and dev's own conversion remain, and the swap telemetry
reaches the fleet with the next agent release (the change sits in the
unreleased 1.23.0). Split out of
`vault_key_memory_exposure.md` on 2026-09-07.
Everything here is a contained code or installer change with no design
decision attached. The parent spec keeps the structural questions (unseal
daemon, package signing, read-only webroot).

**Declined, kept for reference:** none of these checks blocks raising a
mailbox to Private or Fortress. The owner declined any such gate on
2026-09-07. `VaultHealth` stays advisory; the fixes below make its answers
true and its remediation text actionable, and stop there.

## Q1 — the search index working copy is world-readable (was B1)

`MailboxIndex` creates `/dev/shm/mailfts_{user_id}.sqlite` with the web
worker's default umask, so it lands 0644 in a 1777 tmpfs and every local
account on a bare-metal host can read the owner's whole vocabulary for the
life of the window. (Inside a container `/dev/shm` is container-private, so
this bites bare-metal and self-hosted boxes.)

**This is a chmod, not a placement decision.** The deferred-work drain that
folds on a timer runs in the web tier on the vault heartbeat
(`VaultDeferredWork`, registered in `plugins/mailbox/includes/bootstrap.php`),
and `VaultUnlock::secretKey()` returns null to any process without a session,
so a CLI process can never fold a live user's index. The only CLI folder is
the test suite, on its own fixture users. The fold lock's 0666 exists for
those tests and stays.

Three creation points, all in `plugins/mailbox/includes/MailboxIndex.php`:

1. `rebuild()` — `new SQLite3($path)` on a fresh path.
2. `restoreFromBlob()` — `VaultCrypto::openFieldFile()` writes the restored
   copy via `SealedBox::openStreamFile()`, which writes a sibling temp file
   (`xb`) and renames it into place.
3. `tryOpenDb()` opens an existing file read-write and creates nothing.

**Order matters.** SQLite gives its `-journal` and `-wal` files the mode of
the main database file, so the mode must be 0600 before the first write:

- In `rebuild()`, pre-create the file (`fopen($path, 'x')`, close, `chmod
  0600`) and only then hand the path to `SQLite3`.
- In `restoreFromBlob()` the plaintext is world-readable while it is being
  written, not only after: `SealedBox::openStreamFile()` creates its temp
  file under the umask and streams the decrypted index into it for as long
  as the decrypt takes. The temp file is made 0600 before the first byte
  (the rename carries the mode to the final path), and `restoreFromBlob()`
  asserts 0600 on the result before `tryOpenDb()`.

`fs.protected_regular=2` (Ubuntu default) already refuses the web worker an
`O_CREAT` open on a file another local account pre-created there, so a
squatter cannot get www-data to write into a file they own. What a squatter
can do is stop folds for a user id by holding the lock or planting the path —
a denial of service, not a read. Noted, not fixed here.

**Test:** `plugins/mailbox/tests/mailbox_index_working_copy_mode_test.php`
(db tier, dev-only, like its sibling index suites) creates a working copy
through both `rebuild()` and `restoreFromBlob()` and pins
`fileperms() & 0777 === 0600` on each. It fails if either path regresses.
Without the fix SQLite creates the file 0644 — its own default, the umask
only ever narrows it — so the pin is against 0644, not 0666.

## Q2 — the core-dump check reports "verified" when cores are still kept

`VaultHealth::checkCoredumpsDisabled()` reads `ulimit -c` and passes on 0.
That is the right check only when `kernel.core_pattern` names a file. Ubuntu
sets it to a pipe (`|/usr/share/apport/apport ...`), and the kernel ignores
`RLIMIT_CORE` for pipe patterns. apport then reads the whole core from stdin
into the `/var/crash` report; the rlimit governs only the plain `core` file
it additionally writes. Dev has apport enabled and active, so a php-fpm crash
with a window open lands the key on disk today, and the check says all clear.

Fix, in `VaultHealth`:

- Read `/proc/sys/kernel/core_pattern`. Not a pipe: the rlimit check stands.
- Pipe to apport: `unmet` unless `/etc/default/apport` has `enabled=0` or the
  `apport` unit is inactive. Remediation text: `sudo systemctl disable --now
  apport` and set `enabled=0`.
- Pipe to `systemd-coredump`: it honours the rlimit, so the rlimit check
  stands.
- Any other pipe: `unknown`, naming the handler.

Inside a container `core_pattern` is the host's (it is not namespaced), so
the check reads the right thing there too; the remediation runs on the host.

`tests/vault/vault_health_test.php` gains cases for each branch by injecting
the pattern string (the reader takes a path so tests can point it at a
fixture).

## Q3 — one more health check: exception arguments stay out of the log

`zend.exception_ignore_args=Off` makes every logged uncaught exception carry
the leading bytes of its string arguments, and the secret key is a string
argument on the three `VaultCrypto` open methods. Dev has it `On` and
`zend.exception_string_param_max_len=0` (the distro production defaults), but
nothing in the installer asserts it. Add `VaultHealth::checkExceptionArgs()`:
`verified` when `ini_get('zend.exception_ignore_args')` is truthy, `unmet`
otherwise, with the ini line as the remediation.

## Q4 — the swap check cannot say "verified" for encrypted swap, and should accept zram

`checkSwapSafe()` treats any active swap device not under `/dev/mapper/` as
unmet, and anything under it as "encrypted-looking" — returning `unknown`
with the reason that encryption could not be confirmed from PHP. Two
consequences: after Q5 converts a node, its swap check never resolves; and
an LVM swap volume, which also lives under `/dev/mapper/`, is called
encrypted-looking when it is plain.

The kernel answers the question. Every device-mapper device exposes its type
in `/sys/block/dm-N/dm/uuid`: the string begins `CRYPT-` for dm-crypt and
`LVM-` for a logical volume. Fix:

- For each device in `/proc/swaps`, `realpath()` it. A path resolving to
  `/dev/dm-N`: read `/sys/block/dm-N/dm/uuid`. Prefix `CRYPT-` is
  `verified`; any other prefix is `unmet`, naming the type; unreadable is
  `unknown`.
- `/dev/zram*` is `verified`. zram is compressed RAM and never reaches disk
  unless its writeback feature is enabled, which no distro does by default.
- Anything else stays `unmet` as today.

The check passes only when every active device is verified. The reader takes
a sysfs root so `tests/vault/vault_health_test.php` can point it at a
fixture tree and cover each branch: dm-crypt, LVM, zram, a plain partition,
and a swapless box.

## Q5 — the installer creates the unencrypted swap it warns about, at a size nobody derived

`host_housekeeping` in `install.sh` (maintenance_scripts/install_tools/, the
`# --- Swap: at least 2G ---` step; it was `host-harden`'s until 2026-09-07,
when that subcommand was removed and its housekeeping moved into every docker
and server install) creates a 2 GB plain `/swapfile` on any box with less
than 2 GB of swap. Every provisioned node runs it at install, so the fleet's
swap is unencrypted by construction. A box installed before the step existed
keeps whatever the image shipped — Linode's default is a 512 MB plain swap
disk, which is what dev's `/dev/sdb` is.

### The size

The step's own comment is the only justification for 2 GB: check_mail was
OOM-killed on a 2 GB box with no swap. That incident (2026-08-22, shipped in
0.8.320) was a bug, not a workload — a zero sync cursor made Horde fetch a
1.25 million message mailbox into memory — and the fix removed the demand.
2 GB was a round number picked in reaction to it. Nothing measured it then and
nothing measures it now: `check_status` parses the `Mem:` line of `free -m`
and skips the `Swap:` line directly under it, so no node reports swap use.

Two facts the number ignores:

- Customer instances are always the 1 GB nanode (owner, 2026-09-08). 2 GB of
  swap on a 1 GB box is twice RAM — the shape where a box thrashes for
  minutes instead of the OOM killer resetting one worker, which on a web box
  is the worse outcome.
- A container installed with a memory budget gets `--memory-swap` equal to
  `--memory`, so it cannot touch host swap at all. On a Docker host the
  swapfile serves only the host's own processes.

What the fleet shows today (status data, 2026-09-09; the 4 GB host's swap
figure is from a hand check on 2026-09-01):

| Box | RAM | Used | Swap |
|---|---|---|---|
| jeremytunnell.com, bare metal | 2 GB | 975 MB | not recorded |
| 4 GB Docker host, 8 containers | 4 GB | ~1.4 GB | 183 MB of 2 GB used |
| dev | 8 GB | 3.5 GB | 495 MB fully used, 4.4 GB available — cold pages, not pressure |
| DNS boxes | 1 GB | 500–600 MB | not recorded |

**Rule (owner, 2026-09-09): a flat 1 GB on every box.** An earlier draft
sized swap to RAM with a 1 GB floor; that gave dev 8 GB and the Docker host
4 GB, against a fleet whose peak observed use is under half a gigabyte. A
size that scales with RAM is complexity with nothing behind it. The step
creates 1 GB, and skips only when the encrypted mapping is already active
at that size; anything else, larger included, is replaced.

**Telemetry, in the same change:** `JobResultProcessor::parse_check_status_ssh_output()`
gains `swap_total_mb` and `swap_used_mb` from the `Swap:` line it already
receives; `NodeHealthProbe` carries the two keys; the node overview tab shows
swap used beside memory used. Once the fleet has reported for a few weeks the
rule above is revisited against numbers instead of a guess.
`plugins/server_manager/tests/job_result_processor_test.php` pins the parse,
including the `Swap: 0 0 0` line a swapless box prints.

### Encryption

Swap stays on: the fix for the incident above removed one demand, not the
class, and a 1 GB box needs somewhere to put idle php-fpm workers and cold
Postgres pages. Encrypt it with a throwaway key generated at each boot,
the standard Debian pattern, no key to manage:

```
# /etc/crypttab
cryptswap /swapfile /dev/urandom swap,cipher=aes-xts-plain64,size=256,nofail
# /etc/fstab
/dev/mapper/cryptswap none swap sw,nofail 0 0
```

`nofail` on both lines is what makes the rollout hazard below survivable: a
box whose swap fails to come up boots without it and the Q4 check reports
the gap, instead of the box hanging in the emergency shell.

`systemd-cryptsetup` accepts a regular file as the source and sets up the
loop device itself (the `cryptsetup` package is installed by the step). The
step becomes: `swapoff -a`; stop any stale `cryptswap` mapping; create
`/swapfile` at the computed size; write the crypttab line; replace the fstab
swap line with the mapper path; `systemctl daemon-reload`;
`systemctl start systemd-cryptsetup@cryptswap`; `swapon /dev/mapper/cryptswap`;
verify with `swapon --show` that the only active device is the mapping, and
warn (not abort) otherwise. The check in Q4 then passes on its own. A box
re-running the install sees the mapping active at a size at least the
computed one and skips, as it skips the plain swapfile today.

In the same step: `sed -i 's/^enabled=1/enabled=0/' /etc/default/apport` and
`systemctl disable --now apport`, so Q2 passes on an installed box.

**Rollout hazard.** A wrong fstab line is a box that does not come back from
a reboot. `nofail` on both lines is the answer: the worst case is a box that
boots without swap and a Q4 check that says so.

**Rollout, done 2026-09-09.** The agent has no primitive that runs a host
script, so "through the agent" was never a path; the step is
`maintenance_scripts/sysadmin_tools/joinery_swap_step.sh` (the installer's
block wrapped with a fstab/crypttab backup and a verification tail), run by
hand over the owner's existing access. Only two fleet hosts needed it — the
DNS boxes and the relay are disposable — and both are converted live,
without a reboot, swap use having first been swapped back into RAM:

| Host | RAM | Swap before | Swap after |
|---|---|---|---|
| Docker host, eight container sites | 4 GB | 2 GB plain `/swapfile` | 1 GB dm-crypt |
| jeremytunnell.com, bare metal | 2 GB | 496 MB plain partition | 1 GB dm-crypt |

On both, `/sys/block/dm-0/dm/uuid` reads `CRYPT-PLAIN-cryptswap`, apport is
inactive with `enabled=0`, and `kernel.core_pattern` fell back to `core`.
The boot chain was read rather than rebooted for: sysinit wants
`cryptsetup.target`, `systemd-cryptsetup@cryptswap.service` is required by
`dev-mapper-cryptswap.device`, and `dev-mapper-cryptswap.swap` requires that
device. A reboot of either host under a maintenance window is the remaining
proof. The proof box named in the earlier draft no longer exists. Dev is
not converted (no passwordless sudo from the working session); the same
script converts it, replacing its 496 MB plain disk with 1 GB encrypted.

## Q6 — two facts the docs should state

`docs/sealed_vault.md` § host hardening gains two sentences:

- APCu never zeroes a freed slot. Closing a window deletes the entry, but the
  key bytes remain in the segment until the allocator reuses them, so the
  anonymous-memory, no-core, encrypted-swap trio guards residue after close as
  much as the live window. `sodium_memzero` reaches the PHP copy only.
- The APCu segment belongs to the php-fpm master and is shared by every pool
  and every site that master serves. Code on any site served by the same
  php-fpm can read every other site's open windows. Managed nodes run one
  site per container; a self-hoster serving several sites from one php-fpm
  does not have that separation. Dev serves three sites through one pool.

## Out of scope here (parent spec)

Package signing and its composer gap, the unseal daemon, a read-only webroot
for the web user, and the browser-held-key alternative. See
`vault_key_memory_exposure.md`.

## Files

- `plugins/mailbox/includes/MailboxIndex.php`, `includes/SealedBox.php` — Q1
- `plugins/mailbox/tests/mailbox_index_working_copy_mode_test.php` — Q1 (new)
- `includes/VaultHealth.php` — Q2, Q3, Q4
- `tests/vault/vault_health_test.php` — Q2, Q3, Q4
- `maintenance_scripts/install_tools/install.sh`, `maintenance_scripts/sysadmin_tools/joinery_swap_step.sh` — Q5
- `plugins/server_manager/includes/JobResultProcessor.php`, `NodeHealthProbe.php`, `node_detail_tabs/overview.php` — Q5 (swap telemetry)
- `plugins/server_manager/tests/job_result_processor_test.php`, `node_health_probe_test.php` — Q5
- `~/joinery-agent/primitives/observe_check_status.go` (+ `dispatch_test.go`), `~/scrolldaddy-dns/internal/machine/facts.go` (+ `facts_test.go`) — Q5 (the two Go collectors emit the swap keys; agented nodes never go through the SSH parser)
- `docs/sealed_vault.md` — Q2 (remediation text), Q6
