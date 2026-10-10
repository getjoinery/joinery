# One data root: every install keeps its data in one place, on a filesystem of its own

**Status:** IMPLEMENTED 2026-10-10. Drafted 2026-10-07 from an inventory of
the installers; O1-O4 and F5 settled by the owner. WP1 (586d2ec3) and WP2 with
D6 (5997795d) committed, box-proven. WP3 `migrate` box-proven on bare metal, a
Docker host and a multi-tenant host, reviewer2 VALID. WP4's tooling (agent
1.69.0 word, node page action) built and gate-tested. Not yet run: the release
carrying D6, WP2, WP3 and agent 1.69.0, and WP4's moves (dev first, then
getjoinery, Joinerydemo, jeremytunnell.com, docker-prod); both are in the live
verification queue. Stands alone; `hosted_data_promise_disk_encryption` stands
on it and is deliberately NOT part of it — this spec never mentions a key.

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
the platform uses today keep working: each one is a **bind mount** of its
place under the data root (O4, settled 2026-10-10), and Docker's `data-root` is
one line of `daemon.json`:

| Under `/srv/joinery` | Mounted at |
|---|---|
| `postgresql` | `/var/lib/postgresql` |
| `mail/postfix` | `/var/spool/postfix` |
| `mail/rspamd` | `/var/lib/rspamd` |
| `sites/<site>/<dir>`, for uploads, static_files, storage, backups, logs, cache | `/var/www/html/<site>/<dir>` |

No PHP changes, no package configuration changed: code, `apt`, `pg_*` and
Postfix address the paths they always have.

One stated exception: Docker 29 keeps images, and each container's writable
layer, in containerd's store (`/var/lib/containerd`), not under `data-root`.
They stay on the root disk. Images are code, rebuilt or pulled again; a site's
data is its volumes, which are under `docker/`. (Found moving a Docker host in
WP3; reviewer2 N2.)

Why mounts and not symlinks: everything that walks a site directory — the
backup engine (tar), restore, the site census, the permission sweep — treats a
symlink as one small file and never goes inside it. With symlinks a backup
would silently stop carrying uploads, and a restore would rebuild the folders
on the root disk. A mounted folder is an ordinary folder to every tool, the
same shape a site container's volumes already are. The cost: six mounts per
site, kept by `joinery_data_root.sh`, and a file moved between two of them is
copied rather than renamed (PHP's `rename()` does that itself). While the data
root is down the folder underneath each mount is root's, mode 000 and
immutable, so nothing writes to the root disk in its place.

`joinery_data_root.sh bind REL TARGET` makes one: the directory under the data
root, a mount unit (`Requires=srv-joinery.mount`), and a line in
`/etc/joinery/data_binds`; `joinery-data.target` requires every recorded mount,
so a consumer waits for its folders as well as for the filesystem. `bind`
refuses a TARGET that holds data: moving data is `migrate`'s (WP3). `unbind
TARGET` takes one away and leaves its data where it is. `check` fails while
any recorded mount is not in place, and `tick` starts one that is not.

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

- **New bare-metal site:** `install.sh server` creates the data root and binds
  Postgres, Postfix and rspamd onto it before their packages are installed;
  `_site_init.sh` binds the site's six folders before it makes anything in
  them. A host that already holds data in any of those places (a `server` run
  again on an existing box) keeps it where it is and says so: moving it is
  `migrate`'s (WP3).
- **New Docker host (any kind):** the pool becomes the data root; Docker's
  `data-root` is `/srv/joinery/docker`. Every new Docker host gets one, not
  only a multi-tenant one. A site's disk allowance still needs a multi-tenant
  host (user-namespace remapping), and `docker_disk_pool.sh allow` asks for it
  itself, since a data root no longer says the host is multi-tenant.
- **Size or device:** `install.sh server|docker --data-root=SIZE` gives the
  first size, `--data-root=/dev/X` a device; without it, D7's first size.
- **Site container:** nothing to do.
- **Self-hosted, operator says no:** not offered. A filesystem of its own in a
  file costs nothing an operator would notice, and one layout is the point.

### D5. Existing installs

One procedure, the same everywhere, run by the operator (or the management
node on a managed node): `joinery_data_root.sh migrate [SIZE|DEVICE]`. The
downtime is the time to copy the data once.

1. Find what moves: each place of D1's table that is on this host and not
   mounted from the data root yet (a site is a directory under
   `/var/www/html` with `config/Globalvars_site.php`; a companion test site
   moves its logs), and Docker's data-root when it is not `/srv/joinery/docker`.
2. Refuse, with every reason at once and nothing changed: a place that is a
   symlink or on another filesystem, a cluster whose `data_directory` is
   outside `/var/lib/postgresql`, a site holding its upgrade lock, a data root
   that cannot hold the data with room, a root disk that cannot hold it twice.
3. `create` the data root when there is none: the largest of D7's first size,
   the data plus 12 GiB and the data over 0.7, as far as the root disk gives.
4. Hold every host converger off (their runner locks), stop every running
   consumer and cron, and wait (two minutes at most) for anything still
   holding a file there.
5. Copy each place (`rsync -aHAXS --numeric-ids`), then compare it with its
   original, nanosecond times included; nothing is switched until every copy
   matches.
6. Switch: each original goes aside to `/srv/joinery.old` (a rename on the
   root disk), each path becomes a bind (D1), Docker's `data-root` is set;
   `check` passes; the services start.
7. Anything failing before the services start puts every original back,
   removes the copies and starts the services: a move is whole or not at all.
8. The originals are removed by the first `tick` on a later boot whose `check`
   passes (the boot is recorded in `/etc/joinery/data_root_migrated`): a
   reboot is the proof that the data root comes up on its own.

The pool-backed multi-tenant layout this section once named needs no step: no
node carries it (D2).

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
  And `disk_usage.sh`, which walks with `du -x` and so stops at a mount,
  reports the data root's own figures and tree beside the root disk's.
  **D6 BUILT 2026-10-10:** `upgrade.php` 1.13 stages in `SITE/upgrades/` and
  downloads to `SITE/upgrade_archives/` (or the deploy volume's), asks the
  deploy root for 3x the live code, and after a deploy empties the archives
  and removes what older releases left in `uploads/`; `backup_files.sh` 1.5.2
  and `SiteCensus` 1.3 leave both out, and neither counts toward the data's
  identity (staging is made anew by every upgrade).
  **WP2 BUILT 2026-10-10.** `joinery_data_root.sh` 1.2 (`bind`, `unbind`;
  check/tick/status know the binds), `install.sh` 3.09 (`server` makes the
  data root and binds Postgres, the Postfix queue and rspamd before their
  packages; `docker` makes it on every new Docker host; `--data-root=SIZE|DEVICE`
  replaces `--disk-pool`), `_site_init.sh` 3.13 (six site folders, the test
  site's logs), `docker_disk_pool.sh` 2.2 (`can-cap`: the pool and user-id
  remapping, asked by `allow`, `install.sh site --disk` and `site_limits.sh`
  1.1), `disk_usage.sh` 1.1 (`data_root`: its figures and tree to depth 3;
  JobResultProcessor 1.73 and job_detail 1.14 carry and show it), agent 1.68.1
  (`disk_headroom` says "the data root"). Gates: `joinery_data_root` 76,
  `docker_disk_pool` 43, `disk_usage` 42, `site_limits` 21, `upgrade_cleanup`
  32, `installer_contract` 867; `db --changed` 88 suites green.
  Box-proven on two scratch Linodes (Ubuntu 26.04, 2 GB; deleted after) from
  the 0.8.475 archive with this tree's scripts laid over it. Bare metal:
  `install.sh server` made a 16 GiB data root and bound the three paths;
  PostgreSQL 18's cluster and Postfix's queue were made on it by their own
  packages and ran; `install.sh site --with-test-site` bound the six folders
  (www-data 770, as the permission sweep sets them) and the test site's logs;
  a file in uploads/ was in `backup_files.sh --part data`'s archive;
  `disk_usage.sh` reported the data root's tree; a reboot brought every mount
  (direct I/O on) and every service back, Apache after the target; with the
  file missing the box booted, PostgreSQL, Apache, PHP-FPM and Postfix stayed
  down, `check` named the file, and the folders underneath were mode 000 and
  immutable (root's own touch refused); with it back, starting the services
  pulled the mounts in. Docker host: `install.sh docker --multi-tenant` with
  no size made a 16 GiB data root, Docker's root at
  `/srv/joinery/docker/100000.100000`, `can-cap` 0; `install.sh docker` run
  again on it reused everything and exited 0.
  Not box-tested: a device given as `--data-root=/dev/X` through install.sh
  (the tool's device path is gate-covered), `unbind` on a live mount.
  Found on the box, not this WP's: B1 (todo memory) a companion test site's
  own vhost carries a test site of its own, so `<site>_test_test` directories
  (and now its logs bind) are made.
  **Reviewed by reviewer2 2026-10-10: NOT VALID, F1-F2 blockers, F3-F8 notes.**
  F1 `unbind` stopped every consumer (stopping a mount the target still
  required stops the target): the record goes and the units reload before the
  mount stops; box-tested on a live mount, PostgreSQL, Apache, PHP-FPM and
  Postfix stayed up. F2 a bare-metal `remove_account.sh` emptied the mounts and
  stopped at the first mountpoint: `joinery_data_root.sh remove-site NAME`
  takes a site's mounts and its data on the data root first (refused while the
  data root is down); box-tested, six mounts and the data went and the
  directory was removed. F3 incrementals pass `--no-check-device` (a loop
  device renumbered at boot re-dumped the data). F4 `unbind` leaves the path
  an ordinary folder with its place's owner and mode. F6 `restore_project.sh`
  1.6.1 will not move a site with mounts aside; it says to restore in place.
  F7 the upgrade transition read as harmless; `uploads/.upgrade.lock` stays
  where it is (the runner and older upgrade.php must agree on it across
  versions, and it is an empty file). F8 restore_chain and binds: fine.
  F5 (a stale bind record holds every consumer down) SETTLED 2026-10-10 by the
  owner: kept. A bare-metal host runs one site, so it holds down only that site
  (or, for a test site's logs, its main site), and only after a hand deletion
  under /srv/joinery; the consequence is in installation.md. The box run also found B2 (bare-metal
  remove_account hangs at dropdb's password prompt) and B3 (a converger run
  racing a removal recreates the site's cache/), both pre-existing, todo memory.
  Re-review VALID 2026-10-10. Its notes N1 (remove_account warns when the tool
  is missing on a host with binds) and N2 (remove-site reloads once for all of
  a site's mounts) done; N3 (remove_account runs a sibling script the primitive
  does not verify, as remove_site_certificate.sh already does) noted.
- **WP3 — `migrate`** for the three existing shapes, proven on a scratch box
  of each.
  **WP3 BUILT 2026-10-10.** `joinery_data_root.sh` 1.3 `migrate [SIZE|DEVICE]`
  (D5): the places of D1 that are here and not on the data root, and Docker's
  data-root; every refusal said at once before anything changes; the data
  root made to hold the data; converger locks held, running containers stopped
  by Docker, then the consumers and cron (containerd last); each place copied
  (`rsync -aHAXS --numeric-ids`, nanosecond times) and compared before any is
  switched; originals renamed into `/srv/joinery.old`; a failure before the
  services start puts everything back; the first tick on a later boot with
  check passing removes the originals. It runs in its own systemd unit
  (`joinery-data-root-migrate.service`, log `/var/log/joinery-data-root-migrate.log`),
  so a caller that dies or times out ends only its transcript, and refuses
  inside a container. Gates: `joinery_data_root` 115 (+39), `installer_contract`
  877 (+6). Docs: installation.md, "Moving an existing host onto it".
  Box-proven on three scratch Linodes (Ubuntu 26.04, 2 GB; test account; deleted after),
  each installed the old way from the 0.8.475 archive, with a marker file in a
  site's uploads and a marker row in its database:
  - Docker host, one site container: moved in 5 s (0.2 GiB; Docker 29 keeps
    images in containerd's store, which stays on the root disk); daemon.json
    `data-root` set, every other key kept; site healthy, markers read back;
    after a reboot everything came up and the first tick removed the originals.
  - Multi-tenant host (userns-remap), one site container: moved in 44 s
    (1.6 GiB); `100000.100000` kept root:100000 710, volumes their remapped
    owners; `docker_disk_pool.sh check` and `can-cap` both 0 after (the host can
    now cap a site's disk); reboot and tick as above.
  - Bare metal, a site with its test site: 16 folders plus PostgreSQL, Postfix
    and rspamd moved, the web down 12 s; owners and modes kept; reboot: every
    mount, direct I/O, the target and all six services up, check 0, tick removed the
    originals. A tmpfs mounted inside `uploads/` was refused before anything
    stopped; the same refusal reached only after stopping (the earlier build)
    started all six services again.
  Found on the boxes and fixed: stopping Docker and containerd in one
  transaction left the container running (Docker could not reach containerd to
  stop it); the post-stop mount check caught it before a copy, and containers
  are now stopped by Docker first. rsync leaves a directory time that matches
  to the second, which the nanosecond compare flagged; the copy uses the same
  window. XFS shows each ACL a second time as `trusted.SGI_ACL_*`; those two
  names are left out of the copy and the compare (`-A` copies the ACL).
  Not box-tested: `migrate DEVICE`; a move large enough to meet the two-minute
  holder wait.
  **Reviewed by reviewer2 2026-10-10: NOT VALID, F1; F2-F7 smaller; all seven
  fixed.** F1 a converger run that starts during the move waits on the lock
  with the site's `logs/host_converger.log` open, so the holder wait failed:
  the converger's timer and path stop first, and a run waiting on the lock is
  stopped (it changes nothing before the lock); both start again after, or on
  a put-back. F2 an unreadable boot id is refused before anything changes, and
  forget_old never acts on `unknown`. F3 tee ignores TERM, so a stopped move
  logs its put-back. F4 the stopped units, logrotate and apt-daily-upgrade are
  masked (`--runtime`) for the move and unmasked before the starts, and holders
  are checked again just before the switch. F5 an empty place the copy filled
  is removed on a put-back. F6 a folder a site lacks is made with a sibling's
  owner and mode. F7 the originals are removed with `--one-file-system`. Gates:
  `joinery_data_root` 118, `installer_contract` 878.
  Re-review: F1-F7 taken; F8 (a partial mask was not undone on a put-back:
  the mask list is recorded before the mask command) fixed; N4 the converger's
  service is masked too; N5 the log names the unmask command before masking; a
  companion test site's lacking logs take its site's logs' owner. Also: a unit
  stops only when data it keeps moves this time (a site added since an earlier
  move stops the web stack and cron, never PostgreSQL). Box: a second site
  moved with the fixed build (timer back, no masks left, a lacking folder
  www-data 770), a reboot, tick; a 3 GiB move stopped mid-copy by `systemctl
  stop` logged its put-back and left every service running and nothing masked.
  Gates: `joinery_data_root` 119, `installer_contract` 878. Final trace by
  reviewer2 on the frozen tree 2026-10-10: VALID, nothing open.
- **WP4 — Fleet:** managed nodes migrated one at a time from the management
  node, dev first.
  **WP4 tooling BUILT 2026-10-10, unreleased.** Agent 1.69.0: the
  `data_root_migrate` operate machine word (no parameters, argv `migrate`,
  4 h timeout; the move outlives it in its own unit). Plane: JobCommandBuilder
  1.114 `build_data_root_migrate` (refuses a site container), node detail
  actions 1.54, node overview 1.59 (Health shows "Data root: none" with
  **Move its data onto the data root** on a host whose host report says
  `disk_pool: none`, behind a confirm), server_manager 1.30.48, overview.md's
  vocabulary row; `job_command_builder` test +3. Waiting on the owner: a
  release carrying D6, WP2, WP3 and agent 1.69.0, then the moves, dev first.

## Open items

- **O1 — SETTLED 2026-10-09.** Modest at first, grown automatically: D7.
- **O2 — SETTLED 2026-10-07.** `/var/lib/joinery/host` stays on the root
  disk: the agent needs it before the data root is up, and the encryption
  spec needs the agent to run with the data root closed. The `host/` line in
  D1 is withdrawn.
- **O3 — SETTLED 2026-10-09.** Under the data root, with the agent's own log on
  the root disk: D8.
- **O4 — SETTLED 2026-10-10.** A site's folders reach the data root by bind
  mounts, not symlinks (every tool that walks a site skips a symlink's
  contents); Postgres, Postfix and rspamd the same way, so no package is
  reconfigured: D1.
