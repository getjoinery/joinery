# Scaffold Generator — Hardening (post-shakedown)

## Problem

The scaffold generator (`specs/implemented/scaffolding_code_generator.md`) got its first real-world consumer: the scheduling system's data layer (`schedule`, `schedule_window`, `schedule_override`, then `cal_entries`, all `surfaces:["data"]`). That run surfaced **five bugs**, three of which the generator's own acceptance checks certified as clean before they blew up against the database.

The root cause behind the worst of them is a single gap: **the generator's definition of "validated output" is too shallow.** Its post-generation guarantees are `php -l` (syntax) and `validate_php_file.php` (code patterns). Both passed on output that:
- declared a column type `update_database` can't create,
- declared a primary key whose sequence `SystemBase::save()` can't find, and
- named the primary key against the wrong convention.

A class that passes the generator's gate and then throws a fatal on its first `save()` means the gate is measuring the wrong thing. This spec closes that gap and cleans up the smaller frictions the shakedown exposed.

### The four bugs (already fixed — context only)

These landed during the shakedown and are **not** in scope here except as the regression cases the new checks must cover:

1. **`time` columns rejected.** The manifest type validator omitted PostgreSQL `time`, so wall-clock columns couldn't generate. *(Fixed: added to `supportedTypeRegex()` + `formType()`.)*
2. **Soft-delete column type unparseable.** The template emitted `{prefix}_delete_time` as `timestamp with time zone`, which `update_database`'s type reconciliation doesn't recognize; the platform standard is `timestamp(6)`. *(Fixed: template.)*
3. **`bigserial` primary key breaks `save()`.** The PK was emitted as `bigserial`, which does not set `'serial'=>true`. `update_database` only manages the canonical `{table}_{pkey}_seq` when `serial` is set, so PostgreSQL created a divergent `..._seq1` the column used while the canonical name went unmanaged — and `SystemBase::save()`'s `lastInsertId('{table}_{pkey}_seq')` then fails on the first insert of every generated entity. *(Fixed: template now emits `int8` + `'serial'=>true`.)*
4. **Wrong primary-key naming.** The PK was derived as `{prefix}_id` instead of the platform convention `{prefix}_{singular}_id` (`usr_user_id`, `bkt_booking_type_id`). *(Fixed: PK derivation in both the reserved-column check and the render context.)*
5. **Type validator rejected column-type precision.** The supported-type regex accepted bare `timestamp`/`time` but not the precision form `timestamp(6)`/`time(6)` — even though `timestamp(6)` is the platform's standard instant type (`evt_start_time`) and `update_database` accepts it. The validator was stricter than the database, blocking valid manifests. *(Fixed: regex now allows optional `(\d+)` precision on `time`/`timestamp`.)*

Bugs 1 and 5 are two faces of the same underlying problem: **the manifest type validator's allow-list is hand-maintained and drifts from what `update_database` actually accepts.** The durable fix is to source the validator's accepted types from the same definition `update_database` uses, rather than a second hand-kept regex — captured below.

## Goals

- The generator's acceptance gate catches the "compiles but fails at the database" class of bug, not just syntax/pattern faults — so a green generator run means the entity actually works, table and first row included.
- The latent foot-gun behind bug 3 cannot return through a manifest field.
- A developer can iterate on a generated entity with `--force` even after its table has been created.
- The developer-facing documentation describes `owner_field`'s real behavior.
- A regression fixture pins all four shakedown bugs so they cannot silently come back.

## Non-Goals

- No change to the manifest format, the `surfaces:` model, or the derived/declared/stubbed contract.
- Not a schema manager — the roundtrip check **creates nothing that survives**; it proves the schema in a transaction and rolls back.
- No round-trip / merge machinery for hand-edited files (still out of scope per the original spec's no-band-aid stance). `--force` remains whole-file replacement.

## Scope

### 1. Database-roundtrip acceptance check (the core fix)

Add a third post-generation guarantee, alongside `php -l` and `validate_php_file.php`: **prove each generated data class round-trips through the real database.** This is what turns "the file parses" into "the entity works."

In plain terms: the generator should stand the table up, save one row, read it back, and tear it all down — before it tells the developer the run succeeded. If any of that fails, the run aborts and nothing is written (same contract as the existing guarantees).

Mechanism (a faithful exercise of the real code paths, not a reimplementation):

- Run inside a single transaction (`BEGIN … ROLLBACK`). PostgreSQL DDL is transactional, so a `CREATE TABLE` + `ROLLBACK` leaves no residue.
- Build the table from the class's `$field_specifications` using the **same `update_database` type-mapping and serial/sequence logic** the platform uses in production — not a parallel implementation. (Bug 2 and bug 3 both lived in the gap between the template and that mapping; the check is only meaningful if it uses the production mapping.)
- Insert one row of synthesized values (type-appropriate defaults; required fields populated; FK columns may be left null or zero — referential integrity is not under test here), then retrieve the primary key the way `SystemBase::save()` does — via `lastInsertId('{table}_{pkey}_seq')`. This is the step that catches the bug-3 sequence-name divergence.
- Read the row back and confirm the PK is non-empty.
- `ROLLBACK` unconditionally (including on failure).

Each failure is reported with the offending table/column and the underlying database error, mirroring how the CLI already prints validation failures. This check only runs for the `data` surface (the only one that owns a table) and only when a live `DbConnector` is available; when the generator is invoked in a context without a database (a pure preview), it is skipped with a printed notice rather than failing.

Because `files()` must stay pure (it backs the preview consumers — admin wizard, AI tool), the roundtrip lives in the **write path / CLI verification**, next to the existing `php -l` and validator passes — not in `files()`.

### 2. Forbid `bigserial` (and `serial`) as declarable field types

Bug 3's root cause — a serial type that doesn't carry `'serial'=>true` — must not be reachable from a manifest field either. Today `bigserial` is in `supportedTypeRegex()`, so an author could declare `{ "name": "...", "type": "bigserial" }` and reproduce the exact sequence break on a non-PK column.

Remove `bigserial` (and `serial`, if present) from the supported field-type set. The primary key is the only serial column the generator creates, and it is injected by the generator with the correct `int8` + `'serial'=>true` shape — never declared by the author. A manifest that declares `bigserial`/`serial` now fails validation with an actionable message ("serial types are managed by the generator; declare the column as int8 and let the PK be auto-generated").

**Fix the root that taught the broken pattern.** No real data class declares `bigserial` — every model's primary key is `int8` + `'serial'=>true`. The one exception is the annotated reference template `docs/example_class.php`, which declares its PK as `'type' => 'bigserial'`. That is the canonical "how to write a data class" doc and is the most likely origin of bug 3 (the generator's data-class template was modeled on it). Correct `docs/example_class.php`'s primary-key declaration to `int8` + `'serial'=>true` (and its accompanying "Serial types" comment) so the reference stops teaching the type that breaks `SystemBase::save()`.

### 3. `--force` bypasses the existence guards

`--force` currently still fails when the table already exists, because the table-exists and prefix-collision checks are unconditional manifest-validation errors, not write-collision checks. The result: once a generated entity's table is created, you can no longer regenerate the class from its manifest (e.g. after fixing a template bug) — you are forced to hand-edit, which is exactly what masks generator gaps.

When `--force` is set, **demote the table-exists and prefix-collision checks from hard validation errors to warnings.** `--force` already means "the developer accepts a whole-file overwrite"; it should equally mean "the table may already exist." All other validation (prefix shape, field types, filter columns, etc.) stays hard. Without `--force`, behavior is unchanged.

### 4. Documentation correction — `owner_field` semantics

The implemented spec and `docs/scaffolding.md` describe `owner_field` as: *omit it → the generator emits a stubbed `authenticate_*()`.* The code does the opposite:

- `owner_field` omitted, **or** set to the standard `{prefix}_usr_user_id` → **no** custom auth is emitted; the class inherits `SystemBase`'s default owner-or-staff scope.
- `owner_field` set to a **non-standard** column → the generator emits a working-but-flagged owner-check against that column, carrying a `// TODO: confirm this row-scope rule is correct` comment for the developer to harden (the polymorphic case in the scheduling system used this path).

Update `docs/scaffolding.md` (and the derived/declared/stubbed table's "Custom `authenticate_read/write()`" row) to match the code. The implemented spec in `specs/implemented/` is historical and read-only; note the discrepancy there only by reference, do not edit it.

### 5b. Single source of truth for accepted column types

Bugs 1 and 5 were both the validator's hand-kept type regex falling behind `update_database`. Replace that regex's role as the authority with the actual set `update_database` accepts (derive from / delegate to the same type-recognition code), so a type the database supports can never be rejected by the generator again. If a literal shared list isn't practical, at minimum the generator's accepted-type definition and `update_database`'s must be co-located with a comment binding them, and the regression fixture (below) must assert the generator accepts every type the platform's own data classes already use.

### 5. Regression fixture

Add a generator test fixture (under `tests/`) that generates a synthetic entity exercising every shakedown bug at once and asserts the output + roundtrip:

- a `time` column (bug 1),
- the soft-delete column resolves to `timestamp(6)` (bug 2),
- the PK emits as `int8` + `'serial'=>true` and round-trips an insert via the canonical sequence (bug 3),
- the PK column is named `{prefix}_{singular}_id` (bug 4),
- a non-standard `owner_field` produces the flagged owner-check; a standard/absent one produces none (item 4 semantics).

The fixture generates into a scratch location (or asserts on `files()` output plus a transactional roundtrip) and cleans up after itself, so it is safe to run repeatedly.

## Acceptance criteria

- A generated `data` class fails the run if its table cannot be created, a row cannot be inserted, or the PK cannot be retrieved via the canonical sequence — with the failure naming the table/column and DB error.
- A manifest declaring a `bigserial`/`serial` field is rejected with a clear message.
- `docs/example_class.php`'s primary key declares `int8` + `'serial'=>true` (no `bigserial`).
- `php utils/scaffold.php <manifest> --force` regenerates a class whose table already exists.
- `docs/scaffolding.md` describes the real `owner_field` behavior.
- The regression fixture passes and fails loudly if any of the four bugs is reintroduced.

## Documentation

Per the docs rule, update in place as current state (no migration narration):

- `docs/scaffolding.md` — correct the `owner_field` description; add the roundtrip check and the `serial`-types restriction to the "what you still own / what the generator guarantees" sections; document `--force`'s relaxed existence handling.
- `docs/example_class.php` — change the primary-key field declaration (and the "Serial types" comment above it) from `bigserial` to `int8` + `'serial'=>true`, matching the platform convention every real model already follows (see Scope §2).
- No new doc file is warranted; this is an extension of the existing generator guide.

## Open questions

_None outstanding._
