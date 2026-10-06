# Multi-tenant Docker hosts: limits and isolation for many sites on one box

**Status:** Draft, 2026-10-04. WP1 built 2026-10-05 and released in 0.8.459
(host_report.sh 1.7, JobResultProcessor 1.55, node overview 1.37,
stats_handler 1.4); docker-prod has reported per-site figures since, so the
week of measurement ends about 2026-10-12. The agent half (check_status
memory inside a container) ships with agent 1.57.0. WP0 built 2026-10-05
(_site_run_spec.sh 1.0, install.sh 2.94, rebase_site_container.sh 1.8,
migrate_site_to_code_volumes.sh 1.1, remove_account.sh 2.3; gate site_run_spec);
WP3 built 2026-10-05 (install.sh 2.95, _site_run_spec.sh 1.1,
rebase_site_container.sh 1.9, migrate_site_to_code_volumes.sh 1.2). Both run on a
scratch Nanode the same day; the rebase-with-caps check is still to run. WP9
built 2026-10-05 (install.sh 2.96, joinery-base 2.1, install_email.sh 2.27,
mailbox plugin 1.134.0; test mailbox_container_mail, gate container_mail_stack).
WP2 items 1 and 2 built 2026-10-06 (_memory_plan.sh 1.0.0, tune_php_fpm.sh
1.0.0, tune_postgres_memory.sh 1.4.0, _site_supervisor.sh 1.0,
Dockerfile.template 6.0, rebase_site_container.sh 1.10, agent 1.58.0; gates
tune_php_fpm, site_supervisor) and proven on the scratch Nanode; item 3 is
measured, and the owner set the starter budget at 256 MB (2026-10-06). WP5
items 2 and 3 built 2026-10-06 (_site_run_spec.sh 1.2, install.sh 2.98,
rebase_site_container.sh 1.11, migrate_site_to_code_volumes.sh 1.3, agent
1.58.1; gates site_run_spec, docker_multi_tenant) and run on scratch Linodes. WP5 items
4, 5 and 7, with the walls of items 1 and 6, built 2026-10-06 (multi_tenant_host.sh 1.0,
host_report.sh 1.8, JobResultProcessor 1.58, node overview 1.42; gate multi_tenant_host)
and proven on the scratch Linode, including a reboot by hand (an unattended one is a live
check); multi_tenant_host.sh 1.1 has reviewer2's fixes; install.sh 3.00 runs them on every
`docker --multi-tenant` install (gate docker_multi_tenant). Nothing else is built. Split out of the starter
tier spec. Reviewed by public-html-d7 the same day; its findings (S-numbers)
are folded in.

## What this does

Docker mode puts several sites on one machine, which is the cheapest way to
run small sites. This spec gives every site on such a machine limits and
isolation, so that one site cannot use up the machine or reach into another
site:

| Limit | What the site gets | What happens at the edge |
|---|---|---|
| Memory | a fixed budget (256 MB to start) | that site's processes are killed and brought back up; neighbours are untouched |
| CPU | at most one core, and an equal share when the box is busy | the site runs slower; neighbours keep their share |
| Disk | a fixed allowance (about 3.5 GB of its own data at 12 sites a box; WP4 sets the figure) | uploads and stored mail are refused in plain words; the database keeps running; neighbours keep writing |
| Isolation | its own private network, root in it is not root on the host | it cannot connect to another site at all |

A Docker host built this way is a **multi-tenant host**. The starter tier
(`starter_tier`) is its first user, and the reason strangers can share a box
at all. docker-prod, our own Docker host, stays an ordinary Docker host, but
WP0–WP3 help it straight away. Existing hosts stay as they are unless
rebuilt.

## Where the caps live

**The caps belong to the box, not the site.** Every site placed on a box
gets the same limits. The starter tier spec adds `mgh_tier`, so picking a box
is picking a tier.

New columns on `mgh_managed_hosts`, set on the host's edit page
(`host_add.php`):

| Column | Meaning | Empty means |
|---|---|---|

| `mgh_site_memory_mb` | each site's memory budget | no limit (Docker's default, which is what docker-prod has today) |
| `mgh_site_cpus` | each site's CPU ceiling, in cores (e.g. `1.0`) | no ceiling |
| `mgh_site_disk_gb` | each site's disk allowance | no allowance; refused on a box built without a disk pool (WP4) |

The edit page checks the arithmetic when a host is saved and says so in plain
words when it does not fit. `max_sites × memory` must fit within the box's
memory minus a host reserve of 900 MB. The disk pool must hold WP4's
per-site formula for every site. The box's memory and pool size come from
its host report, which it already sends.

**`max_sites` counts sites only (S10).** `pick_for_provisioning` counts every
node linked to the host, including the host's own agent node
(`managed_hosts_class.php` placement query), so `max_sites = 12` places 11.
It counts site nodes only.

Existing hosts and existing sites are untouched. docker-prod carries our own
sites and stays unlimited.

## Work packages, and what depends on what

WP0 comes first, because every other limit is a `docker run` flag. WP4's disk
limit only holds once WP5.3 is in place (S5). WP2's budget is measured after
WP9, since a container's mail stack alone costs about 200 MB. Everything else
can ship on its own.

### WP0 — One recorded run spec per site

**A container's caps vanish when it is recreated, and that is a live bug for
`--memory` today (S9).** `rebase_site_container.sh` (`save_run_args`) and
`migrate_site_to_code_volumes.sh` rebuild a container's run arguments from
its name, hostname, restart policy, ports, environment and volumes, and
nothing else. A site installed with `--memory` loses it at its next rebase.
Every flag this spec adds (`--cpus`, `--pids-limit`, `--storage-opt`, the
network, the security options) would be lost the same way.

Root fix: **the host records one run spec per site**, a file under the
host's site state that holds every `docker run` argument. **One function
builds the run arguments from it.** Install, rebase, migrate and
`site-limits` (WP6) all read and write that spec, and nothing reconstructs
arguments from `docker inspect`. A gate test rebases a site with caps, then
checks that every cap is still in force.

**Built 2026-10-05.** The spec is `/etc/joinery/sites/{site}/run_spec` on the
host, one `key=value` per line (hostname, restart, memory, cpus, pids_limit,
one `publish=` and one `volume=` line each), every line checked on write and
on read. It holds no secret: the environment stays the container's own and is
passed as an `--env-file` by whoever recreates it. `run_spec_args` is the one
builder; `run_spec_render` writes a new site's spec, `run_spec_set` and
`run_spec_add_volume` change one, and `run_spec_adopt` reads a container made
before specs existed, once, while it still exists (install.sh, rebase
`prepare` and migrate `prepare` each do this when the spec is missing).
install.sh takes the spec's memory when `--memory` is not given, accepts any
size Docker takes, checks it before anything stops, and keeps the spec's lines
it does not own (other ports, other volumes); `--memory=none` lifts a budget.
Adopt refuses, by name, anything a spec cannot carry. Rebase keeps a copy of
the spec at `prepare` and again before `swap` stops anything, and rollback
checks it (or converts a 1.7 `run_args`) before it removes anything. Reviewed
by reviewer2 2026-10-05 (B1-B8, all fixed). `--cpus` and `--pids-limit` flags
come with WP3; the spec and builder already carry both. The `site_run_spec` gate covers the helper and the scripts against a
stubbed Docker. **On a real Docker host (2026-10-05):** a fresh site with all
three caps, a rebuild that changed one and kept the others, and a rebuild with
the spec deleted (read back from the container, identical) all held. The first
read-back failed: Docker refuses `len` of a list a container never set, and a
real container has no `CapAdd`, so adopt read empty fields and refused a
healthy container. Each `len` is now guarded, any failed read stops adopt, and
the gate's stub refuses an unguarded `len` as Docker does. The rebase-with-caps
check needs a site on an older PostgreSQL (live verification queue).

### WP1 — See each site's usage

The host report (`sysadmin_tools/host_report.sh`, `emit_containers`) already
lists each container's state. Add, per container:

- memory in use, its limit, and the number of times it has been killed for
  running out (cgroup `memory.current`, `memory.max`, `memory.events` →
  `oom_kill`)
- CPU used since the last report (cgroup `cpu.stat` → `usage_usec`, turned
  into an average share of a core between reports)
- disk in use and its allowance (WP4's quota report; volume sizes until then)
- outbound network traffic since the last report, so a site scanning the
  internet shows up (`hosted_abuse_response`)

The figures are read from the cgroup files, not from `docker stats`, which is
slow and takes a second sample per container.

**The site's own status check reports the host, not the site (S15).** Inside
a container, the status check's memory figure reads `/proc/meminfo`, which is
the host's. Read the cgroup limit and use instead, as `tune_postgres_memory.sh`
already does. The disk figure stats the web root, which is the code volume,
so after WP4 it reports the site's allowance.

The node page's overview shows each site's line on its host, and an OOM kill
count above zero shows in amber.

**Built 2026-10-05.** Disk is one `du -s` over every site's named volumes per
report (0.8 s for 8 sites on docker-prod), until WP4's quota report replaces it.
Each container also reports its processes against their ceiling, which WP3's
pids limit is set from. A one-off read of docker-prod on 2026-10-05 (8 idle
sites, up 6 days, no limits): 111-208 MB in use, peaks 474-973 MB since start,
75 processes each, about 3% of a core each on average.

**This WP is also the measurement.** Run it on docker-prod for a week and set
the starting caps from what it shows: peak memory during an upgrade, a backup
and `update_database`, CPU at rest and at peak, the disk a fresh site uses
before any content, and what the `backups` volume holds between runs (WP4).

### WP2 — Memory

`--memory` exists (install.sh, with swap pinned to the same figure), and
PostgreSQL already sizes itself from it (`tune_postgres_memory.sh`). Three
gaps remain:

1. **PHP-FPM is not sized.** The container keeps the packaged pool (up to 5
   children). Five PHP workers at full size can use the whole 256 MB budget
   on their own. Add a matching tuner, run at container start the same way
   the PostgreSQL one is, that sets `pm.max_children` from the budget: budget
   minus PostgreSQL's share minus Apache, divided by a measured worker size,
   with a floor of 2. Set PostgreSQL's `max_connections` to match. It is
   unset everywhere today, so a small site keeps the packaged 100.
2. **Nothing brings a killed main process back (S29).** When memory runs out,
   the kernel kills the biggest process in the container. A killed worker is
   restarted by its parent. A killed PostgreSQL postmaster, PHP-FPM master or
   cron is restarted by nothing, because the container's first process is a
   shell chain that ends in Apache. A dead cron is the worst case: the site
   still answers, so nothing notices that scheduled tasks and the site
   agent's supervisor have stopped. The container gets a small supervisor
   that restarts any of the four that dies and logs it, and the container
   health check counts a missing one as a failure.
3. **The budget must be proven, not assumed.** On a 256 MB container, run an
   in-place upgrade, `update_database`, a full backup, and a large photo
   upload with resize. Each must finish without an OOM kill. Where one does
   not, either the job gets smaller or the budget gets bigger. The number
   that comes out is what the starter tier uses.

**Found on the first test box (2026-10-05, Nanode, 0.8.459 plus WP0/WP3).** A
fresh site has the mailbox plugin active, so its installer runs rspamd in the
container. rspamd costs about 200 MB once settled (controller, normal, proxy
and hs_helper at about 50 MB each), and more while it compiles its patterns at
start. At a 384 MB budget it never settled: the kernel killed it five times in
a minute, each restart compiled again, memory and CPU pressure sat above 90%,
and the site stopped answering (a 30-second timeout where it had answered in
0.04 s). Each kill also left an orphaned `hs_helper` (144 MB) behind, owned by
PID 1, which nothing reaps or supervises (item 2). A 256 MB site cannot hold a
per-container rspamd at all. The owner's answer (2026-10-05) is WP9: no site
container carries a mail stack. The orphaned process is item 2's to fix
either way. docker-prod's sites are on 0.8.459 but have run since
2026-09-28, at 117-206 MB and about 54 processes each, so rspamd is not running
in them today. Plugin installers run at every container start, so any of them
with the mailbox plugin active gains about 200 MB at its next restart; which
ones have it active is still to check.

**Built 2026-10-06 (items 1 and 2).** One sourced file,
`sysadmin_tools/_memory_plan.sh`, splits the budget: PHP workers =
(budget − `shared_buffers` − 128 MB) ÷ 40 MB, at least 2 and at most 80, and
`max_connections` = workers + 20. `tune_php_fpm.sh` writes the pool as
`pool.d/zz-joinery-memory.conf` (a second `[www]` section over the packaged
one) before PHP-FPM starts; `tune_postgres_memory.sh` 1.4.0 reads the same
plan and writes `max_connections` for a container's budget. 256 MB gives 2
workers and 22 connections. Measured for the figures (2.1 site, 0.8.459): the
heaviest admin page used 10 MB of PHP memory; everything that is not a PHP
worker came to about 90 MB at rest. Item 2 is `_site_supervisor.sh`, the
container's main process (Dockerfile.template 6.0): it starts Apache, checks
the four every 5 seconds, and restarts one gone for three checks after killing
what it left behind; a hold file (`/run/joinery/supervisor.hold`, cleared by
every start) stops it fighting a deliberate stop, and
`rebase_site_container.sh` 1.10 takes it. `--check` is the image's
HEALTHCHECK, and agent 1.58.0's `container_health` restarts a container Docker
calls unhealthy. On the scratch Nanode at 256 MB: each of the four, killed
with SIGKILL, was back in 12-20 seconds and the site kept answering; a dead
FPM master's and Apache parent's leftovers were cleared; `docker stop` took
0.6 s and PostgreSQL's next start found a clean shutdown; with cron unable to
start, Docker called the container unhealthy after 80 seconds while the site
still answered, which is the case nothing caught before.

**Item 3 at 256 MB (2026-10-06, scratch Nanode, 0.5 CPU, 4 concurrent page
loads throughout, a 150 MB database and 400 MB of uploads).** Memory that
cannot be reclaimed, at its peak:

| Job | Peak | Killed for memory |
|---|---|---|
| `update_database` | 124 MB | none |
| full backup (490 MB to the bucket), then an incremental with the database | 177 MB, 186 MB | none |
| one 24-megapixel photo, every size made | 229 MB | none |
| two such photos at once | 253 MB | one of the two |
| in-place upgrade (792 s) | 234 MB | none |

Each job fits alone; two heavy jobs at once do not. A photo's decode is about
100 MB that PHP's `memory_limit` does not count (GD allocates outside PHP's
own allocator), so the 40 MB a worker covers a page, not a photo. A first
upgrade run showed the WP9 transition hazard for real: `--force-upgrade` put
back 0.8.454's mail installer, whose `apt-get` was killed three times.
**One decode at a time (built 2026-10-06).** `includes/ImageWorkLock.php` is a
site-wide `flock` (`cache/image_work.lock`) taken around every image decode:
`FileBlob::_generate_resized` (file_blobs_class 1.2.4) and
`UploadHandler::handle_image_file`, which also frees its cached image at last
(it never did: a 24-megapixel photo's 92 MB stayed resident through every
resize after it; found by reviewer2). Test `image_work_lock`. On the scratch
Nanode at 256 MB, three 24-megapixel resizes started at once queued (done at
5, 12 and 19 s) with no kill and a peak of 217 MB. Making each decode smaller
(a scaled JPEG decode, orientation after the downscale) is spec
`image_decode_memory`.

**The budget sweep on 0.8.460 (2026-10-06).** Scratch Nanode, base 2.2 with
`djpeg`, one decode at a time and the scaled decode (spec `image_decode_memory`),
0.5 CPU, 4 concurrent page loads, the same data. Peak memory that cannot be
reclaimed, and kills:

| Budget | Idle | 24 MP photo (plain / portrait) | Backup + photo | `update_database` | Upgrade + photo |
|---|---|---|---|---|---|
| 256 MB | 44 | 104 / 126, none | 158, none | 112, none | 206, none |
| 192 MB | 45 | 110 / 125, none | 150, none | 129, none | 187, none |
| 160 MB | 45 | 109 / 126, none | 149, none | 107, none | 157, **killed: the upgrade failed and rolled back, schema ahead of code** |
| 128 MB | 63 | 117, **portrait killed** | **backup killed** | 121, none | **killed, upgrade failed** |

At 256 MB the one kill was a 24 MP progressive JPEG decode (about 180 MB with
its PHP worker) beside other photos. `image_decode_memory`'s follow-up
(9567eea4) sizes the decode ceiling from the site's budget (budget −
`shared_buffers` − 128 MB, capped at `image_decode_max_mb`): a 256 MB site's
ceiling is 64 MB, so it refuses such a photo with a plain message instead, and
a decode the kernel kills is retried later rather than recorded as damaged.
At 384 MB the ceiling is 160 MB, the cap, and the photo decodes. 192 MB is the smallest budget where every job
finished, but its upgrade peaked 5 MB under the cap; 256 MB holds every job
with 50 MB to spare. A failed upgrade under memory pressure leaving the schema
ahead of the code is its own risk, apart from the budget.

**Caps that add up to more than the machine (tested 2026-10-06).** Three sites
at 384 MB on the 961 MB Nanode (1.15 GB of caps, the same 115-120% as 12 sites
at 384 MB on a 4 GB box), mailbox off, 4 PHP workers each. One photo in each
at once: all three finished in 10 s, free memory fell from 400 MB to 126 MB,
the box began to swap, and page loads stayed under 0.8 s. Then two photos and
`update_database` in each at once: the whole machine froze within two seconds.
No site, no front-door proxy and no SSH answered for 35 minutes; the kernel
logged no out-of-memory kill and the system journal stopped at the moment the
jobs began; it took a reboot from the Linode API. A cap inside one container
ends in a clean kill of one process in that site; a machine whose caps exceed
its memory ends, in the worst case, in every site on it down until someone
reboots it. So the sum of a box's caps stays within its memory less the host
reserve, whatever per-site figure is chosen.
**Decided (owner, 2026-10-06): a starter site's budget is 256 MB.** Every job
in the sweep finished there with about 50 MB to spare. Photo resizes queue one
at a time (`ImageWorkLock`), and a photo too big for the decode ceiling is
refused with a plain message, not killed. This matches the starter tier's
12 × 256 MB per 4 GB box.

### WP3 — CPU and processes

- `--cpus=N` on `docker run`: a hard ceiling. At `1.0`, one site can use at
  most half of a 2-CPU box. Fair sharing under load is already Docker's
  default: every container has equal weight, so a busy box divides CPU
  evenly between the sites that want it.
- `--pids-limit=512`: a fork bomb stops at 512 processes instead of filling
  the host's process table. 512 is far above what Apache, PHP-FPM,
  PostgreSQL and cron need, and WP1 confirms that.
- `tune_postgres_memory.sh` already reads the cgroup v2 `cpu.max` quota for
  its parallel-worker setting, so PostgreSQL follows the ceiling with no
  change.

**Built 2026-10-05.** `install.sh site --cpus=N --pids-limit=N`, each recorded
in the run spec like `--memory`: a rebuild keeps the spec's value, the option
records a new one, and `none` lifts it. A new site gets `pids_limit=512`
whatever the box; a site that already has a spec keeps what it records (an
adopted docker-prod container records none). A value below 128 is refused, since an idle site runs 54 to 75 processes and
threads (the limit counts both). Every limit is checked before anything stops,
including a recorded CPU ceiling above the CPUs Docker counts (`docker info`,
not `nproc`), which Docker would refuse after the old container was gone. The
rebase and code-volume scripts run the same check (`run_spec_fits_host`) before
they stop anything, and so does every builder of run arguments. Reviewed by
reviewer2 2026-10-05: B1-B5 fixed, VALID. The options are refused for a
bare-metal site instead of being ignored. The box's caps reach `install.sh`
through the starter tier's "install a site container" word, which reads
`mgh_site_cpus` and `mgh_site_memory_mb`; neither column exists yet. The gate
`site_run_spec` covers the options against a stubbed Docker. On the Nanode
(1 CPU): two busy loops under `--cpus=0.5` used 0.52 of a core and were
throttled; a fork bomb as `www-data` under `--pids-limit=400` stopped at 400
with 2,481 forks refused, the host answered commands in 7 ms and a new SSH login
took 2.6 s against 1.4 s idle, and a `docker restart` brought the site back in
45 s. A ceiling above the host's CPUs and one below 128 were refused with the
container still up.

### WP4 — Disk

Docker on a stock Linode disk (ext4) cannot cap one container's disk use.
Per-container limits need **XFS with project quotas** under `/var/lib/docker`.
**WP4 depends on WP5.3 (S5).** Without user-namespace remapping, the owner of
a file can change its XFS project ID (`chattr -p`), and `www-data` owns its
uploads. A site could then move its own bytes out of its allowance. With
remapping, the container's users are not in the host's user namespace, and
the kernel refuses the change.

**The pool.** `install.sh docker --disk-pool=SIZE` runs before Docker is
installed on a fresh host. It does the following:

1. Allocates a file of that size on the root disk (`fallocate`, fully
   allocated, so the pool can never promise space the disk does not have).
2. Formats it XFS and mounts it at `/var/lib/docker` with `loop,prjquota`
   through `/etc/fstab`, so it comes back after a reboot. The loop device
   uses direct I/O, so the box does not cache every page twice (S16).
3. Makes Docker and containerd wait for it (`RequiresMountsFor=/var/lib/docker`
   in a drop-in for each unit). If the pool fails to mount, Docker must not
   start. Otherwise it would build an empty `/var/lib/docker` with no disk limits on the
   root disk, and every site would look gone (S16).

A loop file rather than a second cloud disk works the same on any provider
(see the Linode exit principle). On an 80 GB box the pool is about 68 GB. The
rest is the OS, logs and headroom.

**The allowance.** All of a site's data lives in its named volumes
(`ALL_SITE_VOLUMES` in install.sh, all named `SITENAME_*`). `install.sh site
--disk=SIZE` does the following:

1. Gives all of that site's volume directories except `backups` **one shared
   XFS project ID**, so the allowance covers the site's whole footprint, not
   each volume separately. The ID and paths are recorded in `/etc/projects`
   and `/etc/projid`, so they survive a reboot.
2. Takes site project IDs from a range Docker never reaches. Docker hands out
   its own project IDs for `--storage-opt size`, counting up from the data
   root's ID. A collision would silently pool a container's layer with a
   site's volumes (S14).
3. Sets that project's hard limit to the allowance **plus 10% headroom**
   (see "At the limit").
4. Caps the container's writable layer separately with `--storage-opt
   size=1G`.
5. Under user-namespace remapping, Docker's data root moves to
   `/var/lib/docker/<uid>.<gid>/`, and the project paths follow. The
   host-side `cp -a` and numeric `chown` steps in `rebase_site_container.sh`
   must write remapped IDs (S14).

`install.sh site --disk` on a box without a pool **refuses with a message**
and does not ignore the flag. A cap that silently does nothing is worse than
no cap.

**The `backups` volume is ours, not the customer's (S20).** Fleet backups
stage their archive there (`BackupRunner`). It gets its own project, outside
the allowance. Backups run one per machine at a time, so if WP1 shows the
volume holds only the run in flight, one reserve the size of the largest
site's backup covers the whole box. If it keeps local copies between runs,
each site needs its own reserve.

**The writable layer holds more than it looks (S11).** An upgrade keeps the
previous code in `public_html_last`, which is not on a volume, so about
150 MB lands in the layer on every upgrade. A failed deploy leaves
`public_html_failed_<timestamp>`. PHP extensions installed by plugins, the
Postfix queue, `/tmp` and rspamd's files all live there too. Under a 1 GB cap
an upgrade could fail halfway through swapping the code. Upgrade staging and
the kept previous code move to a volume of their own, a `deploy` volume, in a
project of its own outside the allowance. The 1 GB layer cap then holds
packages and temporary files only.

**A nearly full site can still be upgraded (S12).** `upgrade.php` refuses to
run with less than 500 MB free under `uploads/`, which is inside the
allowance. A site above about 87% full would be stuck on old code, and on a
shared box those are exactly the customers at their limit. The free-space
check reads the `deploy` volume, where the upgrade actually writes.

**The pool formula.** The pool must hold, for every site, the allowance plus
10% headroom, plus the 1 GB layer and the `deploy` volume (about 0.5 GB),
plus the backup reserve. At 12 sites on a 68 GB pool, with one shared backup
reserve of about 4 GB, that leaves an allowance of about **3.5 GB per site**.
WP1's figures set the final number.

**What the site sees (S15, partly confirmed by reading).** XFS reports a
project's limit as the size of the filesystem to a directory that carries the
project, so `df` on any of the site's volumes shows its own allowance. The
status check stats the web root, which is on the code volume, so the hosted
banner's Disk line (`HostedPlanNotice`, "Disk — the node's own status check")
reads the site's own figure with no new plumbing. `df /` inside the container
shows the 1 GB layer instead. Verify this on the first test box.

**At the limit (S13).** At the XFS hard limit, writes fail. PostgreSQL stops
when it cannot write its log. With the database down nobody can sign in to
delete anything, and the banner cannot render. So the site must never reach
that wall:

- **At 100% of the allowance, the app refuses new uploads and stored mail**,
  in plain words, naming the way out: delete something, or, on the starter tier, Move to
  your own server. Inbound mail is refused as temporary, so senders retry.
- **The XFS hard limit sits 10% above the allowance.** Only the database,
  logs and system writes can use that headroom. It is there so PostgreSQL
  never hits the wall first.
- The banner warns at 80% and urges at 95%.

### WP5 — Walling sites off from each other

**This is a prerequisite for selling the tier to strangers, not an extra.**
Today's Docker mode is explicitly not a security boundary
(installation guide § What Docker mode is). Sites share one network, and
containers run with Docker's default privileges. On a box sold to strangers the site
admin is a stranger who is permission 10 on their own site. Permission 10 can
upload a plugin ZIP (`admin_plugins` → `PackageInstallPage::upload`), which is
running their own PHP in the container as `www-data`. An uploaded, unsigned
plugin never has its host installer run as root
(`_plugin_installers_start.sh`, `plugin_package_verified`). So the threat to
design against is `www-data`, and root in the container if `www-data` can
escalate. Every limit has to hold against that.

On a multi-tenant host:

1. **One private network per site, on a pinned subnet (S8).** Every
   container site, on any Docker host, runs on a network of its own with an
   IPv4 subnet and a private IPv6 /64, recorded in its run spec
   (`node_outbound_and_transfer` WP2, which also fixes the one thing that
   assumes the default bridge, the proxy address Apache trusts). On a
   multi-tenant host
   that network is the wall: the container is attached only to it, so a site
   cannot open a connection to another site's web server or PostgreSQL. The
   proxy reaches each site through its published loopback port, as now.
2. **Capabilities: keep what the image needs, drop the rest (S7).**
   `--security-opt no-new-privileges` is **not** used. The parser jail
   (`/usr/local/sbin/joinery-jail`) is setuid root, run by `www-data`, and it
   unshares a network namespace before dropping to its own user.
   `no-new-privileges` turns setuid off outright, so the jail would stop
   jailing, which is worse than either choice. Other things need
   capabilities too:
   - `su postgres` (Dockerfile, `tune_postgres_memory.sh`)
   - cron running jobs as `www-data` (SETUID, SETGID)
   - the ownership fixes at container start (CHOWN, FOWNER, DAC_OVERRIDE)
   - `nft` in `_site_state.sh` (NET_ADMIN)

   Start from `--cap-drop=ALL`, add back exactly what a test shows each of
   these needs, and prove the jail still jails in the gate. User-namespace
   remapping (item 3) is what makes the remaining capabilities harmless to
   the host.

   **The jail in a container (checked 2026-10-06, scratch Nanode, 0.8.460).**
   Under Docker's defaults the `parser_jail` gate passes in full (19/19, as
   `www-data`). The jail gets no network namespace of its own there, since
   `unshare` needs SYS_ADMIN, and the jailed command sees the container's
   network. It still cannot use it: the seccomp filter refuses `socket`,
   `socketpair`, `connect` and `bind`, and the command inherits only stdin,
   stdout and stderr (an outbound fetch from inside fails). The jail needs
   **SETUID and SETGID** and nothing else: with those two added to
   `--cap-drop=ALL` every check matches the default. With neither, the
   launcher refuses (exit 125, `setgroups`), and `DocumentText` reports a
   failed parse, never an unjailed one.

   **What a whole site needs (measured 2026-10-06, same box).** The site's own
   container, on its own volumes and run spec, started under each set and
   probed: healthy, a page served, the supervisor's check, the jail gate, cron
   running the scheduled task as `www-data`, a package install after
   `apt-get update`, and the postmaster killed and brought back.

   | Set | Result |
   |---|---|
   | Docker's default | everything passes (the control) |
   | `--cap-drop=ALL` | exits at start: chmod and mkdir refused, Apache never starts |
   | ALL, then CHOWN DAC_OVERRIDE FOWNER SETUID SETGID KILL | everything passes, same as the default |
   | the six, less CHOWN | unhealthy: PHP-FPM down, page 503, jail 7/19 |
   | less DAC_OVERRIDE | exits at start: PostgreSQL's memory file cannot be written |
   | less FOWNER | unhealthy: PostgreSQL does not start, page 500 |
   | less SETUID or SETGID | unhealthy: `su` refused, PostgreSQL does not start |
   | less KILL | passes, but root cannot signal another user's process, which is how the supervisor clears what a dead service left (`kill_leftovers`) |
   | ALL plus the six plus NET_BIND_SERVICE | the same as without it: Docker opens the low ports to every user in the container's network, so Apache binds 80 regardless |

   So a site runs with `--cap-drop=ALL` and exactly **CHOWN, DAC_OVERRIDE,
   FOWNER, SETUID, SETGID, KILL**. It drops, from Docker's default, FSETID,
   MKNOD, NET_RAW (no raw sockets, so no forged packets onto the bridge),
   SETFCAP, SETPCAP, NET_BIND_SERVICE, SYS_CHROOT and AUDIT_WRITE. NET_ADMIN
   is not in Docker's default either, so `nft` already fails in today's
   containers. Nothing needs it: a site is frozen for a copy only on a machine
   (`site_copy`: a copy of a running container site is refused). Not yet run
   under the six: an agent-run upgrade (the box's site has no agent); the gate
   below covers it.

   **Built 2026-10-06, for every container site, not only on multi-tenant
   hosts:** no site needs what is dropped, wherever it runs.
   `_site_run_spec.sh` 1.2 holds the six as `RUN_SPEC_CAPS`, and
   `run_spec_args`, the one builder of a site's run arguments (install,
   rebase, the code-volume move), adds `--cap-drop=ALL` and the six for every
   site. They are the platform's, not the site's, so they are not spec lines:
   a site installed before this gets them at its next rebuild. Adopt reads the
   capabilities Docker records (`CAP_CHOWN` and so on) and refuses, by name,
   only one beyond the six. The `site_run_spec` gate pins the arguments and
   both sides of adopt (164 checks; four mutations caught). On the scratch
   Nanode: `capsite` recreated through the new builder came up healthy with a
   bounding set of exactly the six (`CapBnd` 0xeb), the jail gate passed 19/19,
   cron ran, and PostgreSQL came back after a kill. Adopt read that container
   back identically, and refused a real container holding NET_ADMIN, naming it.
3. **Root in the container is not root on the host.** Docker's
   user-namespace remapping (`userns-remap` in `daemon.json`) is on for
   multi-tenant hosts. It is a daemon-wide switch, so
   `install.sh docker --multi-tenant` sets it on a fresh box, and it is never
   turned on under existing sites. Volume ownership changes under it. The
   volume-seeding steps in install.sh (`docker run ... tar -x -p`) and the
   rebase script's host-side copies (WP4 item 5) must be proven to still
   produce a working site. WP4's disk limit depends on this item (S5).

   **Built 2026-10-06.** `install.sh docker --multi-tenant` (install.sh 2.98)
   writes `"userns-remap": "default"` into `daemon.json` (merged, never
   clobbered; a broken file is refused) before the Docker package starts the
   daemon, then checks the daemon reports `name=userns`. On a host that already
   has Docker it does nothing if Docker remaps, turns it on and restarts Docker
   only when Docker holds no container and no volume, and otherwise refuses,
   naming the counts, with nothing changed. `run_spec_docker_remaps_ids`
   (`_site_run_spec.sh`) is the one test of "does this host remap", and
   "Docker did not say" is never read as "no".

   What writes into a volume from the host, checked one by one:
   - install.sh's seeding runs `tar` inside a container, and its two host-side
     reads (`site_database_majors`, `code_volume_is_populated`) only read: fine.
   - the rebase's `cp -a` between volumes keeps the remapped owners: fine. Its
     rollback chowned PostgreSQL's log directory from the host with the old
     image's container ids, which under remapping are not the owners (B2): it
     now does that inside a container of the old image, by name
     (rebase_site_container.sh 1.11).
   - the code-volume move `docker cp -a`s into each volume's host directory,
     which writes container ids (B3). A remapping host is always built fresh,
     so its sites are born on code volumes; the move refuses on one
     (migrate_site_to_code_volumes.sh 1.3).
   - the host agent's site removal read the victim's config at the fixed path
     `/var/lib/docker/volumes/...`, which under remapping is
     `/var/lib/docker/100000.100000/volumes/...` (B4): it now asks the daemon
     for its root (`docker info`, a clean absolute path or a refusal), agent
     1.58.1. The host report already takes each volume's path from Docker.
   - Found on the way (B5): `install.sh docker` could not install Docker on a
     machine that once had it, since `gpg --dearmor` stopped to ask about the
     old key with no terminal. It now writes the key with `--batch --yes`.

   Gate `docker_multi_tenant` (25 checks, stubbed Docker and systemctl; four
   mutations caught). **On a fresh 2 GB Linode, Ubuntu 26.04.1, Docker 29.8
   (2026-10-06):** Docker removed entirely (its old apt key left in place),
   then `install.sh -y docker --multi-tenant`: daemon up under remapping,
   container root uid 100000; run again, "already remaps". `sitea` installed at
   256 MB, then rebuilt, then `siteb` beside it: each healthy, the six
   capabilities, PID 1 is uid 100000 on the host, volume files owned by
   100000+ ids (`www-data` is 100033), page served, jail 19/19, a package
   install, cron running, PostgreSQL back after a kill. The host report
   measured each site's memory, processes and disk.

   **The limit, stated.** Docker's remapping is one range for the whole
   daemon, so every site's root is the same host uid. It keeps a site from the
   host: a process that escapes its container is uid 100000, which owns no host
   file. It does not keep one site from another: that uid owns every site's
   volumes. Between sites the walls are the container's own (namespaces,
   separate volumes) and items 1 and 4, and past those the shared kernel
   ("Deferred: a separate kernel per site").

   **Reviewed by reviewer2 2026-10-06 (F1-F9).** F1, a blocker: the move's
   remap check ran bare under `set -e`, so on every host that does not remap
   it ended the script with nothing said; the check's result is now caught
   (same idiom in install.sh's two), and the gate runs the real script against
   the stub (proven on a real non-remapping host: past the check, into
   prepare). F2: images also refuse the switch on an existing host, since
   remapping strands them under the old root. F3: the BuildKit policy's `sed`
   edit of `daemon.json` is gone; one helper merges every key as JSON. F5:
   adopt refuses a container that dropped one of the six. F6: the agent's
   refusal carries Docker's own error. F8: the installer test exempts exactly
   the functions that load the run-spec helper. F9: a failed Docker install
   says `daemon.json` keeps the setting. F4 (SETFCAP and FSETID fail quietly)
   is noted in `_site_run_spec.sh` and the plugin guide; nothing declared needs
   either. F7: no change. Gates: docker_multi_tenant 31, site_run_spec 166.
4. **The host's own services are out of reach (S6).** Traffic from a
   container to the host's own addresses (the bridge gateway, the public IP,
   other networks' gateways) passes through the host's **INPUT** chain, not
   `DOCKER-USER`, which handles only traffic passing through the host. Rules
   on the INPUT chain, for every site network's interface, allow replies to
   the proxy and the host's public web ports (80 and 443). Everything else is
   dropped. Without the 80/443 exception, two sites on the same box could not
   reach each other's public pages, or send each other Joinery Direct
   deliveries, which are HTTPS to the receiving site's domain. Confirm that
   nothing else on the host (the host agent, Apache's status page) is
   reachable from a site afterwards.
5. **Outbound blocks.** In `DOCKER-USER`, which is the right chain for
   traffic leaving the box:
   - port 25 to anywhere (`own_mail_server_sending` § 4: machines we create
     never send mail themselves)
   - the cloud metadata address, `169.254.169.254` and Linode's IPv6
     equivalent (S28). We pass no user-data to these boxes today, but nothing
     on a site should be able to ask.
   
   The host's own outbound 25 is blocked in OUTPUT.
6. **IPv4 and IPv6 alike.** A site's container has a private address of
   each kind and leaves through the host from the box's one IPv4 or one IPv6
   address (`node_outbound_and_transfer` WP2, which says why and what follows
   from it). Every rule above is written for both `iptables` and `ip6tables`
   on the host, and the gate checks each over both.
7. **Kernel fixes take effect promptly.** A kernel update does nothing until
   the machine reboots, and the shared kernel is the one wall left once 1–6
   are in place. A multi-tenant host reboots itself after a kernel update
   (unattended-upgrades `Automatic-Reboot`) in a nightly window. The window
   stays clear of the fleet backup window, 03:00 UTC plus two hours (S30).
   Containers come back on their own (`--restart unless-stopped`), and a
   site held stopped stays stopped (WP7). The host report's existing
   `reboot_required` turns amber when a box has gone more than a day without
   taking a pending reboot.

**Items 4, 5 and 7, and the walls of 1 and 6: built 2026-10-06.** All of it
is one file, `multi_tenant_host.sh` (install_tools, 1.0). `install` puts a
root-owned copy at `/usr/local/sbin/joinery-site-walls` and starts
`joinery-site-walls.service`, which loads the walls at every boot, before
Docker. It also writes the reboot policy. The ruleset is checked by `nft -c`
before anything is written. `install.sh docker --multi-tenant` (install.sh
3.00) runs it on both paths, after the remap check: a fresh Docker install, and
a host that already has Docker. A host whose walls fail to install is refused
before its agent joins. Run on the scratch Linode the same day: its walls were
removed and the real `install.sh docker --multi-tenant -y` put them back. The
probe gave the same result as before, `check` passed, and a second run
rewrote nothing. The fresh-Docker path is pinned by the docker_multi_tenant
gate and is a live check on a new box. The installation guide describes both
(installation.md, One-time setup).

**reviewer2's review, 2026-10-06, fixed in multi_tenant_host.sh 1.1:**
- B1. The walls failed open at boot: `Before=` is ordering only, so if the
  walls failed to load, Docker started every site without them and nothing
  noticed. Now a drop-in gives `docker.service` an `ExecStartPre` that runs
  `joinery-site-walls assert`, so Docker refuses to start, and docker.service
  shows as failed in the host report. It is not `Requires=`, because install
  restarts the walls unit, and a required unit's restart restarts Docker and
  every site with it.
- B2. The stock timer's `Persistent=true` made up a missed run at boot. On a
  cloud image with an old stamp, that meant an update, and now a reboot, in the
  day or in the middle of `install.sh`. The drop-in sets `Persistent=false`.
- N1. The drop-in turns on the nightly run itself (`APT::Periodic`). install
  adds unattended-upgrades if it is missing, and install and `check` ask
  `apt-config` what apt will actually do.
- N2. A re-run reloads the walls only when they changed or are missing, so
  their drop counts survive.
- N3. AWS's IPv6 metadata address is dropped beside Linode's.
- N4. `nftables.service` flushes the whole ruleset at start, reload and stop. A
  drop-in loads the walls again after each.

Proven on the scratch Linode:
- the upgrade through the real `install.sh`;
- Docker refusing to start with the tables deleted, with the reason in its
  journal: it reached its start limit and ended `failed` (docker.service and
  docker.socket in the failed units), then started once the walls were back;
- the walls surviving `nftables` start and stop;
- drop counts unchanged across a re-run;
- after a reboot, the walls loaded at 11 s and both sites healthy.

- **The walls are rules on the site bridges, not one rule per site.** They
  match `docker0` (a site not yet moved to a network of its own) and
  `jsnet*` (WP2's site networks, which pin their bridge names). So they hold
  for every site there is or will be, with nothing to rewrite when a site is
  added. `br-*` is not matched, since Docker's other networks are not sites.
- **They sit in two tables beside Docker's own.** `inet joinery_site_walls`
  covers IPv4 and IPv6 with one rule each, which is item 6. `bridge
  joinery_site_walls` is the second.
  - A packet that any table drops is dropped, so nothing depends on Docker's
    chains or their order. `DOCKER-USER` is not used.
  - WP3's per-site limits (`node_outbound_and_transfer`) will be a third
    table, `inet joinery_limits`, rewritten whole on each run. The walls are
    fixed policy (agreed with that session).
- **Input: a site's packets to the host.**
  - Replies pass: the proxy's requests to the site, and the host's reads
    through a published port.
  - IPv6 neighbour discovery passes, and so do ports 80 and 443.
  - Everything else is counted and dropped.
- **Forward: a site's packets through the host.**
  - Counted and dropped: traffic to another site's bridge, port 25 anywhere,
    `169.254.169.254`, and `fd00:a9fe:a9fe::1`.
- **Bridge forward: frames between two ports of one site bridge.**
  - Counted and dropped.
  - This is the wall between two sites on `docker0`. Docker 29 does not
    load `br_netfilter` (checked on the scratch box), so traffic between two
    containers on one bridge never reaches the inet family. Only the bridge
    family sees it.
  - So item 1's wall holds now, before WP2 gives each site its own network.
    WP2 adds the network itself, which WP3 and WP4 of that spec need.
- **Output: the host's own port 25.** Dropped, loopback apart.
- **Item 7.** `apt-daily-upgrade.timer` runs at 05:30 UTC (up to 30 minutes
  later, at random), after the backup window. Unattended-upgrades reboots at
  once when an update asks for it (`Automatic-Reboot-Time "now"`, with users
  logged in too). The `52joinery-multi-tenant-reboot` drop-in sorts after
  Ubuntu's own file.
  - `host_report` 1.8 adds `reboot_required_since`: the birth time of
    `/var/run/reboot-required`. `/run` is a tmpfs emptied at boot, and each
    later update rewrites the file in place, so the birth time is the first
    request.
  - The plane keeps it (JobResultProcessor 1.58), and the node page (overview
    1.42) shows when the reboot was asked for. The badge is grey for the first
    day and amber after.
  - A report without the time (an older node) reads as overdue, as before.
- **Gates.**
  - `multi_tenant_host` (46 checks) pins the ruleset rule by rule, the unit,
    the reboot policy and its window against the backup window, a refused
    ruleset installing nothing, and `check`. Eight mutations were caught.
  - `host_report` (124 checks) pins the new key and the birth time: a
    mutation reading the change time was caught.
  - `job_result_processor` (273 checks) pins intake.
- **On the scratch box (2 GB, remapping, `sitea` and `siteb` on `docker0`),
  2026-10-06.**
  - Before the walls: `sitea` reached the host's SSH on its gateway and on
    the public address, and `siteb`'s web server and PostgreSQL port.
  - After `multi_tenant_host.sh install`, all of those were blocked.
  - Still passing: 80 and 443 to the host (refused there, since the box runs
    no proxy, so the packets got through), the host's page requests to each
    site, the host's database tunnel, and outbound HTTPS and DNS.
  - Port 25 and `169.254.169.254` were dropped and counted.
  - A container on a test network built as WP2 builds them (`jsnet9`,
    `10.250.9.0/24` and `fd00:250:9::/64`):
    - it reached the internet over IPv4 and IPv6, and its DNS worked;
    - it was blocked from the host's IPv4 and IPv6 addresses (gateway and
      public) except 80 and 443;
    - it was blocked from `fd00:a9fe:a9fe::1`, from port 25 over IPv6, and
      from the `docker0` site, in both directions.
  - Docker's own isolation chains also drop traffic between bridges (ours
    counted none there), so that rule is a second wall, not the only one.
  - Port 25 out is also closed by Linode on this account, so the counters, not
    the timeouts, show our rule at work.
  - After a reboot: the walls were loaded 12 s into boot, Docker started at
    25 s, both sites were healthy, and the same probe gave the same result.
    With the bridge walls loaded before the containers, the two `docker0`
    sites cannot even resolve each other's hardware address ("no route to
    host").
  - Still to run (live verification queue): the `install.sh` call on a fresh
    box, a real unattended reboot after a kernel update, and the node page's
    badge.

What this still leaves open is one shared kernel. A kernel exploit escapes any
container. For sites on a multi-tenant host that is the accepted trade, and it is the same
one every low-cost container host makes. Sites that cannot accept it buy their
own machine, which the hosted tier already sells. The starter tier spec
states what that trade means for each kind of customer data.

### Many sites on one address (S23)

These are not leaks, but each must be dealt with:

- The agent channel's rate limit (6,000 an hour) is keyed by the connecting
  address, so a box's 13 agents (12 sites and the host) would share one
  bucket. Key it by the agent's identity.
- Joinery Direct's peer-lookup cap (60 a minute per connecting peer) is
  shared by a box's sites. Raise it or key it by the sending site.
- If one site's scanning gets the address blocklisted, all its neighbours
  are blocklisted with it. `hosted_abuse_response` is the defence.
- DNS publishes one record per node, A or AAAA by the address's type
  (`NodeDnsPlan`). A site's domain on a multi-tenant host needs both, pointing at the box's IPv4
  and IPv6, under the IPv4-and-IPv6 rule.


### WP6 — Changing a site's caps later

A site may need a bigger allowance, or an abuser needs a smaller one, without
a rebuild:

- memory and CPU: `docker update --memory --memory-swap --cpus` changes them
  live. PostgreSQL and PHP-FPM pick up the new memory at the next restart,
  which the step does.
- disk: `xfs_quota` changes the project limit live.

`install.sh site-limits SITENAME [--memory] [--cpus] [--disk]` does both and
writes the run spec (WP0). It is reached from the plane through a new
host-agent word, and offered on the node page as **Change limits** for a site
on a multi-tenant host.

### WP7 — Stop and start that stay stopped (S1)

The host agent's `container_health` recipe restarts any container that is not
running (`recipes/service_health.go`), and raises an incident about it. The
only per-container word today is `restart_container`, which runs
`docker restart`. So anything that stops a site on purpose, such as the end
of an unpaid grace period or a suspension, would be undone within a tick.

- New host words: stop and start one container.
- A host-side "held stopped" record per site, with who held it and why. The
  host report emits it, `container_health` skips a held site, and it survives
  a reboot (`--restart unless-stopped` already keeps a stopped container
  down).

### WP8 — A catch-all default site on every multi-tenant host (S24)

A request whose name the box does not serve falls to the first HTTPS site on
the box, so it shows another customer's site and certificate. A multi-tenant host
gets a default site that answers every unknown name with a plain page. The
same slot serves a "This site is suspended" page for a domain that has been
switched to it (`hosted_abuse_response`).

The proxy template has no error page, and a Docker host's sites are never
re-rendered after install. So this needs a template change, and a host word
that switches one domain's proxy site to the suspended page and back.

### WP9 — No mail stack in a site container

**Owner decision, 2026-10-05:** a Docker site's mail arrives through a relay,
and no site container carries Postfix or rspamd. This holds on every Docker
host, docker-prod included. A shared rspamd on the host was considered and
dropped: it would need a hole in WP5's wall to the host, every site's DKIM keys
in one store, and per-site verdict names, all to check mail that never reaches
the box.

Why the container's mail stack has nothing to do:

- **Inbound.** One box has one port 25, so on a box of several sites at most
  one of them could ever receive directly. Through a relay, the relay's rspamd
  checks the message and stamps the verdict before the site pulls it
  (`RelaySpoolConsumer` trusts that stamp), so the site's own rspamd never sees
  it.
- **Outbound.** Every sending provider is an API or an outside SMTP server
  (`includes/email_providers`). `PostfixProvider` is inbound only, so nothing
  sends through the container's Postfix.

What changes:

1. **The base image.** `install.sh server` installs Postfix when it builds the
   Docker base image. The image build skips it, and `BASE_IMAGE_VERSION` moves
   on, so every host builds a base without it.
2. **The mailbox host installer.** In a container, `install_email.sh` installs
   and starts nothing, says that mail arrives through a relay, and exits 0 so
   the start chain continues. A container built on an older base still has
   the packages in its image, but nothing starts them after its next restart,
   and its next rebuild drops them.
3. **Receive mode.** A container site is offered the relay only. Its receive
   mode resolves to relay, and its Setup page shows no listener, Postfix or
   port 25 rows.
4. **A relay a Docker site can use.** This is the hosted relay fleet (the
   starter tier, WP1 S17) or the deployment's own relay.
5. **docker-prod before the release.** Any site there receiving mail on its
   own port 25 (receive mode `direct`, a container publishing 25) moves to a
   relay first, or its inbound mail stops at the release.
6. **Gate.** A container start with the mailbox plugin active runs no Postfix
   and no rspamd. The base image has neither package. A container site's
   Setup page has no listener row.

**Built 2026-10-05.** A site knows it is a container from the installer's
`deployment_environment` record in its config, read by one helper,
`mailbox_site_has_mail_server()` (`receive_mode.php`); `install_email.sh` reads
the same record and exits 0 before touching anything. `install.sh server`
skips Postfix when `is_docker` (an image build), and the base is
`joinery-base` 2.1. With no relay row, a container's Setup topology is
`relay_needed` rather than colocated: fronted (provider SPF and DKIM, never the
box's address), with no MX or A record prescribed and each domain's MX row
saying a relay is needed. Its host layer is one row, **How mail reaches this
site**, red with no relay and green behind one; the HELO-name rows, the
Postfix, port 25 and rspamd rows, the Local mail listener box and its actions,
and the "receive directly" wording on relay Disable and Delete are gone for a
container. The provisioning check asks for an enabled relay. Found while
building it: `managed_domain_prepare` ran `provision_dkim.sh` (which needs
rspamd) in a container, and counted DKIM ready only for a TXT record, so a
provider that issues its key as a CNAME (SMTP2GO) would have kept the domain
waiting for a key forever; it now skips the local key in a container and
counts either record type. **Review (reviewer2, 2026-10-05, B1-B4 fixed):**
"no mail server" is not "needs a relay": mail that arrives by SMTP needs one
(`mailbox_needs_relay()`), while a webhook provider and an IMAP feed work in a
container unchanged, and a container with no receiving domain is only told it
will need a relay. **A container's relay hides nothing (owner decision,
2026-10-05, option A of three):** the address belongs to the whole box, and one
neighbour publishing it exposes every site on it (on docker-prod,
`developers.getjoinery.com` points at the box while `getjoinery.com` sits behind
Cloudflare). A site that needs a hidden address runs on its own server. So the
hidden-origin rules are a bare-metal relay's only (`originHidden()`). Rejected:
the bare-metal rules in a container (they would hold SMTP2GO, Joinery services
and plain SMTP back from compose for a guarantee a shared box cannot give), and
a per-host "this host hides its address" switch (nothing keeps a later site on
the host from publishing the address; it can be added on top of this later): a container's SPF names its
providers and never the box, and needs nothing when they send with their own
return-path domain (SMTP2GO, Joinery services); compose is never held for the
origin-leak probe (`OutboundTransport` 1.7); Direct is served from the site's
web host; `checkOriginHidden` does not run. A disabled relay turns the host row
red. A managed domain's mail step publishes a plan with no MX but leaves the
step open (`ProvisionManagedDomains` 1.7), so the MX goes out once a relay
exists. Also from the review: a sending setting aimed at the box's own mail
server gets its own red row in a container (the decommission guardrail reads
the same check, now covering a forwarding SMTP host too), and the mail
identity box and the relay card's tunnel wording are gone for a container. All
eight docker-prod configs record `deployment_environment = docker`.
**docker-prod (read 2026-10-05):** the mailbox
plugin is active on none of its eight sites (getjoinery has the files,
inactive), no container publishes port 25 and none runs Postfix or rspamd, so
nothing moves before the release; every image there still carries the Postfix
package until its next rebuild. **On the scratch Nanode:** base 2.0 has
`postfix` and `postfix-pgsql`; base 2.1 has neither, and no rspamd or
`/etc/postfix/main.cf`. In a site rebuilt on 2.1 with the new plugin,
`install_email.sh` printed that the site receives through a relay, exited 0 and
started nothing; the Setup checks read no mail server, receive mode relay,
topology `relay_needed` and the one red row; after a restart no Postfix or
rspamd process ran and the site used 90 MB. A rebuild does not refresh the code
volume, so a site rebuilt onto 2.1 before its code upgrade still runs the old
installer, which apt-installs Postfix and rspamd into the container at every
start until the upgrade lands. No site in the fleet has the mailbox plugin
active in a container, so no release ordering is needed today. The full chain
(a signed release's installer run by the container start) is in the live
verification queue: a hand-copied plugin fails the release manifest check, so
the start skipped it on the box.

## Host-agent words this needs

- stop and start one container, with the held-stopped record (WP7)
- `site-limits` (WP6)
- switch a domain's proxy site to the suspended page and back (WP8)

## Testing

A gate on a scratch box, with three small sites on a multi-tenant host:

| Check | Pass |
|---|---|
| a site allocates past its memory budget | only that site's process is killed; the supervisor brings it back; the others answer throughout |
| PostgreSQL's postmaster, PHP-FPM's master or cron is killed | the supervisor restarts it, and the health check notices |
| a site spins every core | it stays at its ceiling; a neighbour's page time stays within 2× its idle figure |
| a fork bomb in a site | stops at the pids limit; the host stays responsive |
| a site fills its allowance | uploads and stored mail are refused in plain words; the database keeps running; a neighbour can still write; `df` inside shows the allowance |
| a site changes the XFS project of its own file | refused |
| a site at 95% of its allowance is upgraded | the upgrade succeeds |
| a site is rebased with caps | every cap is still in force afterwards |
| site A connects to site B's 5432 and 80 | refused |
| site A connects to the host's address | refused, apart from the proxy's reply path and the public 80/443; checked over IPv4 and IPv6 |
| site A asks the cloud metadata address | refused |
| a visitor loads a page | the site logs the visitor's real address, not the gateway |
| the parser jail runs a parse | it cannot open a socket, and runs as the jail user |
| a site runs with only the six capabilities, and its agent upgrades it | the upgrade and its package installs succeed; the site is healthy after |
| a site on a `--multi-tenant` host is rolled back from a rebase | the old PostgreSQL writes its log and starts |
| the host agent removes a site on a `--multi-tenant` host | it reads the site's config under Docker's remapped root and asks its operator |
| the host reboots | pool mounted, every allowance enforced, every site back up, a site held stopped still stopped |
| the pool fails to mount at boot | Docker does not start |
| a site sends mail to an outside mail server on port 25 | dropped at the box; mail through SMTP2GO still works |
| a site sends a Joinery Direct delivery to a neighbour on the same box, and to a site elsewhere | both arrive |
| a request for a name the box does not serve | the catch-all page, never another customer's site |
| `site-limits` raises a disk allowance | the full site can write again with no restart |
| the 256 MB site runs upgrade, `update_database`, backup and photo upload | no OOM kill |
| a site is held stopped, then two health-check ticks pass | it stays stopped; no incident is raised |

## Deferred: a separate kernel per site (gVisor)

Reference only. The owner deferred this on 2026-10-04.

gVisor (`runsc`) is a Docker runtime that gives each container a kernel of
its own, running in user space. A neighbour would then need a gVisor bug on
top of a Linux kernel bug, which brings the wall between sites much closer to
a dedicated machine's. It needs no nested virtualization, so it runs on an
ordinary cloud instance. Firecracker or Kata, by contrast, need nested
virtualization, which ordinary Linode instances don't offer.

What it would cost, all unmeasured:

- **Speed.** System calls are slower, and PostgreSQL and file uploads use a
  lot of them. Expect something like 10–30% on disk-heavy work.
- **Memory.** Each site's sandbox has some overhead, which could cost a site
  or two per box.
- **Compatibility.** Apache, PHP-FPM, PostgreSQL, cron, the parser jail and
  the site agent all have to be proven under it. So do the disk quotas in
  WP4, since gVisor has its own filesystem layer.

To pick it up: run WP1's measurements on one multi-tenant host with
`--runtime=runsc`, and compare sites per box and page times against the same
box without it.

## Not covered

- **Disk speed.** One site doing heavy reads and writes slows the others.
  There is no per-site I/O limit here. Cgroup I/O weights need a scheduler the
  loop device does not use. If WP1 shows this matters, it gets its own spec.
- **Outbound traffic.** Connection limits, a UDP drop and a speed ceiling
  for every install, with a transfer watch per machine, are spec
  `node_outbound_and_transfer`. A multi-tenant box's lower per-site ceiling
  and each site's count against its monthly share are spec
  `site_outbound_limits`. Until then a site's outbound traffic is
  only measured (WP1).

## Docs to update when this lands

- `docs/installation.md` § Docker Deployment: the multi-tenant host, its flags
  (`--disk-pool`, `--multi-tenant`, `--cpus`, `--disk`) and its limits and isolation. The
  isolation bullets describe both kinds of box.
- `plugins/server_manager/docs/overview.md`: the host's cap fields, **Change
  limits**, and holding a site stopped.
- `plugins/mailbox/docs/overview.md` (WP9, done 2026-10-05): § A site
  container has no mail server replaces § Container persistence and
  § Advanced: multi-site host relay; § Firewall and § Server Setup name the
  server with its own mail stack; § The receive-mode choice matches the code
  (it still described the removed choice card).
- `docs/deploy_and_upgrade.md` (WP9, done): `joinery-base:2.1` carries no mail
  server. (WP2, done 2026-10-06): § PostgreSQL and PHP memory, the pool and
  connection sizing.
- `docs/installation.md` (WP2, done 2026-10-06): § What keeps a site running,
  the supervisor, its health check and the hold.

## Found by the review, outside this spec's scope

- **The fleet backup pass loses its per-machine rule when a verification is
  due (S22).** In `FleetBackupRun::pass()`, `$busy` holds the map of busy
  machines (line 82), and line 144 reuses the name for a string. From then
  on the one-backup-per-machine rule stops applying for the rest of that
  pass, and `$busy[$machine] = …` throws a TypeError after the job has
  already been created. This is committed code (49fbf0f6 and earlier). It
  matters most on a box with 12 sites. Fix: a separate variable name, and a
  test with a verification due and two sites on one host.
