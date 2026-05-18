# Mail Stack Container Persistence

## Problem

The Postfix + opendkim mail stack that the Inbound Email plugin depends on does
**not** survive a `docker stop` / `docker start` of a Joinery site container —
and is lost entirely on a container recreate or image rebuild. On a systemd
host it is fine (`install_email.sh` runs `systemctl enable`); in a container
that enable is a no-op and nothing brings the stack back.

## Root cause

Joinery site containers have no systemd. The container's `CMD` (in
`maintenance_scripts/install_tools/Dockerfile.template`) **is** the init: a bash
script that runs `service postgresql start`, first-run `_site_init.sh`,
`service cron start`, …, and finally `apache2ctl -D FOREGROUND` as the
foreground process that keeps the container alive.

That `CMD` re-runs on every `docker start`. It explicitly restarts
**postgresql** and **cron** each time — that is how those survive a restart.

But:

- `install_email.sh` is run **at runtime** (from the admin Plugins page), not
  at image build. It installs `postfix` / `postfix-pgsql` / `opendkim` into the
  container's writable layer and starts them once.
- The `CMD` knows nothing about the mail stack — it never starts postfix or
  opendkim.
- `install_email.sh`'s `systemctl enable postfix` is wrapped in `|| true` and
  does nothing in a container.

## What survives today

| Event | postgresql / cron / apache | postfix / opendkim |
|-------|---------------------------|--------------------|
| `docker stop` → `docker start` (same container) | ✅ `CMD` restarts them | ❌ nothing restarts them |
| Container recreate / image rebuild | ✅ in the image / re-asserted by `CMD` | ❌ packages **and** `/etc` config gone |

The second row is the deeper issue: even the *packages* are not in the image
(they were installed into one container's writable layer), and the Postfix /
opendkim **config** in `/etc` is not on the config volume, so a rebuild loses
both.

## Recommended solution

Two coordinated changes — packages into the image, startup into the `CMD`.

### 1. Bake the mail packages into the base image

`install.sh server` (which builds `Dockerfile.base`) installs `postfix`,
`postfix-pgsql`, `opendkim`, and `opendkim-tools` alongside Apache / PHP /
PostgreSQL. They sit inert — default config, not started — until a site
configures them. This makes the packages survive rebuilds and removes the slow
`apt-get` step from runtime setup.

### 2. The container `CMD` re-asserts the mail stack on every start

When the `inbound_email` plugin is active, the `CMD` runs `install_email.sh` on
every container start, before `apache2ctl -D FOREGROUND`. `install_email.sh` is
idempotent by design: it re-applies the Postfix and opendkim configuration and
starts both daemons.

This is the **same re-assert-on-every-start pattern the `CMD` already uses** —
Dockerfile.template VERSION 3.3 writes the logrotate config every start, and
VERSION 3.6 self-heals ownership every start. The mail stack joins that list.

The `CMD` gates this on plugin state with one `psql` query (PostgreSQL is
already up at that point in the script):

```
SELECT plg_active FROM plg_plugins WHERE plg_name = 'inbound_email';
```

A site that does not use inbound email never starts postfix.

### Why re-assert, not just restart

The Postfix / opendkim config in `/etc/postfix` and `/etc/opendkim` is not on
the config volume, so it does not survive an image rebuild. Re-running the
idempotent `install_email.sh` rebuilds that config from the volume-persisted
database name. A plain `service postfix start` in the `CMD` would survive
`stop`/`start` but still break on a rebuild — re-assert covers both.

## install_email.sh adjustments

- **No change needed** for the baked packages: the script already detects
  already-installed packages and skips the `apt-get` step (`Already
  installed: …`).
- **Refinement (recommended):** today every run rotates the pgsql-map role
  password. With the `CMD` calling the script on every container start that is
  needless churn. The script should **reuse the existing password** when
  `/etc/postfix/joinery-domains.cf` is present and parseable, and only generate
  a new one when the map is missing or the operator forces a rotation. This
  keeps the every-boot re-assert cheap and side-effect-free.
- A dedicated fast `--start` mode (skip config render, just start the daemons)
  was considered and rejected: it would not survive a rebuild, where the config
  genuinely must be re-rendered.

## Crash recovery (note, not in scope)

The `CMD` re-assert covers the container lifecycle, not a mid-life daemon
crash. `milter_default_action = accept` already means a crashed opendkim never
blocks mail. A crashed postfix mid-life would halt inbound mail until the next
container restart; if that ever matters, a lightweight watchdog (a cron
re-assert, or wiring the existing provisioning checks to self-heal) is a
separate, future addition.

## Files touched

- `maintenance_scripts/install_tools/install.sh` — `server` action installs the
  four mail packages.
- `maintenance_scripts/install_tools/Dockerfile.base` — VERSION bump (its
  contents only change via `install.sh server`).
- `maintenance_scripts/install_tools/Dockerfile.template` — `CMD` gains the
  plugin-gated mail-stack re-assert block; VERSION bump to 3.9.
- `plugins/inbound_email/provisioning/install_email.sh` — pgsql-map password
  reuse refinement; header version note.
- `plugins/inbound_email/docs/overview.md` — a "Container persistence" section
  documenting that the mail stack is re-asserted on every container start.

## Propagation — how the change reaches nodes

Both changes are **build-recipe edits**, not node changes. The base image's
package set is defined by `install.sh`'s `server` action — `Dockerfile.base`
only does `COPY install.sh` + `RUN install.sh server` — and the `CMD` lives in
`Dockerfile.template`. Editing either does nothing to a running node.

The change reaches a node only through a full rebuild chain:

```
edit install.sh / Dockerfile.template
  → rebuild base image       (install.sh build-base)
  → rebuild each site image  (FROM the new base)
  → recreate / redeploy the node's container
```

Critically, the normal **Publish Upgrade / `upgrade.php` pipeline ships app
code only** (`public_html`, plugins) — it does **not** rebuild the OS/base
layer. Base-image changes are a separate, deliberate operation.

Consequences:

- **Newly built nodes** get the baked packages and the `CMD` re-assert — fully
  solved.
- **Existing nodes** are unaffected until rebuilt and redeployed; they keep
  relying on the runtime `install_email.sh` path.
- The design degrades gracefully: `install_email.sh` already installs the mail
  packages when missing, so an un-rebuilt node still works the old way and a
  rebuilt node simply skips the (now redundant) apt step.

There is no existing-node migration to plan for today — Inbound Email is not
deployed to any node yet. This spec makes the mail stack correct for the first
containerized deployment; it is not a retrofit of running infrastructure.

## Open decisions

- **Baking mail packages into every base image** adds roughly **8 MB** to the
  image (measured: ~5 MB for the four packages, ~2.5 MB of genuinely mail-only
  dependency libs; the large shared libs — libc, libssl, libicu, libpq — are
  already in the base and cost nothing). Negligible against a base image of
  hundreds of MB, and the `CMD` only *starts* the stack when the plugin is
  active, so an unused site carries inert packages and nothing more. The
  alternative (a separate "mail-capable" base image variant) is not worth the
  build-matrix complexity.
- **Persisting `/etc/postfix` on the config volume** instead of re-asserting was
  rejected — re-assert matches the existing Dockerfile idiom and avoids volume
  sprawl and drift between the volume and the packaged defaults.

## Testing

- On a container with inbound email fully set up: `docker stop` then
  `docker start`; confirm postfix and opendkim are running, port 25 answers,
  and a test message to a live alias delivers and logs.
- Rebuild the site image and run a fresh container; confirm the same — the
  mail stack comes up without any manual `install_email.sh` run.
- On a site **without** the `inbound_email` plugin active: confirm the `CMD`
  skips the mail block and postfix is not started.
- Confirm container start time is not materially worse (the re-assert with
  packages pre-baked should add only a couple of seconds).

## Out of scope

- Mid-life daemon crash recovery / watchdog.
- A separate mail container — the plugin's Postfix-pipe-to-PHP model requires
  Postfix co-located with the app, on the same filesystem and database.
- systemd-inside-container.
- Bare-metal / VM hosts — `systemctl enable` already makes those boot-safe.

## Phasing

1. Base-image packages + the `Dockerfile.template` `CMD` re-assert block — this
   alone closes the reported `docker stop`/`start` gap and the rebuild gap.
2. `install_email.sh` pgsql-map password-reuse refinement.
3. Docs.
