# Declarative Index Management in update_database

## Overview

The schema engine keeps the database in sync with each model's `$field_specifications`: it creates tables, adds columns, reconciles types and nullability, and manages **unique constraints**. The one thing it can't do is create an ordinary (non-unique) **index** — the structure that makes a lookup, filter, or join fast without enforcing uniqueness.

Today that gap is invisible until a table grows: every foreign-key column (`*_id`), every column a `Multi` class filters on, and every "active rows only" soft-delete scan runs as a sequential scan because nothing declares an index for it. The only indexes that exist are the ones Postgres creates implicitly — the primary key and the unique constraints. There is no declarative way to say "index this column," so in practice no one does, and the indexes that *should* exist simply don't.

This spec adds first-class, declarative index management to `update_database`, built as a direct parallel to the unique-constraint machinery that already works (`DatabaseUpdater::manageUniqueConstraints()`). A developer declares an index next to the column (or in a table-level block for advanced cases); `update_database` creates it if missing and — in cleanup mode — drops the ones it manages that are no longer declared. A changed definition needs no special path: the index name is a complete fingerprint of the definition, so any change is just a new index to create plus a stale one to drop. The model's `$field_specifications` stays the single source of truth for the whole schema, indexes included.

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
| Create-if-missing + dedupe by name (fingerprint), plus structural dedupe for plain btree indexes only (column set + order + method + uniqueness) | In | 1 |
| Drop indexes the system manages that are no longer declared (cleanup mode) | In | 2 |
| Converge an index whose definition changed — via name-as-fingerprint, so any change is create-new + drop-old, never an in-place recreate-diff | In | 2 |
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

**The name is a complete fingerprint of the index definition** — columns *in declared order*, method, predicate, and uniqueness all feed it. Columns and the `idx`/`uidx` token already cover order and uniqueness; for entries carrying a `where` and/or non-default `method`, append a short deterministic hash of `method + '|' + where` before the suffix (e.g. `cal_entries_subject_id_a1b2_idx`). The discriminator hash is threaded into the existing `generateOptimalConstraintName()` template (which builds `table_columns_type` with `type` last) by folding it into the type token — the helper is not a pure "new token" reuse.

**Expression columns cannot pass through the name helper raw.** `abbreviateConstraintName()` splits each column on `_`, so handing it `LOWER(usr_email)` produces a mangled identifier with parentheses. Any entry whose `columns` contains an expression (not a bare column name) derives its name from a sanitized, whitespace-collapsed hash token instead of threading the raw expression through abbreviation. The predicate is likewise whitespace-collapsed before hashing so the name is stable across reformatting.

The consequence is the whole point: **any change to a managed index's definition yields a different name.** A different predicate, method, column set, column order, or uniqueness all produce a new name — so convergence is always "create the new name, drop the old one," never an in-place diff-and-recreate of a same-named index (see Reconciliation Lifecycle). Two indexes on the same columns that differ only by predicate or method get distinct, stable names and coexist correctly.

**The `_idx` / `_uidx` suffixes are reserved for system-managed indexes.** This is the marker the reconciler uses to know which indexes it owns (see Safety). Hand-created indexes must not use these suffixes.

---

## Reconciliation Lifecycle

A new step in `DatabaseUpdater`, `manageIndexes($classes)`, mirrors `manageUniqueConstraints()` and runs as a new step in `utils/update_database.php` immediately after Step 3 (unique constraints), before deletion-rule registration. Same flag gating: **create missing** when `cleanup || upgrade`; **drop obsolete** only when `cleanup`.

For each class, build the expected index set from `$field_specifications` (the `index` / `index_with` keys) plus `$index_specifications`. Then:

1. **Create missing.** For each expected index, if no index with its computed name exists, create it with `CREATE [UNIQUE] INDEX {name} ON {table} USING {method} ({columns}) [WHERE {predicate}]`. The fingerprinted name is the primary idempotency guard. A structural dedupe (`findExistingIndexByColumns()`) additionally suppresses creation when an equivalent index already exists under a *different* (e.g. hand-made) name — but **only for plain btree indexes** (no `where`, no expression columns), matching on column set, **column order, method, and uniqueness**. Two points matter here:
   - **Column order is significant.** Unlike `findExistingConstraintByColumns()` — which `sort()`s both sides because constraint uniqueness is order-independent — the index matcher must compare columns in index-key order (an index on `(a, b)` is not the index on `(b, a)`). Do not copy the sort.
   - **Partial and expression indexes are deduped by name only**, not structurally. Comparing a declared predicate to Postgres's canonicalized `pg_get_expr(indpred)` is the same fragile normalization the recreate path was deleted to avoid (see below); reintroducing it here would risk the same churn. The accepted trade-off: a hand-made partial/expression index equivalent to a declared one is not detected, yielding one redundant index — never dropped (the safety filter protects non-`_idx` names), never churned.

2. **Drop obsolete (cleanup only).** Enumerate the table's indexes from `pg_indexes` and drop those that are **not** in the expected set — but only after three safety filters (below).

**There is deliberately no "recreate on definition change" step.** Because the name is a complete fingerprint of the definition (see Naming), a changed definition cannot present as a same-named index with different DDL — it always presents as a *new* expected name plus a *stale* old name. Step 1 builds the new index; Step 2 drops the old one. This intentionally omits the fragile path of normalizing `pg_get_indexdef()` and diffing it against expected DDL — Postgres rewrites expressions and predicates into a canonical form (injected casts, added parentheses) that an expected-DDL string rarely reproduces exactly, and any mismatch would make every run drop-and-recreate the same index forever. Folding convergence into create + drop removes that failure mode entirely, at zero cost to declared capability.

**Convergence is therefore cleanup-scoped, by design.** In plain `upgrade` mode a changed definition creates the new index but leaves the stale one until the next `cleanup` run drops it. The stale index is redundant, never wrong, and this exactly matches how obsolete unique *constraints* already behave (removal is cleanup-only). No new wart is introduced.

Unlike plain indexes, there is no "skip due to duplicate values" path that unique *constraints* have, except for **unique** index entries, which run the duplicate-value check and are skipped with a warning if the data can't satisfy them — never failing the run. This **extends** `checkForDuplicateUniqueConstraintValues()` rather than reusing it as-is: its current `WHERE` is hardcoded to the NOT-NULL clauses, so scoping the check to a partial predicate requires a new optional `$predicate` parameter that is `AND`ed into that `WHERE`.

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
3. Implement `findExistingIndexByColumns()` for **plain btree indexes only** — match on column set, **column order (no sort)**, method, and uniqueness; do not compare predicates. Parallels `findExistingConstraintByColumns()` but order-sensitive.
4. Implement `manageIndexes()` create-missing path; wire it as a new step in `utils/update_database.php` after unique constraints, gated on `cleanup || upgrade`.
5. Tests: a declared single and composite index appears in `pg_indexes`; a second run is a no-op; an equivalent pre-existing index under another name is not duplicated.
6. Docs: add the field-level `index` / `index_with` lines to `docs/example_class.php`, and the Table Creation note to `docs/plugin_developer_guide.md` (see Documentation Updates).

*Checkpoint:* declaring `'index' => true` on a column creates exactly one btree index, idempotently.

### Phase 2 — Reconciliation (obsolete drop)

1. Obsolete-drop pass with the three safety filters, cleanup-mode only.
2. Tests: removing a declaration drops the index under cleanup; changing a predicate or method yields a new-named index alongside the old, and the old one is dropped under cleanup (the new one survives); PK, unique-constraint, and a hand-created non-`_idx` index all survive a cleanup run.
3. Docs: add the **Index Management** subsection to `docs/deploy_and_upgrade.md` (gating + drop-safety behavior; advanced surface filled in at Phase 3).

*Checkpoint:* the live index set converges to exactly the declared set in cleanup mode, touching nothing the system doesn't own — with no in-place recreate path to maintain, since the fingerprinted name turns every definition change into create-new + drop-old.

### Phase 3 — Advanced indexes

1. Parse `$index_specifications` (`columns`, `method`, `where`, `unique`).
2. Support method override, partial predicate, expression columns, and partial/expression unique indexes. Extend `checkForDuplicateUniqueConstraintValues()` with an optional `$predicate` param to scope the check. Partial/expression entries dedupe by fingerprinted name only (no structural predicate match). Expression columns derive their name from a sanitized hash token.
3. Tests: gin index on jsonb; partial active-rows index; partial unique index rejects a duplicate among active rows but allows it across a soft-deleted row.
4. Docs: add the `$index_specifications` annotated block to `docs/example_class.php` and complete the advanced-surface paragraph of the `docs/deploy_and_upgrade.md` Index Management subsection.

*Checkpoint:* the partial-unique pattern (unique among `delete_time IS NULL`) works end to end.

---

## Documentation Updates

Docs describe the **current state only** (per the project's documentation rule): once the feature ships, the index surface must read as though it always existed — no "new", "now", or migration narration. Each doc change lands with the phase that makes its content true, so the docs never describe an unbuilt capability.

### `docs/deploy_and_upgrade.md` — new subsection under `## update_database Behavior`

Add an **Index Management** subsection (sibling to "Declarative Settings", "Plugin Tables Excluded", etc.) covering, for a developer who already understands the unique-constraint behavior:

- **What gets indexed and how to declare it** — field-level `'index' => true` / `'index_with' => [...]` for plain btree, and the table-level `$index_specifications` block for method overrides, partial predicates, expression columns, and scoped-unique indexes. One short example of each.
- **The division of labour** — whole-table uniqueness stays with `unique`/`unique_with` (real constraints); scoped/expression uniqueness uses an `$index_specifications` entry with `unique => true`. State that the two never describe the same index.
- **Naming & the reserved suffixes** — managed indexes end in `_idx` / `_uidx`; those suffixes are reserved for the system, and hand-created indexes must not use them.
- **Flag gating** — create missing when `cleanup || upgrade`; drop obsolete only when `cleanup`. Note the practical consequence: a changed definition creates the new index immediately but the stale one lingers until the next cleanup run (cleanup-scoped convergence, identical to how obsolete unique constraints already behave). Frame this as the steady-state design, not a caveat.
- **Drop safety** — the obsolete-drop pass only touches indexes whose name carries the reserved suffix, that don't back a constraint, and that aren't declared; PK, constraint-backing, and hand-made indexes are never dropped.

Lands in **Phase 2** (gating + drop behavior become true then); the advanced-surface paragraph is completed in **Phase 3**.

### `docs/example_class.php` — annotated template

The template is the canonical "show me how to declare a field" reference, so both surfaces belong there:

- In the field-options comment block (alongside the existing `'unique'` / `'unique_with'` comment lines), add `'index' => true` and `'index_with' => array(...)` with the same one-line descriptions, and put a real `'index' => true` on a representative FK column in the live `$field_specifications` array.
- Add an annotated `public static $index_specifications` block (the four-entry example from this spec: partial, method override, expression, partial-unique), with a one-line comment on each entry and a sentence restating the division of labour vs. `unique`/`unique_with`.

Field-level lines land in **Phase 1**; the `$index_specifications` block lands in **Phase 3**.

### `docs/plugin_developer_guide.md` — Table Creation note

Plugin tables run through the same `$field_specifications` path (`runPluginTablesOnly()`), so indexes work for plugins with no extra wiring. Add one sentence to the **Table Creation (Automatic)** section: index declarations (`index` / `index_with` / `$index_specifications`) are honored for plugin tables exactly as for core tables, applied on install and on **Sync with Filesystem**. Lands in **Phase 1**.

### Intentionally *not* updated

- **`docs/scaffolding.md`** — the scaffolder doesn't emit index declarations (out of scope; see Capability Inventory). Leaving its `unique_with` row as-is is correct, not an omission. Called out here so a future pass doesn't "complete" the scaffolder integration by reflex.
- **`CLAUDE.md` "Database Schema Management"** — already states schema is managed automatically from `$field_specifications`, which now subsumes indexes; no edit needed. If a one-line index mention is ever wanted, it goes through the Internal CLAUDE.md record at `/admin/admin_agent_files`, never the on-disk file.

---

## Files

**Modify:** `includes/DatabaseUpdater.php` — add `manageIndexes()`, `findExistingIndexByColumns()`, expected-index builders, the `idx`/`uidx` naming tokens, and the predicate/method hash in naming.

**Modify:** `utils/update_database.php` — new step calling `manageIndexes($classes)`, with the same result-echo block as the unique-constraint step.

**Modify (docs):** `docs/deploy_and_upgrade.md`, `docs/example_class.php`, and `docs/plugin_developer_guide.md` — see **Documentation Updates** above for the exact anchors, content, and phase each lands in.

**Tests:** `tests/models/` (or a dedicated `tests/schema/`) — coverage for each phase's checkpoint.

---

## Out of Scope

- Real foreign-key constraints (referential integrity is app-level by design).
- `CREATE INDEX CONCURRENTLY` and covering (`INCLUDE`) indexes.
- Scaffolder/manifest emission of index declarations.
- Index-usage analysis or advisory tooling ("you should add an index here") — this spec is about honoring declarations, not recommending them.
