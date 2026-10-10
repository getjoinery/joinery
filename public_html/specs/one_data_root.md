# One data root: every install keeps its data in one place, on a filesystem of its own

**Status:** BUILDING. Drafted 2026-10-07 from an inventory of the installers;
O1 and O3 settled by the owner 2026-10-09 (D7, D8). WP1 BUILT 2026-10-10,
box-proven, uncommitted. WP2 next. Stands alone; `hosted_data_promise_disk_encryption`
stands on it and is deliberately NOT part of it — this spec never mentions a
key.

## The goal, in one sentence

**On every install — bare metal, a site container, a multi-tenant Docker host
— everything that is data lives under one mount point, on a filesystem of its
own, and every service that needs it waits for it.**

## Why, without encryption in the picture

- **Sizing and growth.** One place to measure, cap and grow, instead of a site
  root, a Postgres directory, a mail spool and a Docker directory each filling
  the root disk on their own schedule.
- **Backups and moves.** "The data" is one tree. A site copy, a host move or a
  restore names one path.
- **Sameness.** The three install shapes today keep data in three layouts. One
  layout means one set of scripts and one set of answers.
- **Later.** A filesystem of its own, in a file or on a device, is the one
  shape that can be encrypted underneath without anything above it changing.
  That is the encryption spec's concern; this spec only makes it possible.

## What exists (measured 2026-10-07)

| Install shape | Where data lives today | Mounted how |
|---|---|---|
| Bare-metal site (`install.sh`, `_site_init.sh`) | `/var/www/html/<site>/{uploads,backups,storage,static_files,logs,cache}`; Postgres at `/var/lib/postgresql/<major>/main`; Postfix `/var/spool/postfix`; rspamd `/var/lib/rspamd` | The root disk, scattered |
| Site container on a Docker host | Named volumes: `postgres, config, uploads, static_files, backups, cache, logs, sessions, deploy` under Docker's data root | Docker's data root |
| Multi-tenant Docker host (`docker_disk_pool.sh`) | `/var/lib/docker` is an XFS filesystem **in a fully allocated file**, `/var/lib/joinery-docker-pool.img`, with project quotas, mounted through fstab; Docker and containerd wait for it. Host state at `/var/lib/joinery/host` | **Already the shape this spec wants** |

What is *not* data and stays on the root disk everywhere: the code tree
(`public_html`, `maintenance_scripts`), `config/` (including the database
credentials), the agent and its credential, the operating system.

## The design

### D1. The mount point

`/srv/joinery` on every install. FHS names `/srv` for "data for services
provided by this system", which is exactly what this is. Under it:

```
/srv/joinery/
  postgresql/          bare metal: the cluster's data directory
  sites/<site>/        bare metal: uploads, backups, storage, static_files, logs, cache
  mail/postfix/        bare metal: the Postfix queue
  mail/rspamd/         bare metal: rspamd state
  docker/              any Docker host: Docker's data-root (volumes, images, layers)
```

A site container needs nothing: its volumes are under `docker/`. The paths
the platform uses today keep working — `/var/www/html/<site>/uploads` becomes
a symlink to `/srv/joinery/sites/<site>/uploads`, Postgres's `data_directory`
and Postfix's `queue_directory` are configuration, and Docker's `data-root` is
one line of `daemon.json`. No PHP changes: code addresses the site root as it
always has.

### D2. A filesystem of its own, in a file or on a device

The data root is its own filesystem, never a directory on the root disk:

- **On a device the operator or provisioner names** (a second cloud disk, a
  partition): formatted and mounted there.
- **Otherwise, in a file**: `/srv/joinery.img`, fully allocated
  (`fallocate`), so it can never promise space the disk does not have, read
  through a loop device with direct I/O. This is `docker_disk_pool.sh`'s
  pool, generalised; it works the same on every provider and on bare metal.

XFS, because the multi-tenant host needs project quotas and one filesystem
choice is simpler than two. `docker_disk_pool.sh`'s quota work keeps its job
and operates on `/srv/joinery/docker`.

One script, `joinery_data_root.sh`, in `maintenance_scripts/install_tools/`:

```
joinery_data_root.sh create [SIZE] [DEVICE] make it (file of SIZE, default per D7, or format DEVICE), fstab, mount
joinery_data_root.sh check                  mounted, right filesystem, right options; exit 1 if not, 2 if this host has none
joinery_data_root.sh grow [SIZE]            a file grows in place (to SIZE, or by D7's step); a device grows after the provider does
joinery_data_root.sh status                 size, used, what is under it, and whether it can still grow
joinery_data_root.sh tick                   the converger's call: check, keep the units, grow when D7 says so
```

The fstab line always carries `prjquota`: it costs nothing where no quota is
set, and one mount line everywhere is the point. A host's data root is
recorded in `/etc/joinery/data_root` (what backs it), on the root disk; a host
without that file has no data root, which is a different answer from one that
is declared and not mounted.

No node in the fleet carries the old `/var/lib/docker` pool (every host
report said `disk_pool: none`, 2026-10-09; only scratch boxes ever had one), so
`docker_disk_pool.sh` moves onto the data root outright: its pool IS the data
root, mounted at `/srv/joinery`, and Docker's `data-root` is
`/srv/joinery/docker`. There is no second layout to keep working.

### D3. Services wait for it

A systemd target, `joinery-data.target`, reached when `/srv/joinery` is
mounted. PostgreSQL, Docker and containerd, the web stack, Postfix and rspamd
are ordered after it (`After=` + `Requires=` drop-ins written by the
installer). A box whose data root is absent starts the agent and nothing
else, and `check` says why. This is what `docker_disk_pool.sh` already does
for Docker alone, applied to every consumer.

The drop-ins are written only on a host that has a data root (a `Requires=`
naming a target that does not exist would stop the unit starting), and kept
by `tick` on every converge, so a PHP-FPM version installed later is ordered
after the data root too. The host converger itself refuses to run any
installer while a declared data root fails `check`: every installer it would
run writes to a host whose data is missing.

### D4. Install shapes

- **New bare-metal site:** `install.sh` creates the data root before Postgres
  is initialised, and every data directory is born under it.
- **New Docker host (any kind):** the pool becomes the data root; Docker's
  `data-root` is `/srv/joinery/docker`; the multi-tenant quota work is
  unchanged in substance.
- **Site container:** nothing to do.
- **Self-hosted, operator says no:** not offered. A filesystem of its own in a
  file costs nothing an operator would notice, and one layout is the point.

### D5. Existing installs

One procedure, the same everywhere, run by the operator (or the management
node on a managed node) with downtime proportional to data size:

1. `create` the data root beside the live data.
2. Stop `joinery-data.target`'s consumers.
3. Copy each data location into its place under `/srv/joinery` (rsync, checked).
4. Point each consumer at the new place (symlink, `data_directory`,
   `queue_directory`, `data-root`); on a multi-tenant host the existing pool
   file is renamed and remounted at `/srv/joinery`, no copy.
5. Start the target. `check` passes. The old locations are removed only after
   a `check` on the next boot.

`joinery_data_root.sh migrate` does 1–5 and refuses to start if the root
disk cannot hold both copies for the duration.

### D6. Upgrades stay on the code's disk

An upgrade duplicates only the code, never data. It unpacks the new code
beside the live tree, then swaps them by moving; on one filesystem each move
is a rename and costs nothing. Today a bare-metal site without a deploy
volume stages in `uploads/upgrades/` and downloads into `uploads/`. Once
`uploads` lives on the data root, that would unpack the code on one
filesystem and move it onto another: every file copied instead of renamed,
a slower, less atomic swap, and the free-space check measuring the wrong
disk.

So staging and the downloaded archives move out of `uploads/` to the site
root (`/var/www/html/<site>/upgrades/`), next to `public_html` and
`public_html_last`, on the root disk. Code is not data. A site container is
unchanged: it stages on its `deploy` volume, which already holds
`public_html_last`. `utils/upgrade.php` and `DeploymentHelper::deployRoot()`
change together, and the change ships in a release before any node is
migrated, so no upgrade ever stages on the data root.

The data root therefore does not make upgrades smaller or larger: they need
about two copies of the code tree plus the archives on the root disk,
before and after.

### D7. Size: modest at first, grown automatically (O1, settled 2026-10-09)

A data root in a file starts modest and grows as it fills. XFS can grow but
never shrink, so space handed to the data root never comes back to the root
disk; a large first size would be a choice nobody can undo, and it would
squeeze the root disk that upgrades unpack on (D6).

- **First size**, when `create` is given none: the larger of 16 GiB and a
  quarter of the root disk, but never more than the root disk can give above
  its reserve. Under 4 GiB available is refused.
- **The root disk's reserve**: the larger of 6 GiB and 15% of the root disk
  is never given to the data root. It sits above the agent's `disk_headroom`
  floor (10% / 5 GiB), so growing the data root can never be what opens a
  disk case on the root disk.
- **When it grows**: on the converger's tick (every minute; one `df`), when
  the data root has less than the larger of 8 GiB and 20% of its size free.
  That is above the same floor applied to the data root, so it grows before a
  case would open.
- **By how much**: to the largest of a quarter more than it is, its used
  space plus 12 GiB, and its used space over 0.7, rounded up to a whole GiB;
  then cut down to what the root disk can give above its reserve. Under 1 GiB
  to give is no growth.
- **When it cannot**: `tick` says so in the converger's transcript and
  `status` says so; nothing else is built. The data root then fills like any
  disk, and the agent's `disk_headroom` recipe opens its case when it reaches
  the floor (host_report's `disk_pool` reports the data root's figures), as
  for any full disk. Disk space is the operator's; there is no trend notice.
- **Growing** is online: `fallocate` to the new length, `losetup -c` so the
  loop device sees it, `xfs_growfs`. A data root on a device grows only after
  the provider has grown the device; `tick` runs `xfs_growfs` when the device
  is larger than the filesystem, and never asks the provider for more.

### D8. Logs live in the data root (O3, settled 2026-10-09)

A site's `logs/` is data, under `/srv/joinery/sites/<site>/logs`: logs fill
disks, and they belong with the rest. The agent's own log, the converger's
record and `/var/lib/joinery/host` stay on the root disk, so a box whose data
root is the thing that failed can still say why.

## Work packages

- **WP1 — `joinery_data_root.sh`** with `create / check / grow / status /
  tick`, the target and the drop-ins; the converger's gate and growth tick
  (D3, D7); `docker_disk_pool.sh` rebased onto it; `host_report`'s
  `disk_pool` reads the data root, so the agent's floor covers it with no
  agent change; the support bundle carries the script.
  `installer_contract_test` pins the layout; `host_converger_gate.sh` pins
  that the converger refuses to run installers when `check` fails.
  **BUILT 2026-10-10.** Gates: `joinery_data_root` (43, new),
  `docker_disk_pool` (39), `host_converger` (+6), `host_report`,
  `docker_multi_tenant`, `installer_contract` (+37). Box-proven on a scratch
  Nanode (Ubuntu 26.04, systemd 259; deleted after): created at 2G, the first
  tick grew it online to 13G in 0.17 s with data intact; a second tick was
  silent; at the root disk's reserve it said "cannot grow" once; a consumer
  (Apache) whose package was installed after its drop-in still waited for the
  target; a clean reboot brought the mount, direct I/O and Apache up in that
  order; with the file missing the box booted, Apache stayed down and `check`
  named the cause, and starting Apache once the file was back pulled the mount
  in; the runner as root refused `--machine --only` while unmounted and ran
  once mounted; `docker_disk_pool.sh create` reused the data root, Docker's
  root and volumes landed under `/srv/joinery/docker` and Docker and
  containerd required the target; an XFS project limit of 100M held on it.
  install.sh loads `_docker_daemon_json.sh` only when it edits daemon.json, so
  the base image build carries no new file.
  **Reviewed by reviewer2 2026-10-10: NOT VALID, F1-F4 bugs, F5-F9 smaller; all
  nine fixed.** F1 `tick` exits non-zero only for not-ready (failed growth or
  reload is said and absorbed); F2 both installer job processors go red with
  the reason, and the admin notice names `data-root-not-ready`; F3 both gates
  skip as root and `docker_disk_pool.sh` refuses its test root as root; F4 the
  daemon.json loader fails instead of recursing without its helper; F5 docs
  name the half-configured-package case; F6 first size in whole GiB; F7 a
  trailing slash in data-root accepted, an unused SIZE said; F8 a device read
  by its UUID path; F9 `status` bounds each `du` at 20 s. Re-review VALID
  2026-10-10; its nit N1 (create says what to run when its units cannot be
  written) fixed.
- **WP2 — New installs born on it:** `install.sh`, `_site_init.sh`, the
  Docker host path. Upgrade staging moves to the site root (D6), released
  before WP3 runs anywhere.
  Also in WP2: the agent's `disk_headroom` reason text names "the Docker
  disk pool"; once bare-metal sites have a data root it should say "the data
  root" (joinery-agent, text only; host_report's key stays `disk_pool`).
- **WP3 — `migrate`** for the three existing shapes, proven on a scratch box
  of each.
- **WP4 — Fleet:** managed nodes migrated one at a time from the management
  node, dev first.

## Open items

- **O1 — SETTLED 2026-10-09.** Modest at first, grown automatically: D7.
- **O2 — SETTLED 2026-10-07.** `/var/lib/joinery/host` stays on the root
  disk: the agent needs it before the data root is up, and the encryption
  spec needs the agent to run with the data root closed. The `host/` line in
  D1 is withdrawn.
- **O3 — SETTLED 2026-10-09.** Under the data root, with the agent's own log on
  the root disk: D8.
