# gVisor for multi-tenant site containers: trial and verdict

**Status:** trial complete 2026-10-13. Verdict: **do not adopt** on our Linode
shared boxes, and the owner agreed. The trial itself changed nothing in the
codebase; it ran on a scratch box. The bugs it found (B1-B3 below, and B4 found
while proving B3) are fixed and live-proven on a test box, 2026-10-13, except
two steps that only a release can exercise, which are in the live verification
queue. B2's seccomp profile is not built.

## The question

On a multi-tenant host, the last wall between two customers' sites is the
shared Linux kernel (`multi_tenant_docker_hosts`, "Deferred: a separate kernel
per site"). A starter-tier customer is permission 10 on their own site. Today
that means they can run their own PHP, and the box's other sites are one kernel
privilege escalation away. gVisor (`runsc`) gives each container a kernel of
its own, running as an ordinary program, so an escape would need a gVisor bug
on top of a Linux one. Firecracker and Kata need hardware virtualization, which
Linode does not expose: there is no `svm`/`vmx` CPU flag and no `/dev/kvm` on
dev or on the trial box.

The owner asked whether gVisor works for our multi-tenant configuration, and
at what cost.

## How it was tested

- **Box:** a starter-tier shape in the test Linode account. g6-standard-2
  (4 GB, 2 CPUs), Ubuntu 26.04.1, kernel 7.0, AMD EPYC 7542.
- **Install:** release 0.8.486 unchanged, `install.sh docker --multi-tenant`
  (user-id remapping, walls, data root with project quotas), three sites at
  `--memory=256m --cpus=1.0 --disk=4G`.
- **gVisor:** release-20261005.0 from gVisor's apt repository, made Docker's
  default runtime. Each site was moved onto it by an ordinary
  `install.sh site` rebuild.
- **The same measurements under each runtime:** idle CPU and memory from the
  host's cgroup files, page times with `ab` (one and four visitors),
  starting processes, pgbench (scale 10), file copy and read,
  `update_database`, scheduled tasks, a 24-megapixel resize, `pg_dump`, a
  restart until `/login` answers, an in-place upgrade 0.8.486 → 0.8.487.
- **The multi-tenant gate's checks under each runtime:** walls over IPv4 and
  IPv6, CPU ceiling, supervisor restarts, memory overrun, fork bomb, disk
  allowance, layer cap, project-ID change, parser jail, host report, reboot.
- **A static probe** of which kernel interfaces a site can reach, run as
  `www-data` and as root.

## What gVisor gets right

- It runs our site image with Docker's user-id remapping on. Each sandbox
  sits in the site's own cgroup with its memory, CPU and process limits
  enforced. On the host it runs as the remapped uid 100000, in its own user
  namespace, under a seccomp filter. Every network wall still holds, because
  those rules live on the host.
- It answers every kernel interface a site does not need with its own code:
  netlink (nftables, XFRM, audit, generic), SCTP, CAN, io_uring, userfaultfd,
  perf and the rest. None of them reaches the host kernel. Under Docker's
  default runtime, the web user reaches several of these, and the host kernel
  loaded the `sctp`, `can` and `can_raw` modules when the probe asked (see B2
  below).

## Why not

### Cost, measured

Medians are under Docker's default runtime (runc) and under gVisor configured
as well as the trial could make it (the settings below, with the memory
tuners sizing correctly). The database and upgrade figures for gVisor are at
384 MB, because at 256 MB gVisor killed the site partway through, and each is
a single run. The other runc figures are medians of 4–6 samples.

| Measure | runc | gVisor | Ratio |
|---|---|---|---|
| CPU per idle site | 4.3% of a core | 24% of a core | 5.6× |
| Memory per idle site | 166 MB | 213 MB | 1.3× |
| Login page, one visitor (median) | 33 ms | 136 ms | 4.2× |
| Login page, four visitors (mean) | 145 ms | 703 ms | 4.9× |
| Register page, four visitors (mean) | 167 ms | 851 ms | 5.1× |
| Start 200 processes | 0.27 s | 2.5 s | 9× |
| pgbench reads, four clients | 7,875 tps | 2,340 tps | 0.30× |
| pgbench writes, four clients | 1,281 tps | 211 tps | 0.16× |
| Copy the code tree (2,379 files) | 0.41 s | 5.3 s | 13× |
| `update_database` | 7.5 s | 23 s | 3.1× |
| Scheduled tasks, one run | 0.38 s | 2.3 s | 6× |
| Restart until `/login` answers | 19 s | 45 s | 2.3× |
| In-place upgrade | 113 s (256 MB) | 502 s (384 MB) | 4.5× |

**CPU decides it.** Twelve resting sites take about 0.5 of a core under runc
and about 2.9 cores under gVisor, on a 2-CPU box. The idle cost breaks down
as follows (one site, 120 s each):

- cron's once-a-minute scheduled-task run: about 13%
- the supervisor's 5-second check: about 8% (about 15 short-lived processes
  per check; each process start costs 10–25 times more under gVisor)
- everything else: about 2%

A supervisor that starts no processes would remove most of the 8%. The
scheduled-task run is real work and stays.

**Memory decides the box size.** At 256 MB a gVisor site cannot run pgbench,
an upgrade, a 1.4 GB temporary file or a fork bomb without being killed (see
the next section). At 384 MB pgbench and the upgrade finished, though the
upgrade peaked at exactly 384 MB. That means about 8 sites per 4 GB box
instead of 12: about $3.00 a site instead of $2.00, before the CPU limit. A
dedicated Nanode ($5, its own kernel behind hardware virtualization) is a
stronger wall for little more.

### Behaviour that changes for the worse

1. **Running out of memory kills the whole site.** The host kernel sees one
   big process, the sandbox, and kills it. Postgres, Apache, PHP and cron go
   down together until Docker restarts the container, about 45 seconds.
   Under runc only the greedy process dies, and the supervisor brings it back
   in seconds. It happened nine times in one 30-minute benchmark at 256 MB,
   once in the middle of an upgrade.
2. **The host cannot see those kills.** Docker reports `OOMKilled=false`, and
   the container's `memory.events` count starts again at 0 after the restart,
   so the host report's `oom_kills` stays at 0.
3. **The process ceiling does not hold.** Processes inside gVisor are not host
   processes, so `--pids-limit` does not stop a fork bomb. The bomb grew until
   memory killed the site.
4. **A killed upgrade wedged later upgrades.** See B3 below. Any kill at that
   moment would do the same, but gVisor's whole-site kills make it far more
   likely.

### What adopting it would have required

Each of these was found and worked around on the box; none is built.

- **`--allow-suid=true`.** By default gVisor ignores setuid bits. The parser
  jail is setuid root, and 9 of its 19 gate checks failed until this was set.
- **`--overlay2=none`.** By default the container's own filesystem lives in a
  sandbox-private layer:
  - writes to `/etc` were lost at every restart;
  - a 1.4 GB file in `/var/tmp` filled the site's memory instead of reaching
    the 1 GB layer cap, killing the site (PHP spools uploads to `/tmp`);
  - `docker cp` into `/tmp` is still not seen inside, even with this setting.
- **DNS.** Docker's built-in resolver (127.0.0.11) cannot be reached from
  gVisor's network stack. Plain lookups to outside resolvers are UDP, which
  the outbound limits drop (`drops_site_*`). DNS worked only with a
  `resolv.conf` pointing at the host's upstream resolvers with
  `options use-vc` (TCP). Docker rewrites the file at every start, so it
  would have to become a bind mount in the run spec.
- **The memory planner.** `_memory_plan.sh` treats a cgroup limit as real
  only when it is below `MemTotal`. Under gVisor `MemTotal` *is* the limit,
  so the planner decided the site was unlimited and sized nothing: Postgres
  ran on its defaults (128 MB buffers, 100 connections) and PHP on its 5
  workers. The fix is `-le` for `-lt`, and `ImageDecoder` mirrors the same
  comparison. Not a defect today; it matters only under gVisor.
- **The host report** would need another way to see whole-sandbox kills.

## Found along the way (not gVisor)

Each is logged for a fix of its own.

- **B1. Disk-allowance project IDs collide with Docker's after any Docker
  restart.**
  - After a restart, Docker numbered its `--storage-opt size` layer projects
    from just above the highest site project ID (1000000010 upward, after
    ours at 1000000001–1000000009).
  - `docker_disk_pool.sh` picks the next ID from `/etc/projid` alone, so the
    next site would share a quota with a neighbour's writable layer.
  - The pool check counts Docker's 1 GB layer caps as sites' allowances, so
    re-setting a site's existing 4 GB allowance was refused.
  - `allow` moves the volumes into the project before it checks, so a refusal
    leaves them moved.
  - A reboot or a Docker package upgrade triggers all of it.
  - Fixed 2026-10-13 (`docker_disk_pool.sh` 2.4). Docker numbers its caps
    from the project ID of the folders it keeps them in, and after each start
    from past the highest ID it finds there (moby `daemon/internal/quota`).
    Docker's data root now carries project 2,000,000,000 from before Docker
    first starts, and every Docker folder inherits it; site IDs stay below.
    `allow` checks before it tags, counts only the sites' limits, and skips
    every ID in use on the pool. A site's backups and deploy projects are
    `joinery_<site>.backups` and `.deploy`, since a site named `a_deploy`
    shared `a`'s.
- **B2. A site's web user can make the host load kernel modules.** Under
  runc, the probe made the host load `sctp`, `can` and `can_raw`, and reached
  the nftables, XFRM, audit and generic netlink sockets. No site needs any of
  these. A modprobe.d deny list on multi-tenant hosts, or a seccomp profile
  that refuses those socket families, removes that kernel code from a
  tenant's reach. It costs a fraction of gVisor, and nothing in the trial
  suggests a site would notice.
  - The deny list existed, but only `install.sh server` wrote it. Fixed
    2026-10-13 (`host_housekeeping.sh` 1.16): every machine, on every
    converge, refuses 46 rare protocol modules and unloads any already
    loaded and unused. It also sets `dev.tty.ldisc_autoload = 0`, which stops
    line disciplines such as `n_gsm` from loading for unprivileged users. The
    seccomp profile is not built.
- **B3. An upgrade killed after its self-update wedges every later upgrade.**
  `upgrade.php` infers "resume after self-update" from staging alone (its
  `VERSION` matches the target). A continuation killed after plugin archives
  were merged into staging fails the core-archive check on every later run
  (`extra_file: public_html/plugins/joinery_ai/LICENSE.md`). Emptying
  `deploy/upgrades/` cleared it.
  - Fixed 2026-10-13 (`upgrade.php` 1.17). Only the run a self-update
    restarts (`JOINERY_UPGRADE_RERUN=1`) resumes from staging. Every other
    run downloads into an empty staging. The staged `VERSION` also waits
    beside staging until the deploy, so an older `upgrade.php`, which the
    agent puts back after a killed run, never resumes from a merged staging.

## Recommendation

1. Do not run sites under gVisor on Linode shared instances. The isolation is
   real, but the cost is about 6× the idle CPU, 4–5× page times, a third
   more sites' worth of memory per box, and new failure modes that our
   monitoring cannot see.
2. Shrink the shared kernel's reach under runc instead: B2's deny list and a
   tighter seccomp profile, beside the refusal of unsigned packages that
   0.8.487 already ships (`host_restrictions`).
3. Keep "your own server" as the answer for customers who need a
   hardware-virtualization wall.
4. Revisit stronger isolation only on hardware with KVM. There, gVisor's KVM
   platform or a real microVM (Firecracker, Kata, Cloud Hypervisor) changes
   these numbers, and the comparison should be rerun.

## The trial box

`gv-mt`, test account, Linode 108000460, 173.255.234.231, tagged `keep`.
- The three sites are still on gVisor (runtime `runsc-tuned`), with the box
  copies of `_memory_plan.sh` patched.
- B1's state is still visible there (`xfs_quota -x -c "report -p" /srv/joinery`).
- The scripts and raw results are under `/root/bench`.
- Delete it by hand once B1 has been looked at.
