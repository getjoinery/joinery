# PHP-FPM sizing on bare-metal hosts

Status: IMPLEMENTED 2026-10-24 (see As built: WP3's per-site pools were dropped for a one-site refusal, owner 2026-10-24). Verified: the tuner, PostgreSQL tuner, housekeeping, host report, processor and installer suites; live on dev (housekeeping 1.32 sized dev's pool, held to 5 by dev's own max_connections 25); a reload with process_control_timeout 10s finishes a request in flight and 0 cuts it (real php-fpm, in the tuner gate). The rest (the reserve measured on a 2 GB box with rspamd, the node page line on a released node) is in the live verification queue.
Origin: the jeremytunnell.com stalls of 2026-10-23 (`slow_request_diagnosis_words.md`).
Found while answering whether PHP's worker count should be sized: it already is
in every site container, and nowhere else.

## What stands today

`multi_tenant_docker_hosts.md` WP2 (built 2026-10-06) sizes a **site
container's** pool from its memory budget, at every container start
(`_site_start.sh` → `tune_php_fpm.sh --container`):

```
budget          = the container's cgroup limit (a container with none is refused, exit 3)
shared_buffers  = budget / 5, at least 64 MB, at most 2 GB          (PostgreSQL's cache)
pm.max_children = (budget − shared_buffers − 128 MB) / 40 MB, at least 2, at most 80
max_connections = pm.max_children + 20                              (PostgreSQL)
```

All in `sysadmin_tools/_memory_plan.sh`, shared with `tune_postgres_memory.sh`
so the pool and the database's connections cannot drift apart. 128 MB is
everything in a container that is not a PHP worker (measured about 90 MB at
rest); 40 MB is a worker plus its database connection (the heaviest admin page
measured 10 MB). A shared host's site gets a budget of 384 MB, which gives 4
workers.

**A bare-metal host gets none of it.**

- `install.sh` runs `tune_postgres_memory.sh` on bare metal (`install.sh:3937`)
  but never `tune_php_fpm.sh`, so the pool keeps Ubuntu's 5 workers whatever
  the machine. Dev's `pool.d` holds only the packaged `www.conf`
  (`pm.max_children = 5`); jeremytunnell.com (2 GB, one CPU) has no caller that
  would have written anything else (not read on the node: no word reads
  `pool.d`).
- `tune_php_fpm.sh` would size from `MemTotal` there, but the plan's 128 MB
  "everything else" is a container's figure. A bare-metal host also carries the
  mail stack (rspamd about 200 MB settled), the agent, fail2ban, journald,
  sshd, and possibly the office editor, so the plan would overcount the room
  for PHP.
- Several sites on one machine share one pool and one PostgreSQL cluster.
  Two real sites on one machine take each other's workers. (A site's own
  `_test` copy sharing them is fine: it is idle almost always, WP3.)
- PostgreSQL on bare metal keeps the packaged 100 connections
  (`tune_postgres_memory.sh` writes `max_connections` only for a stated or
  cgroup budget, line 167).

## Goal

Every bare-metal host sizes its PHP workers from the memory its sites
actually have, by the same plan containers use, and one site on a shared
machine cannot take another's workers.

## Work packages

### WP1. A host reserve, then the same plan

`_memory_plan.sh` gains a bare-metal budget: when the source is `MemTotal`,

```
budget = MemTotal − host_reserve
host_reserve = 384 MB                        (agent, sshd, fail2ban, journald, cron,
                                              Apache's parent, kernel)
             + 256 MB if rspamd is installed
             + the office editor's own cap if its unit is installed:
               MemTotal / 2, at least 400 MB (install_office_editor.sh:221-222)
```

Each term is a named constant in `_memory_plan.sh` with its measurement beside
it, as `MEMORY_PLAN_BASE_MB` has. The figures above are starting values to be
measured on a test box (WP4) before they ship: rspamd measured 57 MB resident
on dev on 2026-10-23 against about 200 MB settled in a container in
`multi_tenant_docker_hosts.md`, so its constant is the measured figure on the
test box, not either guess. The plan's shared_buffers and 128 MB base then
apply to `budget` unchanged.

Constants, not a run-time measurement: what is available at run time includes
what PHP workers and PostgreSQL hold at that moment, so a plan read from it
feeds on its own output and changes with the hour. WP5 reports the actual
non-site resident set beside the constant, so drift is seen and the constant
corrected by a release.

jeremytunnell.com at the starting figures: 2,009 − 384 − 256 = 1,369 MB budget;
shared_buffers 273 MB; workers (1,369 − 273 − 128) / 40 = 24.

### WP2. A CPU ceiling

Memory alone gives 24 workers on one CPU. Most of a worker's time is a wait
(the database, an IMAP server, an outside API), so more workers than CPUs is
right, but without limit a burst of heavy pages queues in the run queue instead
of FPM's listen queue, and holds memory while it does. The plan gains:

```
pm.max_children ≤ MEMORY_PLAN_CHILDREN_PER_CPU × CPUs     (starting value 8)
```

applied to containers too. The CPUs are the cgroup's `cpu.max` quota when one
is set (a site's `--cpus`), else `nproc`. A fractional quota counts as it is
(0.5 CPU gives 4, not 0), and the minimum wins over the ceiling, so a tiny
container still has its minimum. On a shared host this only bites a
site with a budget large enough to exceed it. jeremytunnell.com: min(24, 8) = 8.

### WP3. One pool per site on a shared machine

On a bare-metal machine with more than one site, each site gets its own pool:

- `pool.d/zz-joinery-{site}.conf`: `[{site}]` with its own socket
  `/run/php/php{ver}-fpm-{site}.sock`, the same user and group as today
  (`www-data`; a separate user per site is not this spec's), `pm = ondemand`,
  and only what `ondemand` reads: `pm.max_children` (its share),
  `pm.process_idle_timeout`, `pm.max_requests`. No start or spare figures,
  which `ondemand` ignores (the container drop-in's are trimmed the same way).
- The site's vhost names its socket, through a new `{{PHP_FPM_SOCK}}`
  placeholder every renderer of `default_virtualhost.conf` fills (install.sh,
  `_site_init.sh`, `render_vhost.sh`, `manage_domain.sh`,
  `virtualhost_update_script.sh`, `reconcile_site.sh`). Each pool also gets its
  own status listener (`slow_request_diagnosis_words.md` built one fixed
  `pm.status_listen` for the single pool), and the vhost's `/fpm-status`
  Location names that pool's: a `<FilesMatch \.php$> SetHandler "proxy:unix:{{PHP_FPM_SOCK}}|fcgi://{{SITE_NAME}}"`
  in the site's vhost, over the global handler in
  `conf-enabled/php{ver}-fpm.conf` (which stays, for anything outside a site
  vhost). The vhost's Files-type section merges after the main server's, so it
  is the one in force; the `static_files` guard is a `Require all denied` and
  stays in force under it. WP3's `/fpm-status` Location then reads that site's
  own pool.
- The packaged `[www]` pool stays, sized to the minimum, for the global
  handler.
- Each pool's backend is named for its site,
  `proxy:unix:/run/php/php{ver}-fpm-{site}.sock|fcgi://{site}`, never
  `fcgi://localhost` on every vhost, so mod_proxy cannot key two sockets to one
  worker name. `slow_request_diagnosis_words.md`'s `/fpm-status` Location uses
  the same string.
- **A test site shares its main site's pool.** A site named `{main}_test`
  whose `{main}` is on the same machine gets no pool of its own: its vhost's
  `{{PHP_FPM_SOCK}}` names `{main}`'s socket. A test site is idle almost all the
  time, and a pool of its own would hold back workers the real site could use
  (owner, 2026-10-23). The cost is accepted: a test site hammered at the same
  moment as the real one competes with it for the same workers. A `_test` site
  with no `{main}` on the machine is a site like any other.
- **The split.** Only pools count: the real sites. One real site gets all the
  workers. Several real sites share them equally, each with at least
  `MEMORY_PLAN_MIN_CHILDREN`, which rises from 2 to 3 for every site,
  containers included: an AI chat turn holds a worker for its whole run
  (`ChatAsync` detaches with `fastcgi_finish_request` and then
  `set_time_limit(0)`, `ChatAsync.php:37,52`), so on two workers one chat is
  half the site. jeremytunnell.com at 8: one pool, `jeremytunnell`, 8 workers,
  serving both jeremytunnell and jeremytunnell_test.
- **The workers are a ceiling, not a reservation**: `ondemand` starts a worker
  only when a request needs one and lets it go after 10 s idle, so the sum
  across pools is what the machine can hold at once, and the split decides who
  gets it when several real sites are busy.

- **This spec owns `zz-joinery-status.conf`** (written today by
  `host_housekeeping.sh` 1.31 for `slow_request_diagnosis_words.md`: a
  `[global]` `process_control_timeout = 10s`, and a `[www]` section with
  `pm.status_path` and `pm.status_listen`). When pools are named per site, that
  `[www]` section must go in the same change, or move to the per-site pool
  files: a `[www]` section with status keys and no `listen` is a fatal pool
  error once `www` is renamed, and a reload under a bad configuration leaves
  PHP-FPM down. The tuner writes each pool's own `pm.status_listen`; the
  `[global]` line stays.

PostgreSQL's `max_connections` = the sum of every pool's workers + 20, written
by `tune_postgres_memory.sh` from the same plan (it then writes it on bare metal
too).

### WP4. Who runs it, and when

**A reload must actually be graceful.** `php-fpm.conf` leaves
`process_control_timeout` at its default 0, so on a reload the master sends
QUIT and then TERM at once, and a child on TERM drops its request: today a
reload cuts every request in flight exactly as a restart does. The tuner writes
`process_control_timeout = 10s` in a `[global]` section of its drop-in
(`pool.d/*.conf` is included from `php-fpm.conf`; that a `[global]` section
there is honoured is verified on the test box in the measurement step), and
changes PHP-FPM with `systemctl reload`, never `restart` (`tune_php_fpm.sh`
uses `restart` today, line 132).

**PostgreSQL is never restarted by the converger.** `max_connections` and
`shared_buffers` take a PostgreSQL restart (`tune_postgres_memory.sh:207`), and
`host_housekeeping.sh` runs under the host converger, which ticks every minute.
So:

- `install.sh` (bare metal, line 3937's block) runs both tuners after the
  site's vhost exists, and restarts both.
- `install.sh site` and `remove_account.sh`, the moments the site count
  changes and already disruptive, run both tuners and restart PostgreSQL.
- `host_housekeeping.sh` runs both on every run on a host that is not a
  container, with `--no-restart` for PostgreSQL: it writes the drop-in and
  reports "PostgreSQL restart pending" (WP5 shows it) until the next restart
  someone chooses. PHP-FPM's graceful reload stays in housekeeping. Both
  tuners are idempotent: nothing changed, nothing written, nothing reloaded.
- Measurement before shipping: on a 2 GB test box with the mailbox plugin and
  rspamd, at rest and during a backup and an `update_database`, record what is
  not a site (the reserve) and the largest PHP worker under a real mailbox page
  and a Drive upload. The constants take those figures.

### WP5. Visible on the node page

`host_report` (script word, no agent release) carries, per pool: name,
`pm.max_children`, and where the figure came from (`plan` with budget and
source, or `packaged` when no drop-in exists); the reserve constant beside the
non-site resident set actually measured; and "PostgreSQL restart pending" when
a drop-in is newer than the cluster's start. Numbers and names only: a pool
file's lines are never sent (a pool can carry `env[]` values and
`listen.owner`). The node page's Health block
shows "PHP workers: jeremytunnell 8, shared with jeremytunnell_test (from
1,369 MB, 1 CPU)", and names a pool still on the packaged 5. A site container whose
tuner refused (no memory limit) shows the same "packaged" line, which today it
does not say.

## What is not in this spec

- Container budgets. They exist (`mgh_site_memory_mb`, 384 MB on a shared
  host) and are sized by the same plan; WP2's CPU ceiling is the only change
  that reaches them.
- A separate Unix user per site on bare metal. A real isolation gain, and a
  file-ownership migration; its own spec if wanted.
- Whether 5 workers caused the 2026-10-23 stalls. The evidence says no (direct
  requests were fast at the same moment); this is a ceiling nobody chose, fixed
  because it is wrong, not because it is today's cause.

## Open questions

None open. Settled: a `_test` site shares its main site's pool (owner,
2026-10-23); real sites split equally; the CPU ceiling applies to containers
too.

## Tests

- `_memory_plan.sh` unit test (bash, the existing pattern): the bare-metal
  budget with and without rspamd; the CPU ceiling; the split for 1, 2 and 3
  real sites; a `_test` site with its main site on the machine adds no pool and
  renders its main site's socket, one without gets its own; minimum 3 each when the budget is small; a 0.5 CPU quota gives 4.
- `tune_php_fpm.sh --dry-run` on a fixture `pool.d` with two real sites writes two
  pools with the right sockets, the `[global]` `process_control_timeout`, no
  start or spare figures, and leaves `www.conf` alone; a second run writes
  nothing. `tests/integration/tune_php_fpm_gate.sh` (which expects one `[www]`
  section today) is rewritten for per-site pools.
- Housekeeping never restarts PostgreSQL: a gate test runs it with a changed
  plan and asserts a write, a "restart pending", and no restart.
- On the test box: a reload with a 20 s request in flight lets it finish.
- `tune_postgres_memory.sh --dry-run` on bare metal writes `max_connections` =
  sum + 20.
- The vhost template renders `{{PHP_FPM_SOCK}}` per site; the existing vhost
  template test covers the new `FilesMatch`.
- A live check on a test box: two sites, one held busy with slow requests,
  the other still answers.

## As built (2026-10-24)

- **Code review** (reviewer2, 2026-10-24): F1-F7 all taken. F1: a rebuild of a site already
  on the machine is never refused, even on a machine that took two before the rule. F5:
  `php_workers.source` is `overridden` when a later pool file sets another figure. F6: a
  container on the packaged pool is told to get a memory limit, not to wait for housekeeping.
- **WP3 reduced.** A machine runs one site directly: the vhost template (2.08) and the fleet's
  layout already said so, and a shared machine runs each site in a container with its own
  budget. So no per-site pools, no `{{PHP_FPM_SOCK}}`, no renderer changes; `install.sh site`
  (3.31) refuses a second site directly on a machine, naming the one there
  (`other_baremetal_site`). The test site is served from the main site's vhost through the one
  `[www]` pool, so it shares the workers with nothing to build. The minimum of 3 workers is
  kept, containers included. `install.sh site` and `remove_account.sh` run no tuner: the site
  count on a machine no longer changes the plan.
- **WP1** `_memory_plan.sh` 1.1.0: reserve 384 + 128 with rspamd (`/usr/bin/rspamd`) + the
  office editor's `MemoryMax` from its drop-in. A machine smaller than its reserve keeps a
  256 MB budget, so the floors still apply. The constants were not measured on a test box.
  rspamd is 128, twice dev's measured 62 MB: at 256 a 1 GB mailbox box fell to the minimum 3
  workers and a 64 MB shared_buffers (reviewer2 F2); at 128 it keeps 5 and 89 MB. On 2 GB
  boxes the CPU ceiling binds first (memory gives 26, the ceiling 8). The host report shows
  the measured figure, and a 1 GB mailbox box's reading is in the live queue.
- **WP2** `memory_plan_cpu_milli`: `MEMORY_PLAN_CPUS`, else `nproc` lowered by `cpu.max` (or
  cgroup v1's quota), in thousandths.
- **max_connections on bare metal is not written** (the spec had sum + 20). The packaged 100
  already holds the plan's most (80 + 20), and writing less only adds risk. Instead
  `tune_php_fpm.sh` 1.1.0 holds the pool to the configured `max_connections` less 20 where a
  hand set it lower: dev's own `50-minimal.conf` sets 25, and 32 workers would have run it
  out of connections. The read uses `setpriv` (no PAM session, so no auth.log pair every
  minute; reviewer2 F4), and a failed read leaves an existing drop-in as it is, warned by
  housekeeping, rather than lifting the cap for a tick (F3).
- **WP4** `tune_php_fpm.sh` reloads, never restarts. `tune_postgres_memory.sh` 1.5.0 gains
  `--reload` (write, then reload; a changed `shared_buffers` is `pending_restart`), which
  host housekeeping 1.32 (4b) uses on every run on a machine that is its own site, from the
  site's own tree. `install.sh server` runs the PHP tuner beside the PostgreSQL one. The
  `[global] process_control_timeout = 10s` stays in housekeeping's `zz-joinery-status.conf`
  (written since 1.31); the tuner writes no `[global]`.
- **WP5** `host_report` 1.39 `php_workers`: version, `max_children`, `plan` or `packaged`, the
  plan's `budget_mb`, `plan_source`, `reserve_mb` and `cpus` (from the drop-in's
  `; joinery-plan:` line), `non_site_rss_mb` by PSS (`smaps_rollup`, root only; RSS
  double-counts shared pages), and `pg_pending_restart` names from `pg_settings` (read over
  peer auth, no file date: a hand `conf.d` file can override the drop-in). JobResultProcessor
  1.107 keeps it; the node page (overview 1.87) shows **PHP workers** in Health.
- `ImageDecoder` still sizes its decode ceiling from `MemTotal` without the reserve: it is a
  per-decode upper bound under the 160 MB setting, and the reserve would lower it only on
  machines where the setting already binds.

