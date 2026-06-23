# Declarative Index Management in update_database

## Overview

The schema engine keeps the database in sync with each model's `$field_specifications`: it creates tables, adds columns, reconciles types and nullability, and manages **unique constraints**. The one thing it can't do is create an ordinary (non-unique) **index** — the structure that makes a lookup, filter, or join fast without enforcing uniqueness.

Today that gap is invisible until a table grows: every foreign-key column (`*_id`), every column a `Multi` class filters on, and every "active rows only" soft-delete scan runs as a sequential scan because nothing declares an index for it. The only indexes that exist are the ones Postgres creates implicitly — the primary key and the unique constraints. There is no declarative way to say "index this column," so in practice no one does, and the indexes that *should* exist simply don't.

This spec adds first-class, declarative index management to `update_database`, built as a direct parallel to the unique-constraint machinery that already works (`DatabaseUpdater::manageUniqueConstraints()`). A developer declares an index next to the column (or in a table-level block for advanced cases); `update_database` creates it if missing, recreates it if its definition changed, and — in cleanup mode — drops the ones it manages that are no longer declared. The model's `$field_specifications` stays the single source of truth for the whole schema, indexes included.

---

## Current State (what exists, what's missing)

**Exists — unique constraints**, reconciled in Step 3 of `utils/update_database.php` via `manageUniqueConstraints($classes)`:

- Declared field-level: `'unique' => true` (single column) and `'unique_with' => ['other_col', …]` (composite).
- `addMissingConstraints()` creates them; `removeObsoleteConstraints()` drops ones not in the spec (cleanup mode only).
- `generateOptimalConstraintName()` produces a deterministic name within Postgres's 63-char identifier limit, falling back abbreviation → positional → hash.
- `findExistingConstraintByColumns()` detects a constraint that already exists under a *different* name (e.g. a truncated legacy name), so it isn't duplicated.
- Gating: add when `cleanup || upgrade`; remove only when `cleanup` (constructor `__construct($verbose, $upgrade, $cleanup)`).

**Missing — ordinary indexes.** Nothing reads an "index this" declaration, so:

- Foreign-key columns are unindexed (Postgres does **not** auto-index the referencing side of a relationship).
- Common `Multi` filter columns and `(subject_type, subject_id)`-style composite filters have no supporting index.
- Soft-delete tables can't express the high-value "index only the rows where `delete_time IS NULL`" partial index.
- Uniqueness that must be scoped (unique **among active rows**) is impossible — a unique *constraint* is always whole-table, and the engine has no unique *index* path.

---

## Capability Inventory & Scope Decisions

Decided up front so the surface is fixed in one pass rather than grown piecemeal.

| Capability | Decision | Phase |
|---|---|---|
| Single-column btree index | In | 1 |
| Composite (multi-column) btree index | In | 1 |
| Create-if-missing + dedupe by column set (don't duplicate an equivalent index under another name) | In | 1 |
| Drop indexes the system manages that are no longer declared (cleanup mode) | In | 2 |
| Recreate when an index's definition changes (method/predicate/columns) | In | 2 |
| Index method override (`gin`, `gist`, `brin`, `hash`) | In | 3 |
| Partial index (`WHERE` predicate) — e.g. active-rows-only | In | 3 |
| Partial / expression **unique** index (uniqueness scoped by a predicate) | In | 3 |
| Expression index (e.g. `lower(email)`) | In | 3 |
| Covering index `INCLUDE (…)` | Out (v1) — add later if a concrete need appears |
| `CREATE INDEX CONCURRENTLY` | Out (v1) — see Safety; revisit only for tables too large to lock briefly |
| Real foreign-key **constraints** | Out — referential integrity stays app-level by deliberate design (see `docs/deletion_system.md`); this spec indexes FK columns, it does not constrain them |
| Scaffolder/manifest integration | Out — the scaffolder is a one-shot generator; indexes are declared on the data class directly |

---

## Declarative API

Two surfaces, with a clear division of labour. Both live in the data class; neither requires a migration.

### Field-level shorthand (the common case)

Mirrors `unique` / `unique_with` exactly, so it reads the same as constraints already do. Always a plain btree index.

```php
public static $field_specifications = array(
    // single-column index — the typical FK / filter column
    'ord_usr_user_id' => array('type' => 'int8', 'index' => true),

    // composite index starting with this column; order is significant
    'cal_subject_type' => array('type' => 'varchar(32)', 'index_with' => array('cal_subject_id')),
    'cal_subject_id'   => array('type' => 'int8'),
);
```

- `'index' => true` → btree index on this one column.
- `'index_with' => ['col2', …]` → btree index on `(this_column, col2, …)` in the order given (leftmost-prefix rules apply, so declare the column the developer filters/sorts on first).

### Table-level block (advanced cases)

Anything beyond a plain btree goes in one explicit array, so the advanced surface is in a single, greppable place rather than smuggled into field options.

```php
public static $index_specifications = array(
    // partial index: only the rows a query for active records actually scans
    array('columns' => array('cal_subject_id'), 'where' => 'cal_delete_time IS NULL'),

    // method override for a jsonb column
    array('columns' => array('prd_attributes'), 'method' => 'gin'),

    // expression index
    array('columns' => array('LOWER(usr_email)')),

    // partial UNIQUE index — uniqueness scoped to active rows
    // (a plain unique constraint cannot express this)
    array('columns' => array('usr_email'), 'unique' => true, 'where' => 'usr_delete_time IS NULL'),
);
```

Each entry: `columns` (required, array — bare column names or SQL expressions), optional `method` (default `btree`), optional `where` (partial predicate, stored verbatim), optional `unique` (boolean → unique index).

**Division of labour, stated once:** whole-table uniqueness stays with `'unique'` / `'unique_with'` (real `UNIQUE` constraints — they can be referenced by FKs and read as constraints). Scoped or expression uniqueness uses an `$index_specifications` entry with `unique => true`. The two never describe the same index.

---

## Naming

Reuse the existing `generateOptimalConstraintName($table, $columns, $type)` with new type tokens so index names get the same 63-char-safe abbreviation → positional → hash treatment:

- Plain index → type `idx` (suffix `_idx`).
- Unique index → type `uidx` (suffix `_uidx`).

For entries carrying a `where` and/or non-default `method`, append a short deterministic hash of `method + '|' + where` before the suffix, so two indexes on the same columns that differ only by predicate or method get distinct, stable names (e.g. `cal_entries_subject_id_a1b2_idx`). Expression columns are normalized (whitespace-collapsed) before hashing/naming so the name is stable across reformatting.

**The `_idx` / `_uidx` suffixes are reserved for system-managed indexes.** This is the marker the reconciler uses to know which indexes it owns (see Safety). Hand-created indexes must not use these suffixes.

---

## Reconciliation Lifecycle

A new step in `DatabaseUpdater`, `manageIndexes($classes)`, mirrors `manageUniqueConstraints()` and runs as a new step in `utils/update_database.php` immediately after Step 3 (unique constraints), before deletion-rule registration. Same flag gating: **add/recreate** when `cleanup || upgrade`; **drop obsolete** only when `cleanup`.

For each class, build the expected index set from `$field_specifications` (the `index` / `index_with` keys) plus `$index_specifications`. Then:

1. **Create missing.** For each expected index, if no index with its computed name exists *and* `findExistingIndexByColumns()` finds no equivalent index (same columns, method, predicate, uniqueness) under a different name, run `CREATE [UNIQUE] INDEX {name} ON {table} USING {method} ({columns}) [WHERE {predicate}]`. The column-structure check mirrors `findExistingConstraintByColumns()` and prevents duplicating an index that already exists under a legacy name.

2. **Recreate on definition change.** If an index with the expected name exists but its live definition (`pg_get_indexdef(...)`, normalized) differs from the expected DDL — method changed, predicate changed, column list/order changed but resolved to the same name — drop and recreate it. (A pure column-set change yields a *different* name and is handled as create-missing + drop-obsolete instead.)

3. **Drop obsolete (cleanup only).** Enumerate the table's indexes from `pg_indexes` and drop those that are **not** in the expected set — but only after three safety filters (below).

Unlike plain indexes, there is no "skip due to duplicate values" path that unique *constraints* have, except for **unique** index entries, which reuse `checkForDuplicateUniqueConstraintValues()` (scoped by the partial predicate when one is present) and are skipped with a warning if the data can't satisfy them — never failing the run.

---

## Safety

Dropping indexes is more dangerous than dropping unique constraints, because a table's index list includes the primary key, constraint-backing indexes, and anything created by extensions or by hand. The obsolete-drop pass therefore only considers an index droppable when **all** hold:

1. Its name ends in the reserved `_idx` / `_uidx` suffix (it's one we create).
2. It is **not** backing a constraint (excluded via `pg_constraint.conindid` / `pg_index.indisprimary` / `indisunique`-from-constraint), so PK and `UNIQUE`-constraint indexes are never touched.
3. It is not in the current expected set for the class.

This is a deliberate tightening over `removeObsoleteConstraints()`, which drops any non-expected unique constraint by name; index removal must never reach an index the system didn't author.

`CREATE INDEX CONCURRENTLY` is intentionally out of v1: it cannot run inside a transaction and leaves an **invalid** index behind on failure, which would require its own cleanup path — exactly the kind of partial-failure footgun to avoid. v1 uses plain `CREATE INDEX`, whose brief lock is consistent with the `ALTER TABLE … ADD CONSTRAINT` the engine already issues. If a table ever outgrows a brief lock, concurrent builds get their own spec with proper invalid-index recovery.

---

## Phases

### Phase 1 — Plain indexes, create-only

1. Parse `'index' => true` and `'index_with' => [...]` from `$field_specifications`.
2. Add type tokens `idx` / `uidx` to the naming path; verify 63-char fallbacks.
3. Implement `findExistingIndexByColumns()` (column/method/predicate/uniqueness match) paralleling `findExistingConstraintByColumns()`.
4. Implement `manageIndexes()` create-missing path; wire it as a new step in `utils/update_database.php` after unique constraints, gated on `cleanup || upgrade`.
5. Tests: a declared single and composite index appears in `pg_indexes`; a second run is a no-op; an equivalent pre-existing index under another name is not duplicated.

*Checkpoint:* declaring `'index' => true` on a column creates exactly one btree index, idempotently.

### Phase 2 — Reconciliation (recreate + obsolete drop)

1. Definition-diff recreate via normalized `pg_get_indexdef`.
2. Obsolete-drop pass with the three safety filters, cleanup-mode only.
3. Tests: changing a predicate recreates the index; removing a declaration drops it under cleanup; PK, unique-constraint, and a hand-created non-`_idx` index all survive a cleanup run.

*Checkpoint:* the live index set converges to exactly the declared set in cleanup mode, touching nothing the system doesn't own.

### Phase 3 — Advanced indexes

1. Parse `$index_specifications` (`columns`, `method`, `where`, `unique`).
2. Support method override, partial predicate, expression columns, and partial/expression unique indexes (reusing the duplicate-value check, predicate-scoped).
3. Tests: gin index on jsonb; partial active-rows index; partial unique index rejects a duplicate among active rows but allows it across a soft-deleted row.

*Checkpoint:* the partial-unique pattern (unique among `delete_time IS NULL`) works end to end.

---

## Files

**Modify:** `includes/DatabaseUpdater.php` — add `manageIndexes()`, `findExistingIndexByColumns()`, expected-index builders, the `idx`/`uidx` naming tokens, and the predicate/method hash in naming.

**Modify:** `utils/update_database.php` — new step calling `manageIndexes($classes)`, with the same result-echo block as the unique-constraint step.

**Modify (docs):** `docs/deploy_and_upgrade.md` — under **update_database Behavior**, document the index declaration surfaces (field-level `index`/`index_with`, table-level `$index_specifications`), the naming/suffix convention, the flag gating, and the obsolete-drop safety rules. Update `docs/example_class.php` to show both declaration styles in the annotated template.

**Tests:** `tests/models/` (or a dedicated `tests/schema/`) — coverage for each phase's checkpoint.

---

## Out of Scope

- Real foreign-key constraints (referential integrity is app-level by design).
- `CREATE INDEX CONCURRENTLY` and covering (`INCLUDE`) indexes.
- Scaffolder/manifest emission of index declarations.
- Index-usage analysis or advisory tooling ("you should add an index here") — this spec is about honoring declarations, not recommending them.
