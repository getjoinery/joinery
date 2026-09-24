# Backups — Incremental Database Backups

**Status:** Draft — two owner decisions open (D1, D2). WP1 can be built now; everything
after it waits on D1/D2, on services phase 2 item 2b (the object-store seam in
`BackupRunner`), and — for its live proof — on a PostgreSQL 17+ box.
**Date:** 2026-09-24
**Related:** `specs/fleet_ubuntu_2604_postgres_upgrade.md` (gets the fleet onto
PostgreSQL 18), `specs/backups_remaining_gaps.md` (listed this as gated on that campaign).

## What this does for the owner

Every night each node uploads its **whole database**, even on a night when almost
nothing in it changed. On jeremytunnell that is 1.65 GB a night — about three quarters
of everything a normal week of backups sends. Files are already incremental; the
database is not.

PostgreSQL 17 and later can take a backup that carries only the parts of the database
that changed since the last one. Ubuntu 26.04 ships PostgreSQL 18. This spec makes the
backup engine use that on any node whose PostgreSQL can, and keeps the full nightly dump
everywhere else. Nothing changes on a node until it is on PostgreSQL 17+.

Measured on jeremytunnell's backup storage (read-only listing, 2026-09-24):

| | Files | Database | Total |
|---|---|---|---|
| Full run (chain-20260924_040025) | 3.0 GB | 1.65 GB | 4.7 GB |
| Quiet incremental nights (chain-20260914_040021, runs 5–7) | 81–151 MB | 1.65–1.69 GB each | ~1.8 GB |
| A quiet week (1 full + 6 incrementals) | ~3.7 GB | ~11.6 GB | ~15 GB |

Expected after this spec (**estimates — Phase 0 and the live gate measure them**): the
weekly database full is about the size of today's dump (the physical copy includes
indexes but compresses similarly), and a quiet night's database increment is tens to low
hundreds of MB. A quiet week drops from ~15 GB to roughly 5–7 GB, and 28 days of retained
backup storage shrinks in proportion.

Release nights are a separate cost. An upgrade today starts a new chain, so the night
after a release is a full of **everything** — every node has taken a full for the last
two nights for exactly this reason. D1 decides whether the database keeps its
incremental chain across an upgrade.

## For the executor — read this first

**House rules** (CLAUDE.md and project memory):

- **Never commit, never `git add`.** The index is shared; the owner runs git.
- **No schema change and no settings row** in this spec. The engine configures
  PostgreSQL itself (§ Server settings); nothing new is declared in `settings.json`.
- **Docs describe the current state only** — no "now", "previously", "replaces".
- **Bump the version header** of every file touched, one line saying what.
- **After every PHP edit:** `php -l`, then `validate_php_file.php` on class/function
  files only (never on `utils/*.php` or scripts — the validator executes them).
  Shell scripts: `bash -n`.
- **Tests use the shared harness** with the `@joinery-test` header. Working loop
  `php tests/run.php --changed`; before handing back `php tests/run.php db --changed`.
  Never run the runner as root.
- **The data key never touches argv** — fd 3 from a 0600 file, as every engine does.
- **Nothing an engine produces lands on disk** except what this spec names (the
  PostgreSQL backup manifest, kilobytes to a few MB). The 2026-09-22 jeremytunnell
  disk-full incident is why.
- **Never touch a live node.** Phase 0 runs on a scratch box; the live gate is the owner's.
- **Coordinate on `BackupRunner.php`.** Services phase 2 item 2b puts an object-store
  seam at `destination()` / `stream_engine()` and is waiting on the same file. Build
  WP2 onward after 2b lands (or with its author), and send every new artifact through
  `stream_engine()` so it inherits the seam.

**Decisions made — do not reopen:**

- **Chain mode only.** "Full every time" mode and database-only backups keep `pg_dump`.
- **The engine is chosen per run, automatically** (§ Which engine a run uses). No setting.
- **A database increment builds on the previous run's**, like the files: smallest nightly
  upload, and restore already applies a chain in order.
- **Restore goes back through a dump** (§ Restoring). The combined backup is started as a
  throwaway PostgreSQL server, the site's database is dumped from it, and the existing
  `restore_database.sh` loads that dump. One restore contract for every engine: the
  approval flow, the "target must be at least as new" refusal and the drop-and-load
  behaviour stay exactly as they are, and a cluster holding more than the site never
  has to be swapped wholesale.

## How it works

### Which engine a run uses

A chain run takes a **physical** database backup (`pgdata`) when all of these hold,
and a `pg_dump` (`db`, unchanged) otherwise:

1. The server is PostgreSQL 17 or later (`server_version_num >= 170000`).
2. **The cluster holds only this site's databases.** A physical backup copies the whole
   cluster, so every non-template database other than `postgres` must be the site's
   `dbname` or `dbname_test`. This is not hypothetical: dev's cluster also holds
   `integral_membership` (430 MB), and copying it into dev's backups would put another
   site's data under dev's key. That node stays on the dump engine.
3. **The cluster has no unlogged tables.** A physical backup carries their structure but
   not their rows (`pg_dump` carries both). The platform uses none today; the check keeps
   it that way silently rather than losing rows on the day one appears.

The run message says which engine ran and, when it fell back, why — for example
`database: full dump (the server also holds integral_membership)`.

### Server settings the engine sets itself

Incremental backups need `summarize_wal = on`. `pg_basebackup -X fetch` needs the WAL
written during the backup to survive until it ends, which is `wal_keep_size`. Both are
reload-only settings. Before the first physical run on a server, the engine sets
`summarize_wal = on` and raises `wal_keep_size` to a floor (proposed 256 MB — at most
256 MB of extra disk, known and bounded) with `ALTER SYSTEM` and `pg_reload_conf()`, as
the superuser it already connects as. The installer and the container image need no
change, and a box installed by hand gets it too. A backup that fails because WAL rotated
during it says so and names `wal_keep_size`.

### Taking the backup

A new engine script, `maintenance_scripts/sysadmin_tools/backup_pgdata.sh`, in the same
stream mode as `backup_database.sh` (`--archive -`, `--report FILE`, key on fd 3):

```
pg_basebackup -h 127.0.0.1 -U postgres -D - -Ft -X fetch --checkpoint=fast \
    [--incremental=<previous run's backup_manifest>] \
  | tee >(tar -xOf - backup_manifest > <pending manifest>) \
  | gzip | openssl enc -aes-256-cbc -salt -pbkdf2 -pass fd:3
```

- Replication over loopback with the password the dump already uses. The installer's
  `pg_hba.conf` allows `host replication all 127.0.0.1/32` (`install.sh:2918`); the
  container's distro default must be confirmed in Phase 0.
- `-D - -Ft` writes one tar to stdout, so nothing lands on disk. `-X fetch` puts the
  WAL inside it (`-X stream` cannot write to stdout). PostgreSQL injects its
  `backup_manifest` into a tar written to stdout; the `tee` branch extracts it to a
  pending file beside the snapshot, because the **next** increment is computed against it.
- The report carries `BASEBACKUP_RC`, `MANIFEST_RC`, `ENC_RC`, `LEVEL`, and from the
  extracted manifest `SYSTEM_ID`, `TIMELINE`, `PG_MAJOR` and `RAW_BYTES` (the sum of
  the manifest's file sizes — the disk a restore needs, which the compressed size hides).
- **Accepted** only when all three exit codes are 0, the manifest was extracted and
  parses, and more than an empty envelope went up — the same shape as `accept_dump()`.
- **On commit** the pending manifest is renamed to `{working dir}/.{slug}.pgmanifest`.
  On failure it is deleted, and the committed one stays as it was. The run's other
  failure rules are unchanged (a failed run clears the snapshot and the next run starts
  a new chain).

Artifact: kind `pgdata`, named `pgdata-NNNN.tar.gz.enc`, encrypted with the chain's data
key like every other artifact. A run carries `db` or `pgdata`, never both.

### When the database starts over inside a chain

A `pgdata` artifact is level 0 (a full copy of the cluster) when any of these hold, and
otherwise an increment on the previous run's:

- the chain is new;
- there is no committed `.pgmanifest` for this chain (lost, or never written);
- the previous run's database artifact was a dump (the node just reached PostgreSQL 17+);
- the server's system identifier, timeline or major version differs from the previous
  run's (a restore, a `pg_upgrade`, a re-initialised cluster);
- the previous `pgdata` run is older than `wal_summary_keep_time` less a day — the
  server no longer holds the summaries an increment needs;
- `pg_basebackup` refuses the increment for missing WAL summaries anyway. The run aborts
  that upload and retries once as a level 0 **in the same run**.

The artifact records why it started over (`rebased_because`), the way a manifest records
`started_because`.

### The manifest: every part carries its own level (version 2)

Today a run has one `level`, and "full" means files and database together. With a
database that increments on its own terms, each artifact kind carries its own `level`:

```json
"artifacts": {
  "files":  {"name": "files-0004.tar.gz.enc", "bytes": 81400000, "sha256": "…", "level": 1},
  "pgdata": {"name": "pgdata-0004.tar.gz.enc", "bytes": 64000000, "sha256": "…", "level": 1,
             "raw_bytes": 212000000, "pg": {"system_id": "…", "timeline": 1, "major": 18}},
  "meta":   {…}, "objects": {…}
}
```

- The run's own `level` is 0 exactly when every kind in it is level 0 — "this run
  restores on its own". Every page that says Full / Incremental keeps reading it.
- **Restore plan per kind:** the newest level-0 artifact of that kind at or before the
  run, then every artifact of that kind after it, up to the run. A dump is always
  level 0, so a `db` plan is that run's dump, exactly as today.
- `VERSION` becomes 2. Readers accept 1 and 2; a version-1 manifest reads exactly as
  today (run level applies to the files, `db` is that run's dump). **Old readers refuse
  version 2 loudly** — both `BackupChain::decode()` and the Python plan inside
  `restore_chain.sh` check the version — which is what stops a stale reader restoring the
  files and silently skipping a database kind it does not know.

### Restoring

A new script, `maintenance_scripts/sysadmin_tools/restore_pgdata.sh`, turns a chain's
`pgdata` artifacts into an encrypted dump for the existing loader:

1. Check every artifact against its size and hash (as today, before anything is written).
2. **Refuse before writing anything if the disk is short.** Need: the raw bytes of every
   artifact in the plan (extracted) plus the full's raw bytes (the combined copy) plus
   the dump. `pg_combinebackup --link`, where the installed version has it, removes most
   of the second term.
3. Decrypt and extract each artifact into its own directory, in a work directory created
   `0700`.
4. `pg_combinebackup <full> <inc1> … <incN> -o <combined>`, in plan order.
5. Start a **throwaway server** on `<combined>`: Unix socket in the work directory,
   `listen_addresses=''`, so it can never collide with or be reached beside the live
   server. It runs as the invoking account, or as `postgres` when invoked as root
   (PostgreSQL refuses root). It replays the WAL the backup carries and opens.
6. `pg_dump` the site's database from it, through `gzip | openssl` with the chain key, and
   hand the result to `restore_database.sh` — its version check, drop-and-load and
   refusals unchanged.
7. Stop the server and remove the work directory on every path, success or failure.

`restore_chain.sh` picks `restore_database.sh` for a `db` artifact and `restore_pgdata.sh`
followed by `restore_database.sh` for a `pgdata` plan. The combined copy is plaintext
cluster files on disk for the length of the restore — the same exposure as the live
database directory, in a `0700` directory that is always removed.

A physical backup restores only with PostgreSQL of **the same major version** that took
it. Going through a dump is what keeps "restore onto a newer PostgreSQL" working: the
throwaway server is the old version and the target can be newer.

### Verifying

- **Level 1** (backup storage, on the management node): unchanged. It already walks
  every artifact a manifest names (`FleetBackupRetention::compare_manifest()`).
- **Level 2** (opened and read): for `pgdata`, decrypt → gunzip → `tar -t` to the end,
  confirm the `backup_manifest` member is present and parses, and that its system
  identifier and incremental flag match what the chain manifest recorded.
- **Level 3** (rehearsed): the § Restoring path into the throwaway database it already
  creates and drops, with the same counts (tables, users, sampled offloaded files).
- `BackupVerifier::disk_needed()` counts raw bytes for `pgdata`, not compressed bytes.

### What a physical backup carries that a dump does not

Every database in the cluster (the eligibility check keeps that to this site's own),
and the cluster's roles, including the `postgres` role's password hash. Both are sealed
under the same envelope as everything else. The restore path dumps only the site's
database, so roles do not travel to the target — the same as today.

## Work packages

**WP1 — Say the whole size (defect, ships alone, now).** A backup's message and job
result count only the files archive, and label binary units "GB": jeremytunnell's
4.7 GB full was reported as "2.8 GB of files".
- `BackupRunner::execute_chain()` (`BackupRunner.php:940`): the message states the total
  and each part — `Full backup 4.7 GB (files 3.0 GB, database 1.7 GB)` — and the
  result's `bytes` figure is the total (`BackupRunner.php:947`). The node's own
  `bkh_bytes` already sums every artifact (`backup_history_class.php:174`).
- `BackupRunner::human()` uses decimal units, matching what backup storage bills and
  shows.
- `full_size_warning()` keeps comparing files bytes to files bytes, so its history stays
  comparable.
- Tests: extend `backup_runner` for the message and figure.

**WP2 — Manifest version 2: per-kind levels.** No behaviour change on its own.
- `BackupChain.php`: `VERSION = 2`, decode accepts 1|2 (`:308`); `KINDS` gains `pgdata`
  with extension `.tar.gz` (`:75`, `:121`); `add_run()` records each kind's level and
  derives the run level (`:137`); `restore_plan()` plans per kind (`:236`) and returns
  the database as a list (a dump is a one-element list).
- Every reader of a plan or a run: `BackupStaging.php:291` and `:308` (artifact list),
  `BackupVerifier` (`read_all`, `rehearse`, `disk_needed`), `utils/verify_backup.php`,
  the Python plan in `restore_chain.sh` (`:156`–`:213`), and on the management node
  `BackupChainListHelper.php:154`, `node_detail_tabs/backups.php:475/481/545`,
  `FleetBackupRetention::check_shelf()`.
- Tests: plan per kind with version-1 fixtures unchanged; a version-2 chain with a
  files rebase mid-chain; a `pgdata` chain; an old-version reader refusing version 2
  (shell gate over `restore_chain.sh --dry-run` with fixture manifests).

**WP3 — A code upgrade restarts the files, not the chain (only if D1 = yes).**
- `tree_changed` stops being a reason to start a chain (`BackupChain.php:190`). It
  becomes a files rebase inside the current chain: the snapshot is cleared, the files
  engine takes level 0, the artifact records `rebased_because: tree_changed`, and the
  database continues its increments.
- Every other chain-start reason is unchanged, including `snar_lost` after a failed run.
- The engine finding the tree swapped **during** a run still fails the run
  (`BackupRunner.php:863`).
- The consistency checks at `BackupRunner.php:854`–`:871` compare against the planned
  per-kind levels.
- Tests: `backup_tree_swap` becomes "a swap re-bases the files inside the chain, and a
  restore at every run after it extracts".

**WP4 — The physical engine.** `backup_pgdata.sh` (§ Taking the backup), eligibility and
the level-0 rules as pure functions beside `should_start_new()` so they test without a
server, the self-set server settings, `run_db_engine()` choosing the engine, the
`.pgmanifest` commit/discard alongside the snapshot's, and the same-run retry.

**WP5 — Restore.** `restore_pgdata.sh` (§ Restoring), `restore_chain.sh` dispatching on
kind, and the disk preflight.

**WP6 — Verification.** Levels 2 and 3 for `pgdata` (§ Verifying).

**WP7 — Agent.** `operate_stage_chain.go:86` caps `artifact_urls` at 64 entries. Restoring
the last run of a maximum-length chain needs 31 files + 31 `pgdata` + meta + objects = 64,
so the cap is reached exactly: raise it to 128. Agent release.

**WP8 — Docs** (current state only): `docs/backups.md` — the layout under *What a backup
is*; the database paragraph and the tree-swap paragraph under *How chains work*;
*Opening a backup with no Joinery anywhere* gains the physical steps (decrypt each,
extract, `pg_combinebackup`, start a PostgreSQL of the same major version on the result,
`pg_dump`); *Restoring* and *Verifying* for the throwaway server and its disk.

**WP9 — Tests.** Pure (safe tier): eligibility, level-0 reasons, plan per kind, decode
1|2, the accept rule, units. Integration (db tier): a physical full + increment + restore
round trip, `harness_skip` with the reason when the server is older than 17 — which is
dev today, so it runs on the Phase 0 box and on dev once dev is on 26.04. A pin that a
cluster holding another database always takes the dump engine.

## Phase 0 — prove the PostgreSQL behaviour on a scratch 26.04 box

On a scratch Linode (g6-nanode-1, deleted after), with a copy of a real site restored
onto it, confirm each assumption above and record the evidence here:

1. `pg_basebackup -D - -Ft -X fetch` injects `backup_manifest` into the stdout tar, and
   the `tee` extraction yields a manifest `--incremental` accepts.
2. `--incremental` works with tar-to-stdout and `-X fetch`.
3. `summarize_wal` and `wal_keep_size` take effect on reload, without a restart.
4. The refusal for missing WAL summaries: its text, and whether it arrives before any
   bytes stream (shapes the same-run retry).
5. `pg_combinebackup` takes the extracted directories in order; whether `--link` exists.
6. The combined directory starts as a server and opens after replaying its WAL.
7. The container image's `pg_hba.conf` allows loopback replication.
8. **Sizes:** a physical full vs `pg_dump | gzip` of the same database, and an increment
   after a day of the site's normal work.

## Live gate (owner)

On the first PostgreSQL 17+ node with real traffic: a week of nightly runs with the sizes
recorded here; one level-3 verification passing; one restore from a mid-chain run onto a
scratch box.

## Open decisions

**D1 — Should the database keep its incremental chain across a code upgrade?**
Today every upgrade starts a new chain, so the night after any release is a full of
files *and* database. We released on 7 of the last 7 days.
- **Yes (WP3):** an upgrade re-bases only the files; the database keeps incrementing.
  Saves the whole database on every release night (1.65 GB on jeremytunnell). Catch: it
  changes a rule that is proven today, and every plan reader must understand a rebase
  mid-chain (WP2 builds that anyway).
- **No:** simpler; a release night stays a full of everything, so while releases are
  daily this spec saves almost nothing.
- **Recommendation: yes.**

**D2 — Also keep a plain dump once per chain, beside the physical full?**
- **Yes:** each chain can still be opened with only `openssl` and `psql`, restored onto
  any PostgreSQL version, with no throwaway server. Catch: one extra dump per chain
  (+1.65 GB a week on jeremytunnell, about a quarter of the new weekly total).
- **No:** the restore path already produces a dump from any run, and PostgreSQL of the
  right major version is a free, standard install. The manual procedure (WP8) is five
  commands instead of two.
- **Recommendation: no.**

## Related, not in this spec

- **The larger release-night cost is the files.** An upgrade swaps only the code tree,
  but the files full then re-uploads all of `uploads/` (3.9 GB on jeremytunnell) though
  none of it changed. Archiving code and data as two kinds, each with its own snapshot,
  would make a release night a code full (about 0.1 GB) plus data and database
  increments. WP2's per-kind levels are the groundwork; it needs its own spec.
- **Getting nodes onto PostgreSQL 18** is `specs/fleet_ubuntu_2604_postgres_upgrade.md`.
  Every node today is on 24.04 / PostgreSQL 16, so nothing here changes a live backup
  until that campaign moves one.
