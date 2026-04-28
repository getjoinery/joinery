# Database Cleanup

## Goal

Remove dead reference tables and prevent
unused plugin tables from being created on sites that don't have the
plugin active. Reduce per-site Postgres footprint, backup size, WAL
churn, and replication load — without changing any live behaviour.

**Out of scope:** `bld_blocklist_domains` on the ScrollDaddy site.
That table is the largest single object in the fleet (~593 MB / 4.3M
rows) but it is actively used by the ScrollDaddy DNS resolver and the
DownloadBlocklists task. It is intentionally excluded from this spec.

## Current State (audited 2026-04-27 across 8 prod containers)

### Dead reference tables still present

| Table | Status | Per-site size | Fleet impact |
|---|---|---|---|
| `timezone` (zone_id, abbreviation, time_start, gmt_offset, dst) | Dead — no code reads it | 9.7 MB on 6/8 sites | ~58 MB |
| `country` (country_code, country_name) | Dead — superseded by `cco_country_codes` | trivial | trivial |

Both have drop migrations already declared in
`migrations/migrations.php` at version 67 (lines 915–929), but the
migrations have never executed. The `test` clause on each is inverted:

```sql
-- Current test (skips drop while table still exists):
SELECT count(1) as count FROM pg_tables
  WHERE tablename = 'timezone' AND schemaname = 'public'
```

The migration runner in `data/migrations_class.php:196` skips when
`count > 0`. Because the table exists, count is 1, so the drop is
permanently skipped. The test needs to be inverted (skip *when the
drop has already been done*, run *when the table is still present*).

### Unused-but-installed plugin tables

`bld_blocklist_domains` exists empty (0 rows, ~50 KB heap) on every
non-ScrollDaddy site because plugin schema sync (`PluginManager::sync()`)
creates the tables for every plugin that is *installed*, regardless
of whether the plugin is *active* on that site.

The footprint per empty plugin table is small in absolute terms, but
this is a fleet-wide pattern that will compound as more plugins ship
with their own tables.

### Considered and rejected

- **`zone`** (timezone-name dropdown, ~425 rows, ~100 KB per site).
  Used by `Address::get_timezone_drop_array()` and powers timezone
  dropdowns on register / profile / event-edit / etc. Could become
  a static PHP array, but ~1 MB fleet-wide isn't worth the refactor.
- **`cco_country_codes`** (~225 rows, <100 KB per site). Used as
  FK target on `usa_addrs` and `phn_phone_numbers`. Tiny; leave it.

## Changes

### 1. Fix the inverted migration test for `timezone` and `country` drops

`migrations/migrations.php`, lines ~917 and ~925: invert the `test`
clause so the drop *runs* when the table is still present and *skips*
when it has already been dropped.

The cleanest pattern is to change the test to a dummy that always
forces "needs to run", since the migration is itself idempotent
(`DROP TABLE IF EXISTS`). Or align with the runner's semantics by
checking the *absence* of the table:

```sql
-- Run drop while table still exists (count of pg_tables with
-- this name AND already-dropped marker = 0)
SELECT count(1) as count FROM pg_tables
  WHERE tablename = 'timezone_dropped_marker'
```

Either approach works. The current code is the only place this
specific bug occurs, but the migration runner contract should be
documented in `docs/deploy_and_upgrade.md` so future drop-style
migrations don't repeat it.

After the fix, the next deploy applies the drops automatically via
`update_database` during upgrade.

### 2. Make plugin schema sync conditional on plugin activation

`PluginManager::sync()` should only create / sync tables for
plugins that are *active* on the current site, not merely installed.
On deactivation, tables should be left in place (data is preserved
in case the plugin is reactivated) but no longer synced; on
uninstall, the existing destructive uninstall path already removes
tables, so no change there.

Concretely:
- During `sync()`, iterate active plugins only when calling
  `update_database` for plugin-owned data classes.
- The "Sync with Filesystem" admin action should match — it should
  not create tables for inactive plugins.

This is the platform-level fix and the largest behavioural change
in this spec. It makes plugin-as-feature-flag actually mean
"feature absent on disk" for sites that don't use it.

**Migration consideration:** for sites that already have empty
plugin tables they shouldn't have, a one-time sweep can be run
post-deploy: for each inactive plugin, drop its tables if they
contain zero rows. This is opt-in; default is to leave existing
tables alone.

### 3. Update existing developer docs

Per project convention (specs add to existing docs, not new ones):

- `docs/deploy_and_upgrade.md` — document the migration `test`
  semantics: "test returns count > 0 → migration is **skipped**;
  count = 0 → migration **runs**." Add an example of a correct
  drop-table migration.
- `docs/plugin_developer_guide.md` — document the new conditional
  sync behaviour: plugin tables exist on disk only while the
  plugin is active. Schema changes to a plugin's data classes are
  applied on next sync after activation.

## Validation

After deploying:

1. Run the audit script (`/tmp/prod_table_audit.sh`, kept in
   `/tmp/` on docker-prod from the 2026-04-27 audit) against all
   prod containers and confirm:
   - `timezone` no longer present on any site.
   - `country` no longer present on any site.
   - `bld_blocklist_domains` only present on the ScrollDaddy site.
2. Smoke test register / profile / event-edit pages to confirm
   timezone dropdowns still populate (`zone` table is untouched).

## Estimated Payoff

- **~58 MB** dead `timezone` data removed across the fleet
  (data + indexes + WAL on every backup).
- **Future bloat avoided:** plugin tables stop appearing on
  sites that don't use the plugin.
- **No live behaviour change.**

## Non-Goals

- Touching `bld_blocklist_domains` storage strategy — handled
  separately if at all.
- Reducing operational table size (`vse_visitor_events`, etc.) —
  separate retention-policy conversation.
- Refactoring `cco_country_codes` or `zone` into static arrays —
  too small to justify the change.
