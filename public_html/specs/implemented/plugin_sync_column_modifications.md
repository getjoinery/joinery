# Plugin sync — apply column modifications to plugin tables

**Status:** Ready to implement.
**Touches:** `includes/PluginManager.php` (`sync()`), one docs paragraph.
No schema, no new files.

## The gap

A plugin data class's `$field_specifications` is supposed to be the whole
truth about its table — change the spec, run "Sync with Filesystem", and the
database follows. Today that's only true for *additions*. The plugin sync
pipeline (`PluginManager::sync()`, `includes/PluginManager.php:1135`) runs:

1. `DatabaseUpdater::runPluginTablesOnly()` — creates missing tables, adds
   missing columns (`runUpdate()`'s two passes), nothing else;
2. `manageUniqueConstraints()` + `manageIndexes()` over the plugin's
   discovered model classes;
3. pending migrations, deletion rules, menus, settings.

What never runs for plugin tables is
`DatabaseUpdater::processAdvancedColumnOperations()` — the pass that alters
an *existing* column to match its spec: type changes/widening (e.g.
`varchar(100)` → `varchar(255)`) and nullability changes (`SET NOT NULL` /
`DROP NOT NULL`). Core tables get it from `utils/update_database.php:162`,
gated on `$upgrade || $cleanup` — but that call site discovers classes with
`include_plugins => false`, so plugin classes are structurally excluded.
Result: widening a column in a plugin data class silently does nothing on
sync, and the change has to be applied by hand-written SQL (which has
already happened once).

## The fix

One call added inside `sync()`'s per-plugin loop. The pieces are all already
there:

- `sync()` already constructs its updater as
  `new DatabaseUpdater(false, true /* upgrade */, false)` — `upgrade=true`
  is exactly the gate `processAdvancedColumnOperations()` honors for
  modifications (`processTableColumns()` checks `$this->upgrade ||
  $this->cleanup`), and `cleanup=false` means the column-*drop* path
  (`processColumnCleanup()`) can never fire. Keep both flags exactly as they
  are.
- The constraint pass already discovers the plugin's model classes
  (`LibraryFunctions::discover_model_classes([... 'plugin_filter' =>
  $plugin_name])`) into `$plugin_classes`.

Inside the existing `try` block, after that discovery and **before**
`manageUniqueConstraints()` (mirroring core order: create/add → modify →
constraints → indexes), add:

```php
// Column modifications (type/length widening, nullability) for existing
// plugin columns — the same pass core tables get from
// update_database --upgrade. upgrade=true / cleanup=false on this updater
// means specs are applied but columns are never dropped here.
$advanced_result = $database_updater->processAdvancedColumnOperations($plugin_classes);
if (!empty($advanced_result['messages'])) {
    $table_messages = array_merge($table_messages, $advanced_result['messages']);
}
foreach (($advanced_result['errors'] ?? []) as $error) {
    $table_messages[] = "$plugin_name: column modification error - $error";
}
```

That is the entire code change. Nothing else in `sync()` moves.

## Pinned behavior decisions

- **Modifications on, drops off — not configurable.** Plugin sync runs
  implicitly (admin "Sync with Filesystem" button, `update_database`'s final
  plugin step, deploy via `upgrade.php`), so it gets the safe subset:
  `upgrade` semantics always, `cleanup` (dropping columns absent from the
  spec) never. Column removal for plugin tables stays a deliberate
  migration-or-manual act, same as before this fix.
- **Failures report, never abort.** `processAdvancedColumnOperations()`
  returns errors in its result array (e.g. `SET NOT NULL` on a table with
  existing NULL rows, a cast Postgres refuses on populated data) — surface
  them in `$table_messages` exactly like constraint errors; do not throw.
  A failed modification leaves the column as it was, sync completes, and
  the error is visible in the sync output.
- **No new flags, settings, or UI.** The admin sync button's output already
  displays `table_messages`; the new messages ride along.

## Verification

1. `php -l` + `validate_php_file.php` on `includes/PluginManager.php`.
2. Convergence test against the live dev DB: pick a plugin column whose spec
   and live type already match (any `varchar` on `iem_inbound_email_messages`),
   run "Sync with Filesystem", confirm no modification message is emitted
   for it (the pass is idempotent — no-op on matching columns).
3. Modification test: temporarily widen one plugin class's `varchar` length
   in `$field_specifications`, run sync, confirm the
   "Modified column type" message and the new length in
   `information_schema.columns`; revert the spec, run sync again, confirm it
   narrows back. Use a column on a low-stakes plugin table, not a content
   column.
4. Confirm core behavior is untouched: `utils/update_database.php` is not
   modified by this spec.

## Docs

`docs/plugin_developer_guide.md`, the plugin schema/sync section: state that
plugin sync applies the full column reconciliation — new tables, new
columns, type/length and nullability modifications, unique constraints, and
indexes — and that column *removal* is the one schema change sync never
performs. Current-state voice only.
