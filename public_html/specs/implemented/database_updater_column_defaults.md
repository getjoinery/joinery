# Database updater — apply declared column defaults in the database

**Status:** Ready to implement.
**Touches:** `includes/DatabaseUpdater.php` only (three spots), one docs
paragraph. No schema classes, no new files. Plugin tables inherit all three
changes automatically (plugin sync runs the same code paths).

## The gap

A data class declares a column default in `$field_specifications`
(`'default' => 'confirmed'`, `'default' => false`, `'default' => 'now()'`),
and today that default lives **only in PHP**: `SystemBase::save()` applies
it to unset fields on a new row (`includes/SystemBase.php:1259`), so every
model-path insert gets the right value. The database itself never learns
the default:

- **`createTableIfMissing()`** emits `DEFAULT` only for serial primary keys
  (`includes/DatabaseUpdater.php:~190`) — every other column is created
  bare.
- **`addMissingColumn()`** (`:~619`) emits `ADD COLUMN name type` — no
  default, ever.
- **`processColumnModifications()`** reconciles type, length, and
  nullability (backfilling NULLs from the declared default before `SET NOT
  NULL` — the rendering helper `defaultToSqlLiteral()` at `:~601` already
  exists for exactly this) but never runs `SET DEFAULT`.

The result is a landmine for every **raw-SQL** writer — and raw inserts
into model tables are an established pattern (`RecipeRunner`, `CostGuard`,
`seo_page_metadata_class`, `event_sessions_class`, blocklist ingest, and
more): add a `NOT NULL` + `default` column to a table later, and every raw
`INSERT` that predates the column starts failing with a NOT NULL violation,
even though the class declares a perfectly good default. It also leaves the
live schema drifted and ambiguous — a few columns carry hand-applied
defaults (`iem_key_generation`, `iem_raw_storage_driver`,
`cal_entries.cal_status`), most carry none, and nothing reconciles them.

Model-path inserts are unaffected throughout (SystemBase fills the value
client-side), which is why this went unnoticed.

## The fix

Declared defaults become part of what the updater enforces, alongside type
and nullability. `SystemBase::save()`'s client-side application stays
untouched — the two are complementary, not redundant (PHP covers the model
path including values the DB can't express; the DB covers raw SQL).

### 1. `createTableIfMissing()` — defaults at creation

In the column loop, after the NOT NULL clause and only when the column is
not serial (the serial branch already emits its sequence default):

```php
if (!empty($field_specs['serial'])) {
    $sql .= "DEFAULT nextval('{$sequence_name}'::regclass)";
} elseif (isset($field_specs['default'])) {
    $sql .= ' DEFAULT ' . $this->defaultToSqlLiteral($field_specs['default']);
}
```

`defaultToSqlLiteral()` already renders bools, numbers, `now()`-style
function calls, and quoted strings — reuse it as-is.

### 2. `addMissingColumn()` — defaults on added columns

Append the same clause to the `ADD COLUMN` statement:

```php
$sql = 'ALTER TABLE "public"."' . $table_name . '" ADD COLUMN "' . $field_name . '" ' . $field_specs['type'];
if (isset($field_specs['default'])) {
    $sql .= ' DEFAULT ' . $this->defaultToSqlLiteral($field_specs['default']);
}
```

Postgres applies an `ADD COLUMN ... DEFAULT` to existing rows itself
(metadata-only fast path on modern Postgres), which also removes most of
the need for the advanced-ops NULL backfill on newly added columns — the
backfill stays for the pre-existing-column case. The "add as nullable, NOT
NULL later via --upgrade" behavior is unchanged.

### 3. `processColumnModifications()` — heal missing defaults

After the existing nullability block, one narrow reconciliation, gated the
same way as every other modification (`$this->upgrade || $this->cleanup`):

- `getDetailedColumnInfo()` already selects `column_default` (`:~504`).
- **When the spec declares a default and the live column has none**
  (`column_default` is NULL, column not serial): run
  `ALTER TABLE ... ALTER COLUMN ... SET DEFAULT <literal>` and report it in
  `columns_modified`/`messages`.
- **Nothing else.** A live default that *differs* from the spec is not
  rewritten (comparing Postgres's normalized default expressions —
  `'confirmed'::character varying` — against PHP literals is fragile;
  a mismatch gets a warning message, not an ALTER). A live default with no
  spec default is left alone silently (hand-managed legacy is not an
  error). Dropping defaults is out of scope.

This makes the existing drift self-heal on the next `update_database
--upgrade` / plugin sync: `iem_is_archived` and every other
declared-but-absent default converges, and the ones already hand-applied
(`cal_status`, `iem_key_generation`, …) are recognized as present and left
alone.

## Pinned decisions

- **Absent → present only.** The reconcile pass adds missing defaults; it
  never rewrites or drops one. Fail toward inaction with a warning, never
  toward an ALTER built from a fragile string comparison.
- **`defaultToSqlLiteral()` is the single renderer** for all three call
  sites (it already is for the NULL backfill). No second quoting path.
- **No change to `SystemBase::save()`**, the NULL-backfill logic, or the
  "add nullable first, NOT NULL under --upgrade" sequencing.
- **No migrations** — this is updater behavior; the schema classes are
  already correct.

## Verification

1. `php -l` + `validate_php_file.php` on `includes/DatabaseUpdater.php`.
2. New-table path: `php utils/update_database.php` after pointing at a
   scratch data class (or reuse an existing test-table flow) — confirm
   `information_schema.columns.column_default` is populated for a declared
   default and empty for an undeclared one.
3. Reconcile path: confirm `iem_is_archived` currently has no live default;
   run `php utils/update_database.php --upgrade`; confirm it now reads
   `false` and the run reported the SET DEFAULT. Re-run; confirm no second
   message (idempotent).
4. Plugin path: run "Sync with Filesystem"; confirm a plugin-table column
   with a declared-but-absent default converges the same way.
5. Raw-SQL sanity: `INSERT` into a table omitting a defaulted NOT NULL
   column inside a rolled-back transaction; confirm the default value comes
   back.

## Docs

`docs/deploy_and_upgrade.md` (or the schema-management section CLAUDE.md
points at): state that `$field_specifications` defaults are enforced in
both layers — applied to new model rows by `SystemBase::save()` and
declared on the column itself by the updater (at creation, on added
columns, and reconciled under `--upgrade` when absent). Current-state voice
only.
