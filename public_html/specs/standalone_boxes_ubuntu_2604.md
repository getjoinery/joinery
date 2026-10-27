# Standalone Boxes to PostgreSQL 18 / PHP 8.5

**Status:** Active. 2026-10-26: Ubuntu opened the 24.04 → 26.04 upgrade (`Supported: 1`), and the
workspace has moved off dev to localdev, so dev takes the OS move in place (WP2, D5) instead of the
outside-repository route. WP1 under way; WP3 (jeremytunnell) keeps the outside-repository route
until decided otherwise.
**WP2 done 2026-10-26:** dev runs Ubuntu 26.04.1, PostgreSQL 18.6 and PHP 8.5.4 from Ubuntu, on a
4 GB plan; every check below passed, and `php8.3*` is purged. Left: one real publish and the next
night's backups, then WP4 on 2026-11-02.
**Related:** `specs/implemented/fleet_ubuntu_2604_postgres_upgrade.md` — the fleet move this is the
rest of. All eight Docker sites run PostgreSQL 18 and PHP 8.5 on Ubuntu 26.04 images.
`specs/backup_database_incrementals.md` — the payoff: a node's nightly database backup can shrink
only once it runs PostgreSQL 17+.

## What this does for the owner

Moves the last two boxes that run their own PostgreSQL — dev and jeremytunnell — to PostgreSQL 18
and PHP 8.5, without changing their operating system. For jeremytunnell that is the point of the
whole fleet move: its nightly 1.65 GB database upload can drop to a fraction of that. For dev it also
lets the database-incrementals tests run in the ordinary gate.

**Why not the OS upgrade (as of 2026-09-26; dev takes it after all, WP2):** Ubuntu had not opened the 24.04 → 26.04 upgrade (`meta-release-lts`
still lists resolute with `Supported: 0`, checked 2026-09-26) and gives no date. Ubuntu 24.04 is
supported to 2029, so the OS move is no longer needed for anything; it becomes optional (Later,
below).

**How:** Ubuntu 24.04's own archive carries only PostgreSQL 16 and PHP 8.3, so both come from
outside repositories:
- PostgreSQL 18 from the PostgreSQL project's repository (apt.postgresql.org), which builds for 24.04.
- PHP 8.5 from the ondrej PHP repository, which dev already uses for PHP 8.3.

The operating system does not change, so the rules PostgreSQL sorts text by do not change either:
the database moves with `pg_upgrade`, which copies its files, with no dump and no index rebuild. The
old PostgreSQL 16 stays on the box, stopped, as the way back.

## For the executor — read this first

- **Never commit, never `git add`.** The owner runs git.
- **The owner runs the root steps.** The executor has no sudo on dev, and nothing in the agent runs
  apt. The executor prepares each step's commands, and checks the result between steps.
- **Installer files and the converger.** Stop the dev box's host converger before editing anything
  in `install_tools/` on dev (WP1), and restart it after.
- **Docs describe the current state only.**

## Current state (checked 2026-09-26)

- **dev:** Ubuntu 24.04.4. PostgreSQL 16.15 (Ubuntu's), one cluster `16/main` on 5432 holding
  `joinerytest` (408 MB), `integral_membership` (430 MB), `test_joinerytest` and `postgres`; no
  extension but plpgsql in `joinerytest`. PHP 8.3.32 from ondrej, with apcu, imagick and imap beside
  the platform's set; ondrej offers 8.5.11 for 24.04, apcu and imagick included, no separate opcache
  package (8.5 builds it in). Apache runs PHP through `conf-enabled/php8.3-fpm.conf`. The FPM pool
  (`/etc/php/8.3/fpm/pool.d/www.conf`) is hand-tuned to `pm = ondemand`; its php.ini is the tuned
  one host housekeeping writes. No cron job or unit names a PHP version. 18 GB free on `/`.
  Last backup 2026-09-26 04:45 (success); last verification 2026-09-22, level 3, pass.
- **jeremytunnell:** Ubuntu 24.04.4, 2 GB memory, 32.7 GB free. Its PHP's source and version, its
  FPM pool and its database sizes are not yet read (WP3).
- **Our scripts already take a second version:** host housekeeping configures every
  `/etc/postgresql/*/main` and `/etc/php/*/fpm`; `tune_postgres_memory.sh` picks the newest
  PostgreSQL; `host_report.sh` and `restart_unit.sh` pick the active php-fpm unit; the backup and
  restore scripts read the server's version rather than assuming one.

## Bugs found

**B1 — packages from outside repositories never get security updates.** Automatic updates
(`50unattended-upgrades`, written by `install.sh`) allow only Ubuntu's own `-security` origins. Dev's
PHP comes from ondrej: 8.3.32 is installed while 8.3.35 is offered, and the last PHP update was by
hand. Moving PostgreSQL to its own repository would add the same gap. Fixed by WP1.

## WP1 — Automatic updates cover the platform's outside repositories (fixes B1)

Host housekeeping writes `/etc/apt/apt.conf.d/51joinery-platform-repos` naming the origin of each
outside repository the platform's own runtime comes from, when that repository is configured on the
box:
- ondrej PHP: `LP-PPA-ondrej-php:${distro_codename}`
- PostgreSQL: `apt.postgresql.org:${distro_codename}-pgdg`

With neither present the file is removed. Written whole, only when its content differs. Other
outside repositories (Docker, Chrome, Node, Tailscale) stay out: an automatic Docker engine update
restarts every site on the box.

**Tests:** the host housekeeping gate — with each repository present, both, and neither; a second
run changes nothing; the file names only origins, and `apt-config dump` on a real box shows them
added to Ubuntu's.

## The runbook (WP2 on dev, then WP3 on jeremytunnell)

**Before:** a successful backup under 24 hours old, and the last verification passed. Stop the host
converger (`joinery-host-converger.timer` and `.path`) so nothing converges mid-change. The executor
records every table's row count and every non-default server setting.

**PostgreSQL 18:**
1. Add the repository: `/usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y`, then
   `apt install -y postgresql-common` (the repository's newer one).
2. Keep a later release from arriving on its own: `create_main_cluster = false` in
   `/etc/postgresql-common/createcluster.conf`, and remove the unversioned `postgresql` and
   `postgresql-contrib` packages, which always pull in the newest major version. Mark
   `postgresql-16` and `postgresql-client-16` manual so the nightly autoremove keeps the way back.
3. `apt install -y postgresql-18 postgresql-client-18`. No cluster is created.
4. **The cutover (the site's database is down for a minute or two):**
   `pg_upgradecluster -m upgrade 16 main`. The new `18/main` takes port 5432; `16/main` moves to 5433,
   stopped, with automatic start off.
5. The upgrade copies `postgresql.conf`, `pg_hba.conf` and `postgresql.auto.conf` but not `conf.d/`,
   where the memory tuning (`50-minimal.conf` on dev) and host housekeeping's listen pin live: copy
   `16/main/conf.d/*.conf` into `18/main/conf.d/`, then `pg_ctlcluster 18 main restart` (never
   `systemctl restart postgresql`).
6. Check: `pg_lsclusters`, the server version, row counts and settings against the record.

**PHP 8.5:**
1. `apt install -y php8.5-{fpm,cli,common,pgsql,xml,curl,gd,dev,mbstring,soap,zip,bcmath,intl,readline,sqlite3,apcu,imagick}`.
   It starts `php8.5-fpm` on its own socket; Apache still uses 8.3. (imap is left out: nothing in the
   platform uses it.)
2. Carry the pool over: 8.3's `www.conf` with its socket renamed to `php8.5-fpm.sock`; keep the
   packaged one as `www.conf.dist`.
3. Run one host converge: it tunes 8.5's fresh php.ini and writes WP1's file.
4. Switch: `update-alternatives --set php /usr/bin/php8.5` (manual mode, so a later PHP never takes
   the `php` command over), `a2disconf php8.3-fpm`, `a2enconf php8.5-fpm`, reload Apache, then
   `systemctl disable --now php8.3-fpm`.

**After:** restart the converger.

**Gates, per box:**
- The front page, login and admin dashboard over HTTPS; no new `Fatal` in the error log.
- `php -v` and the modules `php -m` lists, for both the CLI and FPM.
- Every table's row count and every setting match the record (tables written to during the
  cutover are named, not failed).
- The agent is green on the management node, and a `check_status` job round-trips.
- `php tests/run.php deploy`.
- One backup and one level-2 verification succeed on the new stack.
- jeremytunnell: mail flow, rspamd and redis.

**The way back** (writes after the cutover are lost):
- PostgreSQL: stop `18/main`; swap the two clusters' ports; set `16/main`'s `start.conf` to `auto`;
  start `16/main`.
- PHP: `a2disconf php8.5-fpm`, `a2enconf php8.3-fpm`, `systemctl enable --now php8.3-fpm`,
  `update-alternatives --set php /usr/bin/php8.3`, reload Apache.

## WP2 — dev: the OS move in place (D5)

Dev goes 24.04 → 26.04 with `do-release-upgrade`, and its PostgreSQL 18 and PHP 8.5 come from
Ubuntu's own archive. The ondrej repository is removed (it has no 26.04 build). Dev keeps its
address, keys, mail setup and allow-lists. It is the management node: stay clear of 03:00–05:00
UTC, when it runs the fleet's backups. No agent sessions run on dev since the move to localdev, so
the window touches only its public jobs (node check-ins retry, test mail waits, webhooks retry).

**State before (checked 2026-10-26):** Ubuntu 24.04.4, kernel 6.8 booted by GRUB 2. PostgreSQL 16.15
(Ubuntu's, with the unversioned `postgresql` package), cluster `16/main` on the data root
(`/var/lib/postgresql` is a bind of `/srv/joinery/postgresql`). PHP 8.3.36 from ondrej, through
FPM (`conf-enabled/php8.3-fpm.conf`, mpm_event, no mod_php). Outside repositories: ondrej (no
resolute build), Tailscale (has resolute), Chrome and NodeSource (codename-free). Go 1.22 from
Ubuntu (26.04 has 1.26; the Go modules ask for 1.22). Last site backup 06:00, verification level 2
passed 10-25.

The scripts are in `~user1/os_upgrade_2604/` on dev:

1. **Record (Claude, as user1):** `record.sh before` — every table's exact row count in
   `joinerytest` and `integral_membership`, non-default server settings, database sizes, roles,
   PHP modules, running services, data-root mounts, the package list, and a full `pg_dumpall`
   (the fallback if PostgreSQL 16 does not start on 26.04). About 45 seconds.
2. **Before (owner):** a manual Linode snapshot in Cloud Manager. It is the way back for
   everything; PHP 8.3 has no 26.04 build to fall back to.
3. **Prepare (owner, root):** `sudo bash ~/os_upgrade_2604/1_prepare.sh` — pauses dev's
   `follow_main` cron line, the host converger and automatic updates; sets
   `create_main_cluster = false` so no empty 18 cluster appears; marks `postgresql-16` manual;
   `apt full-upgrade` on 24.04. Reboot if it says so.
4. **Upgrade (owner, root, inside `screen`):** `sudo do-release-upgrade`. Answers: continue over
   SSH, yes; keep the locally modified version of every config file (the default); **No** to
   removing obsolete packages (PostgreSQL 16 and its libraries must stay until the data moves);
   reboot at the end.
5. **After (owner, root):** `sudo bash ~/os_upgrade_2604/3_after.sh` — re-enables Chrome,
   NodeSource and Tailscale (Tailscale pointed at resolute), deletes the ondrej source; installs
   PostgreSQL 18 and moves the data with `pg_upgradecluster -m dump` (dump and reload rebuilds
   every index, which the new C library's text sorting requires), copies `conf.d/`; installs PHP
   8.5 with apcu and imagick (xhprof and imap have no 8.5 package; nothing in the platform uses
   either), carries the FPM pool with its socket renamed, switches the `php` command and Apache's
   conf, turns 8.3's FPM off; runs one full host converge; turns step 3's pauses back on.
6. **Check (Claude):** `record.sh after` and compare: row counts and roles match (tables written
   in the window named, not failed), settings carry over, every PHP module but xhprof and imap is
   there, the same services run, the data-root mounts are back. Then the gates below, plus one
   real publish (dev builds and signs releases, now with Go 1.26) and a mail round trip.
7. **Same day, once the checks pass:** purge `php8.3*` and the other packages Ubuntu marks obsolete,
   except `postgresql-16` and its libraries.

**What actually happened on dev (2026-10-26), for jeremytunnell or any later box:**
- `do-release-upgrade` moved the databases itself: 26.04's postgresql-common ran `pg_upgradecluster`
  in dump mode during the upgrade (logged in `/var/log/dist-upgrade/screenlog.0`), leaving `18/main`
  on 5432 and `16/main` stopped on 5433. It did not copy `conf.d/`, so step 3 only copies it.
- Outside `.list` files come back renamed `*.list.disabled` with their lines commented, and the old
  `sources.list` lands in `third-party.sources` (noble, `Enabled: no`). Each repository's line is
  written back explicitly; a blanket "remove `Enabled: no`" would turn the 24.04 sources back on.
- ondrej's `libgd3` and `php-common` carry higher version numbers than Ubuntu's, so apt keeps them;
  step 3 installs Ubuntu's versions with `--allow-downgrades`.
- Postfix runs as `postfix.service`, not `postfix@-`.

**If PostgreSQL 16 will not start on 26.04:** create `18/main` with `pg_createcluster 18 main`,
copy `conf.d/`, start it, load `before/dumpall.sql.gz` with `psql -U postgres -f -`, and run the same
checks.

## WP3 — jeremytunnell

Read first: its package sources, `dpkg -l 'php*'`, `pg_lsclusters`, database sizes, its FPM pool and
php.ini against the packaged templates. Add the ondrej repository if its PHP is Ubuntu's own. Postfix
is off there, and inbound mail waits on the relay while the site is down; check rspamd and redis
after.

## WP4 — Cleanup, a week after each box passes its gates

`pg_dropcluster 16 main`, then purge `postgresql-16`, `postgresql-client-16` and `php8.3*`. On dev,
also put `create_main_cluster` back and remove `~user1/os_upgrade_2604/` (it holds a database dump).

## Later — the OS move (optional)

When Ubuntu opens 24.04 → 26.04 (`Supported: 1`), a box can take `do-release-upgrade`. The newer C
library changes how text sorts under a database that stays on 18, so after it: `REINDEX DATABASE`
each database and `ALTER DATABASE … REFRESH COLLATION VERSION`. Then 26.04's own PostgreSQL 18 and
PHP 8.5 replace the outside repositories' (same versions), and once no box runs 24.04:
- drop 24.04 from the installer's OS gate (`install.sh`) and the `installer_contract_test` assertion
  that pins it; restate `docs/installation.md`, `docs/deploy_and_upgrade.md`, `INSTALL_README.md`
  (supported OS, and the `postgresql-16-main.log` path) and the Server Manager overview;
- the relay birth flow on 26.04 (`RelayCloudProvisioner.php`) has never run: rebuild a relay on it
  the next time one is needed.

## Decisions

- **D3 — When do the boxes move?** Decided 2026-09-26: now, on 24.04, from the outside repositories;
  the OS upgrade is no longer needed for PostgreSQL 18.
- **D4 — Which box first?** Decided 2026-09-26: dev.
- **D5 — dev takes the OS move in place, its PHP and PostgreSQL from Ubuntu.** Decided by the owner
  2026-10-26, once Ubuntu opened the upgrade: in place rather than a new 26.04 Linode, so dev keeps
  its address and identity. Dev moved to a 4 GB plan in the same window, which stands in for the
  planned shrink (`strategy/localdev_and_dev_split.md` WP5).

## Checking whether Ubuntu has opened the OS upgrade

`curl -s https://changelogs.ubuntu.com/meta-release-lts` → the `Dist: resolute` block's
`Supported:` line. `0` means wait; `1` means the upgrade is offered.
