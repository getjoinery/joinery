# Database Restore: Replace Semantics

## What this is for

When the platform restores a database — from a backup file, or as the second
half of a site copy — the person triggering it means "make this database equal
to that snapshot." What a plain `gunzip -c dump | psql` over a populated
database actually delivers is neither a replacement nor an error: every CREATE
collides with the existing schema, one duplicate key aborts an entire table's
data load, and psql exits 0 anyway. The job reports **completed** over a silent
mix of old and new rows.

Observed live on 2026-07-22: copy_database job #830 "completed" with 429
restore errors in its output — 31 tables kept their old rows entirely.

## The rule

**A restore replaces the database, and the restore step owns that guarantee.**

Every restore site drops the schema and recreates it before loading the dump,
and runs psql with `ON_ERROR_STOP=1` so any residual error fails the job
loudly. Dumps stay plain `pg_dump` snapshots everywhere.

## Why the guarantee lives in the restore step, not the dump

The alternative considered: have producers emit self-cleaning dumps
(`pg_dump --clean --if-exists`), so the dump drops what it recreates. Rejected
for three reasons:

1. **`--clean` is not a replacement.** It drops only objects that appear in
   the dump. A table that exists on the target but not in the source survives
   the restore, so the result is not equal to the snapshot.
2. **It turns correctness into a convention.** Dumps reach the restore step
   from the backup script, five `auto_pre_*` safety steps, the install-node
   clone — and from places the platform does not control: files fetched from
   cloud storage, dumps made by hand on a node. The moment any one producer
   is not self-cleaning, the silent-mix failure returns undetected. A drop in
   the restore step is an invariant that holds for any file it is fed.
3. **The restore step must change either way.** A plain psql pipe reports
   success no matter how many statements fail; `ON_ERROR_STOP=1` belongs in
   the restore regardless. Fixing the dumps would mean touching every
   producer *and* the restore; drop-first touches the restore only.

Backward compatibility falls out for free — every backup ever taken restores
correctly — but it is not the deciding reason; the two structural reasons
above hold even with no existing backups.

## Destruction is gated on an integrity check

Drop-first commits to destruction before the load begins, so the restore step
verifies the archive first: `gunzip -t` on the dump file, before the schema is
touched. A truncated or corrupt file fails the job with the database intact.
The pre-restore safety dump (`auto_pre_restore_*`) stays as the recovery path
for a dump that passes the integrity check but fails partway through the load.

## Inventory — every restore site

Decided once here so every restore follows the same rule.

| Site | Restore |
| --- | --- |
| `build_restore_database` (`JobCommandBuilder.php`) | gunzip -t → drop schema → load with `ON_ERROR_STOP=1` |
| `build_copy_database` | same |
| `build_copy_database_by_name` | same |
| `build_install_node` from-backup (`JobCommandBuilder.php:1676`) | already drop-first; add `ON_ERROR_STOP=1` and the gunzip -t gate |

The copy builders' dumps carry no `--clean --if-exists` flags: with drop-first
restores they are redundant, and plain dumps keep the single rule — dumps are
snapshots, restores replace. (`--clean` flags added to the copy builders on
2026-07-22 come back out.)

Job-internal dumps — the copy builders and the install-node clone dump — do
carry `--no-owner --no-acl`. Those dumps are restored as the **target** site's
own DB user, and with `ON_ERROR_STOP` a cross-site copy would otherwise fail
on every `OWNER TO` / `GRANT` naming the source site's role. Ownership falls
to the restoring role, which is exactly the target site's user. Backup files
(`backup_database.sh`, the `auto_pre_*` safety steps) stay plain `pg_dump`:
they are restored onto the site that made them, where the role matches.

The drop is `DROP SCHEMA public CASCADE; CREATE SCHEMA public;` — the pattern
proven by the install-node clone path, run as the site's own DB user, who owns
the schema's objects.

## What this does not cover

A raw `gunzip -c dump | psql` run by hand on a node still has the old hazard.
That is an operator acting outside the platform; the platform's guarantee is
that every restore *it* executes replaces.

## Changes

`plugins/server_manager/includes/JobCommandBuilder.php`:

- `build_restore_database`: add the gunzip -t integrity step, then drop
  schema + `ON_ERROR_STOP=1` on the restore step.
- `build_copy_database` / `build_copy_database_by_name`: restore steps become
  drop-first with `ON_ERROR_STOP=1`; dump steps trade `--clean --if-exists`
  for `--no-owner --no-acl`.
- `build_install_node` from-backup: add `ON_ERROR_STOP=1` and the gunzip -t
  gate to the existing drop-first restore; the clone dump gains
  `--no-owner --no-acl`.

## Testing

- `plugins/server_manager/tests/job_command_builder_test.php`: for each of the
  four restore sites, assert the restore command carries the schema drop and
  `ON_ERROR_STOP=1`, and that destruction is preceded by a `gunzip -t` gate
  and (where the builder emits one) the pre-restore safety dump. Assert no
  dump step anywhere carries `--clean`, and that job-internal dumps carry
  `--no-owner --no-acl`.
- Live gate on gt-copy-test: seed a stray table on the target, run a copy, and
  assert the stray table is gone and source/target row counts match. Then
  point restore_database at a deliberately truncated dump file and assert the
  job fails at the integrity step with the target schema untouched.

## Docs

Update `plugins/server_manager/docs/overview.md` where it describes backup and
restore jobs: a restore replaces the target database with the snapshot — the
restore verifies the archive, drops the schema, and fails loudly on any load
error. State the rule that dumps are plain snapshots and the restore step owns
replacement.
