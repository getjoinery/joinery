# Minimal test database

**Status:** built 2026-08-15. Open: `update_database` to land migration 174
(the menu gate on existing installs), and a live check of the debug-off refusal.

Two things the design did not anticipate, both found while building and both
handled in the code:

- `pg_get_serial_sequence()` answers from the OWNED BY dependency, and
  `DatabaseUpdater` creates sequences without one — so it returns NULL for every
  column on this platform, in live as well as in the copy. The sweep reads the
  `nextval(...)` column default instead. Built the first way, it silently did
  nothing and the duplicate-key collision happened exactly as predicted.
- The exact-match backup exclusion is still not enough on its own, because the
  two names can **collide**: this development box had `dbname_test =
  joinerytest_test` beside a site directory whose `dbname` was also
  `joinerytest_test`. Live always wins, in the backup sweep and in the copy —
  which now refuses to rebuild a database that is some other site's live one.

## The problem in plain terms

The test database is supposed to be a place to run the model suites. What it
actually is, is a second copy of everything the site holds — every mail body,
every sealed blob, every visitor event, every order. That was survivable while
the live database was a few hundred megabytes. It stops being survivable the
first time somebody imports a real mail archive: the copy doubles the disk
footprint, doubles what a full-machine backup sweep has to encrypt and ship,
and doubles the number of places a customer's mail exists at rest.

None of that buys anything. The three suites that need the copy generate their
own rows.

## What is true today

| Fact | Where |
|---|---|
| The copy is `pg_dump live \| psql test` with no filters of any kind | `adm/admin_test_database.php:214` |
| It is the only thing that ever populates a test database — no cron, migration, deploy or upgrade path calls it | `copyLiveToTest()` has exactly one caller, the POST handler in the same file |
| The **Test Database** admin page ships on every install at permission 10 | `admin_menus.json` slug `test-database`; also seeded by `migrations/migrations.php:289` |
| Every install's config declares a `dbname_test` | `maintenance_scripts/install_tools/default_Globalvars_site.php:33` |
| A missing test database is no obstacle — the copy creates one from nothing | `dropdb --if-exists` + `createdb` into staging, then rename |
| `backup_database.sh` with no argument backs up **all** non-system databases | `backup_all_databases()`, `SELECT datname FROM pg_database …` |
| The fleet's scheduled backups dump a single named database, so they are already safe | `plugins/server_manager/includes/JobCommandBuilder.php` |

Surveyed 2026-08-15: no managed node has a test database. `getjoinery`,
`joinerydemo`, `jeremytunnell-vps`, `galactictribune` and `scrolldaddy` each
hold exactly one database, the site's own. Development holds `joinerytest`
1.3 GB and `joinerytest_test` 1.07 GB.

So this is a latent exposure, not an active one. It becomes active the first
time a superadmin on a production node clicks a button that is sitting in their
menu today.

## The rule

> **A test database is a schema with the configuration needed to boot. It is
> never a copy of anyone's content.**

Content lives in exactly one place. If a copy of production content is what a
particular debugging session needs, that is a separate, deliberate, named act —
not the default behaviour of the button everybody clicks after a schema change.

## What the copy is actually for

Three suites declare `needs: [test-db]`: `tests/models/models_test.php`,
`tests/models/multi_models_test.php`, `tests/models/model_tester_selftest_test.php`.

They do not read production rows. `ModelTester` resolves every foreign-key
field by **creating a fresh parent row** through the target model, recursively,
and removing it afterwards — precisely so results do not depend on which ids
happen to exist. `MultiModelTester` drives collections over rows it generated.
What these suites need from the copy is the schema and its constraints: tables,
columns, primary keys, and the foreign keys `update_database` materializes on
live.

They also need the site to *boot* in test mode, and that is a real data
dependency, easy to miss. `Globalvars::get_setting()` falls through to
`SELECT stg_value FROM stg_settings` on `DbConnector::get_db_link()`
(`includes/Globalvars.php:41-54`), which after `set_test_mode()` is the **test**
connection. Values are cached per setting on first read, so any setting first
read after entering test mode is answered by the copy. The same is true of the
plugin registry, which decides whether a plugin's classes resolve at all.

That is the whole dependency: schema, plus a handful of small configuration
tables.

## Design

### 1. Structure-first copy, by default

Replace the single unfiltered pipeline with three steps into the same staging
database, keeping the existing staging-then-rename swap, `ON_ERROR_STOP` and
`pipefail` discipline exactly as it is:

1. `pg_dump --schema-only` of live → staging. Full structure: tables, columns,
   indexes, primary keys, foreign keys, views, functions.
2. `pg_dump --data-only --table=…` for each table on the reference allowlist →
   staging.
3. A sequence sweep (below).

Everything not on the allowlist arrives as an empty table with its constraints
intact. Measured on the current development database, step 1 is 314 KB of SQL
against a 1.3 GB live database.

### 2. The reference allowlist

One list, owned by `TestDatabaseHelper` as a static method so the admin page and
anything later added (a CLI provisioner, an installer step) read the same
definition. Starting contents:

| Table | Why it must carry data |
|---|---|
| `stg_settings` | `get_setting()` reads it through the test connection once test mode is entered |
| `plg_plugins` | Decides which plugins are active, and therefore which classes resolve |
| `amu_admin_menus` | Cheap, and keeps an admin page rendered under test from looking broken |
| `zone` / `timezone` | IANA reference data; time conversion needs it |
| `cco_country_codes` | Reference data |
| `emt_email_templates` | Reference data; anything exercising the send path reads it |

This is the same judgment `utils/create_install_sql.php` already makes for
installers with its `$essential_tables` array. The two lists serve different
jobs and should stay separate — an installer must not ship the developer's
settings row — but the reasoning is shared, and a table added to one is worth a
glance at the other.

**Guard against silent regrowth.** Before dumping an allowlisted table, check
`pg_total_relation_size`. Over a threshold (50 MB is the right order of
magnitude), fail the copy naming the table. An allowlist is a promise that
these tables are small; a table that quietly grows into a content table would
otherwise re-inflate the copy with nobody noticing. Failing loudly turns that
into a one-line decision — either it comes off the list, or the threshold moves
deliberately.

### 3. The sequence sweep

A full `pg_dump` emits `setval` for every sequence (232 of them on the current
development database). Neither `--schema-only` nor `--data-only --table=X`
emits any. Left alone, a seeded table's sequence sits at 1 while rows occupy
ids 1..N, and the first insert into it fails on a duplicate key.

After step 2, run one sweep over the staging database: for every sequence owned
by a column of a table that was seeded, `setval` to that column's `max()`.
Sequences on the empty content tables are correctly left at their start value —
ids in the copy will not match live ids, which is fine and always was, because
the suites make their own rows.

### 4. Full copy as a separate, deliberate action

Keep the ability to take a real copy, as a second button, clearly named, with
the live database's current size rendered next to it so the cost is visible at
the moment of choosing. This is the escape hatch for "I need to reproduce this
against real data", and it should stay available — on development.

### 5. Off on production

Menu visibility is presentation, not a control. Both are needed:

- The `test-database` menu entry is hidden when the `debug` setting is off.
  `tests/index.php:25` already uses `debug` as the development/production
  discriminator; this is the same question, answered the same way.
- **The POST handler refuses** when `debug` is off, before any of the safety
  checks it already performs. A direct request to `/admin/admin_test_database`
  with `action=copy_live_to_test` on a production node does nothing but return
  the refusal, whatever the menu shows.

The refusal message should say what it is protecting, not just that it refused
— a superadmin who genuinely wants this on a production box should be able to
tell from the page that the answer is "turn on `debug`", not "this is broken".

### 6. Keep test copies out of the all-databases backup sweep

`backup_database.sh` invoked with no database name backs up everything on the
machine. A test copy that does exist should not be dumped, encrypted, and
shipped to the bucket.

**The exclusion must be precise, and a glob is not.** The installer's
`create_test_site()` provisions a whole separate site named `${main_site}_test`,
with its own database of that name and its own real content. Excluding
`%_test` would silently drop a real site from backups — a far worse defect than
the one being fixed.

So: discover the exclusion set by reading the `dbname_test` value out of each
`/var/www/html/*/config/Globalvars_site.php` on the box, and skip only exact
matches. The script already walks those config files to find a password, so the
mechanism is present. List what was skipped, and why, in the run's output —
a database silently missing from a backup summary is how a backup gap survives.

## What this does not change

- The staging-database restore and the terminate/drop/rename swap stay as they
  are. They were built to keep a failed restore from destroying the previous
  copy, and that is orthogonal to what goes into the copy.
- The runner still probes the test connection rather than trusting
  `dbname_test`, so a checkout with no copy skips the three suites with a named
  reason (`tests/run.php:170-186`).
- The copy still does not receive `update_database`; resyncing after a schema
  change is still the workflow. It just becomes a cheap operation.

## Acceptance

1. A structure-first copy of the development database produces a test database
   whose size is single-digit megabytes, against a live database of 1.3 GB.
2. `php tests/run.php test-db` passes against that copy — all three model
   suites, including foreign-key resolution, which proves constraints came
   across.
3. Inserting into a seeded table (`stg_settings`) in the copy succeeds rather
   than colliding on a duplicate key, proving the sequence sweep.
4. A content table (`iem_inbound_email_messages`, `msg_messages`,
   `vse_visitor_events`) exists in the copy, has its constraints, and has zero
   rows.
5. Raising an allowlisted table past the size threshold fails the copy with a
   message naming that table.
6. With `debug` off: the menu entry is absent, and a direct POST of
   `action=copy_live_to_test` performs no database operation.
7. `backup_database.sh` with no argument on a box with both a `foo_test` **site**
   and a `foo` site whose configured `dbname_test` is `test_foo` backs up
   `foo`, `foo_test`, and skips `test_foo`, naming the skip in its summary.

## Documentation (written at build time, current-state only)

`docs/testing.md` § test-db tier — currently tells the reader to resync from
`/admin/admin_test_database` after a schema change. Rewrite to describe what the
copy is: structure plus configuration tables, no content, development only, and
why the model suites do not need content. Fold in the sequence behaviour only if
a reader could trip over it.

`docs/backups.md` — note that the all-databases sweep skips configured test
copies, so nobody reads a backup summary and concludes a database went missing.

`CLAUDE.md` (through `/admin/admin_agent_files`, never on disk) § Tests — one
clause noting the test database carries no content, so nothing should be written
that expects to find real rows there.
