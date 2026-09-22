# Test Worker Database Isolation

## Problem

The pre-deploy gate (`php tests/run.php db`) takes about 12½ minutes on the dev
box. It was about 5 in early August. Most of the growth is suite count: 221
suites on 2026-08-03, 473 on 2026-09-22. The runaway waits inside single suites
have been removed (see "Where it stands" below). What is left is a long tail of
suites that each earn their time.

Suites run one at a time, and in the gate the box keeps only about one of its
four cores busy. Running them several at a time is the only way back to about
5 minutes. The obstacle is that they share state. A scan of the whole
estate on 2026-09-22 found:

| Shared thing | Suites | Their time |
|---|---|---|
| write the dev database (fixture rows, rolled-back transactions) | most of the 242 db-tier suites | ~400s |
| rewrite site-wide settings rows | 25 | 38s |
| assert on mail in the one shared test inbox | 38 | 39s |
| count a whole table before and after | 10 | 35s |
| drive the dev web server (Apache reads the dev database) | 14 | 28s |
| run system tools, services or sudo | 25 | 72s |
| create databases | 9 | 55s |

Labelling suites "safe to run in parallel" was rejected: a label is a promise
nobody re-checks, and when it breaks the result is a rare, unrelated-looking
red. The two flaky suites root-caused on 2026-09-22 cost more to chase than
the time they cost.

## Idea

Make collisions impossible instead of promising they will not happen. Each
parallel worker gets **its own database**, and every suite that worker runs
does all its database work there. Two suites in different workers then share
no rows, no settings and no table counts, whatever their code does.

This is the reasoning that already makes the test-db lane safe to run beside
the main batch (`specs/implemented/test_db_lane_overlap.md`), applied to
workers instead of to one lane.

## Design

### Worker databases

- The runner creates `N` worker databases (`{dbname_test}_w1` … `_wN`, N = 3 to
  start) with the same structure copy `TestDatabaseHelper::copy()` makes for
  the test database: schema plus the reference tables (settings, plugin
  registry, menus). They are rebuilt when behind live, as the test database is.
- A worker process points its **whole process** at its database, not just the
  test-mode connection: `Globalvars`/`DbConnector` read the database name from
  an override the runner sets in the child's environment (for example
  `JOINERY_TEST_DBNAME`), honoured only on the CLI and only when the harness
  booted. Settings, models and raw PDO all land there.
- `harness_finish()` checks the suite really ran on its worker database (the
  same shape as the test-db lane's `test_mode_was_used()` guard). A suite that
  opened its own connection to the dev database fails its own run.

### What stays serial on the dev database

Database isolation does not isolate everything. These run in the existing
serial batch, after the pool:

- **Web suites** (`needs: [dev-web]`): Apache reads the dev database, not the
  worker's.
- **Mail suites**: delivered mail is stored by the inbound pipeline in the dev
  database.
- **System suites**: services, sudo, installers, host scripts.
- **Suites that need real content**: the worker copy carries none, as the test
  database carries none.

Which suites fall where is measured, not declared (WP1). The runner decides
from the measurement plus header facts it already has (`needs:`, tier), so
there is no new promise for an author to keep.

### Filesystem

Worker databases do not separate files. Suites already write under
per-process temp names (`sys_get_temp_dir() . '/x_' . getmypid()`), and
2026-09-22 found one exception (`disk_space`, which compares free space).
Shared site paths (`cache/`, `logs/`, backup storage, uploads) need their own
inventory in WP1 before a suite using them is pooled.

## Work packages

- **WP1: Measure.** Build the worker database and the dbname override, then run
  every db-tier suite alone against a worker database and record pass or fail.
  List each failure with its reason: needs content, needs the web server,
  needs mail, touches a shared site path, other. **Stop point:** the owner
  reads the report and decides whether the poolable share is worth WP2. If it
  is small, the spec ends here.
- **WP2: Pool.** Run the poolable db-tier suites N at a time, one per worker
  database, alongside the existing `parallel: true` safe-tier pool. Add the
  `harness_finish()` guard.
- **WP3: Prove.** Five back-to-back full gates with the pool, zero
  pool-induced reds, compared with `--serial` for the same tree. `--serial`
  stays as the fallback and for debugging.

## Where it stands (2026-09-22)

- Gate cut from 18m52s to about 12m45s by removing waits: the sync simulator
  gate built optimised (252s → 80s), two backup suites stopped dumping the dev
  database (61s and 57s → 3s), `InstallJobExecutor`'s SSH probe bounded by its
  budget (45s → 8s), and a compile cache shared across suites.
- `parallel: true` exists for safe-tier suites only. Those suites run three at
  a time, their database session is read-only, and they skip the harness's
  shared cleanup passes. This spec extends pooling to the db tier by
  isolation rather than by more labels.

## Open questions

- Q1: N. Three workers plus the test-db lane fill four cores. Other Claude
  sessions share the box, so the gain under load needs measuring in WP3.
- Q2: whether the test-db lane folds into this (it is a worker database of one)
  or stays as it is.
