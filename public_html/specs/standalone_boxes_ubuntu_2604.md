# Standalone Boxes to Ubuntu 26.04 / PostgreSQL 18

**Status:** Waiting on Ubuntu (2026-09-26). `meta-release-lts` still lists resolute 26.04.1 with
`Supported: 0` (checked 2026-09-26). Two owner decisions open (D3, D4).
**Related:** `specs/implemented/fleet_ubuntu_2604_postgres_upgrade.md` — the fleet move this is the
rest of. Its Stages 1–3 are done: all eight Docker sites run PostgreSQL 18 on Ubuntu 26.04 images, and
new sites are born there. `specs/backup_database_incrementals.md` — the payoff: a node's nightly
database backup can shrink only once it runs PostgreSQL 17+.

## What this does for the owner

Moves the last two boxes that run their own PostgreSQL — jeremytunnell and dev — onto Ubuntu 26.04,
PostgreSQL 18 and PHP 8.5. For jeremytunnell that is the point of the whole fleet move: its nightly
1.65 GB database upload can drop to a fraction of that. For dev it also lets the
database-incrementals tests run in the ordinary gate.

Nothing else is left on Ubuntu 24.04 that needs to move. The docker-prod server, the relay and both
ScrollDaddy DNS servers run no Joinery database and stay on 24.04 (supported to 2029); the relay and
DNS boxes are rebuilt, never upgraded.

**Why it waits:** these two upgrade their own OS in place, and Ubuntu has not opened the 24.04 → 26.04
upgrade. Press reports put the hold on regressions in 26.04's Rust coreutils. Nodes show "no upgrade
offered" because `host_report.sh` reads `/var/lib/ubuntu-release-upgrader/release-upgrade-available`,
which follows `meta-release-lts`.

## For the executor — read this first

- **Never commit, never `git add`.** The owner runs git.
- **This is done on the boxes, in a shell, by the owner or with the owner present.** No agent job can
  do it: nothing in the agent runs apt or `do-release-upgrade`, and the reboot would end the job.
- **Installer files and the converger.** Stop the dev box's host converger before editing anything in
  `install_tools/` on dev (WP13), and restart it after.
- **Docs describe the current state only.**

## WP9 — Inventory both boxes (read-only)

Paste the output here: `ls /etc/apt/sources.list.d/`, `apt-mark showhold`,
`dpkg -l 'php*' | grep ^ii`, `pg_lsclusters`, `df -h /`, `free -m`, and
`systemctl list-units --type=service --state=running`. Known already: dev's PHP 8.3 comes from the
ondrej PPA, and dev also has nodesource, chrome and tailscale sources. jeremytunnell's package sources
are unverified.

## WP10 — Rehearse on a clone of jeremytunnell (R2)

A Linode clone at the same plan (a clone's disk cannot be smaller than the source's). jeremytunnell is
not in the account dev's Linode token reaches; the clone, its firewall and the console step to open
SSH on it happen in jeremytunnell's own account.
- **Before the clone's first boot, attach a Cloud Firewall that denies all outbound traffic and allows
  inbound SSH only from the owner's address.** The clone boots as jeremytunnell: the same agent
  identity, cron, relay pull and backup credentials. Unfenced, it would claim the node's jobs, pull the
  node's mail off the relay and write into its backup storage.
- Disable the agent, the host converger timer and path, and cron. Then open outbound 80/443 for apt only.
- Run the runbook below with `do-release-upgrade -d`, then the gates, reaching the site through a
  hosts-file entry. Record timings and surprises here, then delete the clone.

## The runbook (rehearsed in WP10, then used for real in WP11 and WP12)

1. A successful backup under 24 hours old with a level-2 verification. Take a provider snapshot if
   Linode Backups is enabled on the box (unverified).
2. Stop the agent, `joinery-host-converger.timer` and `.path`, so nothing converges packages
   mid-upgrade.
3. `do-release-upgrade` (non-interactive frontend), then reboot.
4. `pg_upgradecluster 16 main` using the **default dump method**, not `-m upgrade`: a dump rebuilds
   every index under 26.04's collation. The old cluster stays, stopped, on port 5433 as the rollback
   until the gates pass for a week, and is then dropped.
5. `a2disconf php8.3-fpm && a2enconf php8.5-fpm`. Purge PHP 8.3 first: `detect_php_version` prefers a
   leftover `php` binary. Install the declared extensions. Host housekeeping then applies the
   platform's `php.ini` settings (it tunes a `php.ini` byte-identical to its version's
   `php.ini-production`).
6. Re-enable the converger and the agent, run a host converge, then the gates.

**Gates, per box:**
- The front page, login and admin dashboard over HTTPS.
- Every table's row count matches the old cluster's.
- The agent is green on the management node, and a `check_status` job round-trips without re-pairing.
- `php tests/run.php deploy` on the node.
- One fleet backup and one level-2 verification succeed on the new stack.
- jeremytunnell: mail flow, rspamd and redis.

## WP11 — jeremytunnell (per D3 and D4)

Postfix is off there, and inbound mail waits on the relay while the box is down
(`mailbox_listener_decommission`). rspamd and redis are still there: check both after the upgrade.

## WP12 — dev (per D4)

Dev is also the management node: fleet backups are scheduled from here, so pick a window away from
03:00–05:00 UTC. The PPA and third-party sources come back for resolute (or are dropped) after the
upgrade. Once dev is on PostgreSQL 18, the database-incrementals integration tests run in the
ordinary gate.

## WP13 — Cleanup, after both boxes move

- Drop 24.04 from the installer's OS gate (`install.sh`, the supported-OS check) and move the
  `installer_contract_test` assertion that pins it, fixing its stale "PHP 8.3 hardcoded" comment.
  `--allow-unsupported-os` still covers a hand install.
- Restate the docs as current state: `docs/installation.md` (supported OS), `docs/deploy_and_upgrade.md`
  (supported OS), `INSTALL_README.md` (supported OS, and the log path that names
  `postgresql-16-main.log`), and the Server Manager overview's OS expectations.
- The relay birth flow on 26.04 (`RelayCloudProvisioner.php`) has never run. Rebuild a relay on it the
  next time one is needed.
- The PostGIS pin in the unbuilt `specs/geolocation_postgis_spec.md` moves to 18 when that spec is
  built, not before.

## Open decisions

**D3 — When do the two boxes move?**
- **Wait for Ubuntu to open the upgrade** (`Supported: 1`). Canonical has held it for regressions, so
  the first run of the upgrade path is not ours. Catch: no date, and jeremytunnell — the box whose
  backups shrink most — waits with it.
- **Force it (`-d`) once the clone rehearsal (WP10) passes.** Catch: we run a path Ubuntu itself says
  is not ready, on a box our installers drive with coreutils-heavy bash.
- **Recommendation:** rehearse now, and wait for `Supported: 1` for the real upgrade.

**D4 — Which box goes first?**
- **jeremytunnell first:** the simpler box, and the one whose backups benefit most; the relay holds its
  mail while it is down. Catch: it is your live site.
- **dev first:** we use it all day, so problems surface fast, and the database-incremental tests start
  running. Catch: it is the management node, and it carries four third-party package sources.
- **Recommendation:** jeremytunnell first, after the clone rehearsal.

## Checking whether Ubuntu has opened it

`curl -s https://changelogs.ubuntu.com/meta-release-lts` → the `Dist: resolute` block's
`Supported:` line. `0` means wait; `1` means the upgrade is offered.
