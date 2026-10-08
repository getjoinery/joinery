# One data root: every install keeps its data in one place, on a filesystem of its own

**Status:** DRAFT 2026-10-07. Written from an inventory of the installers the
same day. Nothing built. Stands alone; `hosted_data_promise_disk_encryption`
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
joinery_data_root.sh create SIZE [DEVICE]   make it (file of SIZE, or format DEVICE), fstab, mount
joinery_data_root.sh check                  mounted, right filesystem, right options; exit 1 if not
joinery_data_root.sh grow SIZE              a file grows in place; a device grows after the provider does
joinery_data_root.sh status                 size, used, what is under it
```

### D3. Services wait for it

A systemd target, `joinery-data.target`, reached when `/srv/joinery` is
mounted. PostgreSQL, Docker and containerd, the web stack, Postfix and rspamd
are ordered after it (`After=` + `Requires=` drop-ins written by the
installer). A box whose data root is absent starts the agent and nothing
else, and `check` says why. This is what `docker_disk_pool.sh` already does
for Docker alone, applied to every consumer.

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

## Work packages

- **WP1 — `joinery_data_root.sh`** with `create / check / grow / status`, the
  target and the drop-ins; `docker_disk_pool.sh` rebased onto it.
  `installer_contract_test` pins the layout; `host_converger_gate.sh` pins
  that the converger refuses to run installers when `check` fails.
- **WP2 — New installs born on it:** `install.sh`, `_site_init.sh`, the
  Docker host path. Upgrade staging moves to the site root (D6), released
  before WP3 runs anywhere.
- **WP3 — `migrate`** for the three existing shapes, proven on a scratch box
  of each.
- **WP4 — Fleet:** managed nodes migrated one at a time from the management
  node, dev first.

## Open items

- **O1.** Default file size for a bare-metal site when no device is given —
  a fraction of the root disk, or asked at install.
- **O2 — SETTLED 2026-10-07.** `/var/lib/joinery/host` stays on the root
  disk: the agent needs it before the data root is up, and the encryption
  spec needs the agent to run with the data root closed. The `host/` line in
  D1 is withdrawn.
- **O3.** Logs: data (they fill disks, they belong with the rest) or not
  (useful when the data root is the thing that failed). Lean: under the data
  root, with the agent's own log on the root disk.
