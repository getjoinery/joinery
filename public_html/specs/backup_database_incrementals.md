# Backups — Incremental Database Backups, and Uploads That Survive an Upgrade

**Status:** Ready for an executor — every decision made (D1 yes, D2 no, D3 yes,
2026-09-26). Reviewed by public-html-a5 2026-09-26 (B1–B12 folded in). WP1 is done;
WP7 is built (agent 1.45.0 and the plane, uncommitted). WP2 onward edits `BackupRunner`, which services phase 2
item 2b (the object-store seam) also edits: one session in that file at a time. 2b has
not started, so this spec goes first and 2b builds on it. WP2 and WP3 (the code/data split) need no
particular PostgreSQL and reach every node; WP4 onward (the physical database engine)
needs PostgreSQL 17+ for its live proof.
**Date:** 2026-09-24
**Related:** `specs/implemented/fleet_ubuntu_2604_postgres_upgrade.md` (put the eight Docker
sites on PostgreSQL 18, 2026-09-26), `specs/standalone_boxes_ubuntu_2604.md` (jeremytunnell and dev
to PostgreSQL 18 on Ubuntu 24.04, under way), `specs/backups_remaining_gaps.md` (listed this as gated on that campaign).

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

**Release nights are the bigger cost, and this spec removes it.** An upgrade today starts
a new chain, so the night after a release is a full of **everything**: the code, all of
`uploads/` (3.9 GB on jeremytunnell, none of it changed by the release) and the database.
We release most days, so most nights are that full. Under this spec the code
(`public_html/`) and the site's data (everything else in the site directory) are two
separate archives, each with its own incremental chain (§ Code and data are archived
separately). An upgrade re-bases the code only; the data and the database carry on
incrementing (D1, D3, WP3). A release night becomes a code full (about 0.1 GB) plus a
normal night's data and database increments — on every node, whatever its PostgreSQL. On
the publishing box (jeremytunnell) that night's data increment also carries the release
archives it just published into `static_files/` (tens of MB a release).

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
  seam at `destination()` / `stream_engine()`. It has not started; this spec goes first.
  Before editing the file, confirm no other session is in it. Send every new artifact
  through `stream_engine()` so 2b's swap at that seam covers it unchanged.

**Decisions made — do not reopen:**

- **Chain mode only.** "Full every time" mode and database-only backups keep `pg_dump`.
- **The engine is chosen per run, automatically** (§ Which engine a run uses). No setting.
- **A database increment builds on the previous run's**, like the files: smallest nightly
  upload, and restore already applies a chain in order.
- **Code and data are separate archives with separate chains** (D3, WP3). A code
  upgrade re-bases the code only; the data and the database chains continue (D1).
- **No extra plain dump per chain** (D2). The physical full is the chain's only database
  base; the manual procedure (WP8) covers opening it without Joinery.
- **Restore goes back through a dump** (§ Restoring). The combined backup is started as a
  throwaway PostgreSQL server, the site's database is dumped from it, and the existing
  `restore_database.sh` loads that dump. One restore contract for every engine: the
  approval flow, the "target must be at least as new" refusal and the drop-and-load
  behaviour stay exactly as they are, and a cluster holding more than the site never
  has to be swapped wholesale.

## How it works

### Code and data are archived separately

The files engine (`backup_files.sh`) archives the whole site directory as one tar with
one snapshot. `utils/upgrade.php` swaps every directory inside `public_html/`; the new
directories reuse the freed inodes, and tar's incremental mode reads that as renames no
extraction can apply. So the snapshot records the code tree's identity, a swap discards
it, and the run is a full of the whole site (`tree_changed`, `backup_files.sh` 1.4.0).

The swap never touches anything outside `public_html/`. So a chain run takes **two**
archives, each with its own snapshot:

| Kind | What it holds | Rooted at | Snapshot | Re-based by |
|---|---|---|---|---|
| `code` | `public_html/` | `public_html` (extracts into the site directory) | `.{slug}.code.snar` + `.tree` | a changed code-tree identity (every upgrade, every restore that lays the code down) |
| `data` | the site directory **except** `public_html/`, `public_html_*` (the upgrade's rollback and failed trees) and `uploads/upgrades/` (the upgrade's staging area, which holds a whole code tree mid-upgrade) | the site directory, as today | `.{slug}.data.snar` + `.tree` | a changed data-tree identity: the inode of the site directory and of each **archived** directory directly inside it (see below) |

- Both archives take the existing exclusions (`backups`, `vendor`, `node_modules`,
  `target`, `.git`, `logs`, `cache`, `tmp`, `sessions`, and the `backup_exclude`
  setting). The offloaded-files list (`--exclude-from`) goes to the `data` engine only.
- **The three split exclusions are anchored to the site root.** tar exclude patterns are
  unanchored by default, so a plain `--exclude=public_html` also drops every
  `public_html` at any depth — dev's `sync/` holds 6.8 GB of worktrees, each with one.
  `backup_files.sh` passes `--anchored --exclude="$BASE/public_html"
  --exclude="$BASE/public_html_*" --exclude="$BASE/uploads/upgrades" --no-anchored`
  before the by-name exclusions, which stay unanchored on purpose.
- **The data identity covers archived directories only.** A directory the archive
  excludes was never recorded in the snapshot, so its inode cannot produce a rename
  record; counting it would make `rm -rf vendor && composer install`, a wiped `cache/`
  or a recreated `logs/` re-base 3.9 GB for nothing. An in-place restore keeps the
  top-level inodes, and a fresh box has no snapshot at all (`snar_lost`), so in practice
  this rule fires only when someone swaps a top-level data directory by hand. It is
  cheap and it is the guard for that case.
- The `data` archive is rooted at the site directory name, so `restore_chain.sh`'s
  target-name check reads it exactly as today. The `code` archive's root is
  `public_html`; the check for it is that its first member is `public_html/`.
- A snapshot's identity is read before its tar runs, and a swap during a run still fails
  the run (`BackupRunner.php:863`), now for the `code` kind only: a swap cannot change
  what the `data` archive reads.
- `backup_files.sh` gains `--part code|data`. Without it the script archives the whole
  site as today, which is what a version-1 chain and "full every time" mode use.
- A version-1 chain's `.{slug}.snar` is not reused: the first run under this spec starts
  a new chain (`started_because: layout_split`) and deletes `.{slug}.snar` and
  `.{slug}.snar.tree`, which nothing else would ever remove.

**Prototype, 2026-09-26 (GNU tar 1.35, scratch directory, not a live tree):**
- A path left out of an incremental archive is listed in its parent's directory record
  as present-not-dumped (`N public_html`), so extracting the `data` archive over a site
  leaves `public_html/` untouched while still deleting stale files inside `uploads/`.
- An upgrade-style swap of 40 code directories (stage, move live aside, move stage in,
  remove the old) between two `data` runs: the `data` chain restored byte-identical,
  both into an empty directory and over a drifted live tree, with the code re-based
  alone.
- An in-place restore of an earlier `data` run, then more activity, then the next `data`
  increment on the same snapshot: the whole chain restored byte-identical. A tree laid
  down fresh (every inode new) under an old snapshot restored correctly too; the data
  identity rule re-bases that case anyway, since the increment would be a full in all
  but name.

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
4. **The cluster has no tablespaces beyond `pg_default` and `pg_global`.** `pg_basebackup
   -D -` refuses a cluster with more than one tablespace ("can only write single
   tablespace to stdout"); without this check that would be a nightly engine failure.

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
  A process substitution's exit status is not in `PIPESTATUS`, so the substitution
  writes its own: `>(tar -xOf - backup_manifest > <pending>; echo $? > <rc file>)`, and
  the script `wait`s for it before reading `MANIFEST_RC`.
- The report carries `BASEBACKUP_RC`, `MANIFEST_RC`, `ENC_RC`, `LEVEL`, and from the
  extracted manifest `SYSTEM_ID`, `TIMELINE`, `PG_MAJOR` and `RAW_BYTES` (the sum of
  the manifest's file sizes — the disk a restore needs, which the compressed size hides).
- **Accepted** only when all three exit codes are 0, the manifest was extracted and
  parses, and more than an empty envelope went up — the same shape as `accept_dump()`.
- **On commit** the pending manifest is renamed to `{working dir}/.{slug}.pgmanifest`.
  On failure it is deleted, and the committed one stays as it was — the same rollback
  the tar snapshots get (§ WP3: a failed run puts every snapshot back).

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
  "code":   {"name": "code-0004.tar.gz.enc", "bytes": 98000000, "sha256": "…", "level": 0,
             "rebased_because": "tree_changed"},
  "data":   {"name": "data-0004.tar.gz.enc", "bytes": 81400000, "sha256": "…", "level": 1},
  "pgdata": {"name": "pgdata-0004.tar.gz.enc", "bytes": 64000000, "sha256": "…", "level": 1,
             "raw_bytes": 212000000, "pg": {"system_id": "…", "timeline": 1, "major": 18}},
  "meta":   {…}, "objects": {…}
}
```

- The run's own `level` is 0 exactly when every kind in it is level 0 — "this run
  restores on its own". `meta` and `objects` are whole every run and count as level 0.
  Every page that says Full / Incremental keeps reading it.
- **Restore plan per kind:** the newest level-0 artifact of that kind at or before the
  run, then every artifact of that kind after it, up to the run. A dump is always
  level 0, so a `db` plan is that run's dump, exactly as today.
- `VERSION` becomes 2. Readers accept 1 and 2; a version-1 manifest reads exactly as
  today (run level applies to the `files` kind, `db` is that run's dump). A version-2
  run carries `code` and `data`, never `files`. **Old readers refuse
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
   **Its configuration is the script's own, never the backup's.** On Ubuntu,
   `postgresql.conf`, `pg_hba.conf` and `pg_ident.conf` live in
   `/etc/postgresql/<major>/main/`, not in the data directory, so a combined Ubuntu
   backup has none and `pg_ctl` refuses to start. On the Docker image they do travel,
   carrying the source's `listen_addresses='*'` and its access rules. So the script
   writes a minimal `postgresql.conf` and a `local all all trust` `pg_hba.conf` into the
   work directory and starts with `-c config_file=… -c hba_file=… -c listen_addresses=''
   -c unix_socket_directories=<work dir> -c archive_mode=off`.
   `pg_combinebackup`, `pg_ctl` and `pg_dump` all come from
   `/usr/lib/postgresql/<major>/bin/` for the backup's major version.
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

**WP1 — Say the whole size — DONE (committed in 71277d44, `BackupRunner` 1.22).** A backup's message and job
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
- `BackupChain.php`: `VERSION = 2`, decode accepts 1|2 (`:308`); `KINDS` gains `code`,
  `data` and `pgdata`, all with extension `.tar.gz` (`:75`, `:121`); `add_run()` records each kind's level and
  derives the run level (`:137`); `restore_plan()` plans per kind (`:236`) and returns
  the database as a list (a dump is a one-element list).
- Every reader of a plan or a run: `BackupStaging.php:291` and `:308` (artifact list),
  `BackupVerifier` (`read_all`, `rehearse`, `disk_needed`), `utils/verify_backup.php`,
  the Python plan in `restore_chain.sh` (`:156`–`:213`), and on the management node
  `BackupChainListHelper.php:154`, `node_detail_tabs/backups.php:475/481/545`,
  `FleetBackupRetention::check_shelf()`, `BackupRunner::expected_bytes()` (`:1005`,
  reads `files` bytes — sum `code` + `data`, and for a full the newest level 0 of each),
  and `JobCommandBuilder::shelf_newest_run()` (`:2675`, its name regex gains
  `code|data|pgdata`, or a run with no `meta` and no `objects` is invisible to it).
- The management node upgrades before the nodes (it publishes the release, so it does).
  An older one reads a version-2 chain as "manifest could not be read" in
  `check_shelf()` — reported, never pruned, since pruning groups by the listing.
- Tests: plan per kind with version-1 fixtures unchanged; a version-2 chain with a
  code rebase mid-chain; a `pgdata` chain; an old-version reader refusing version 2
  (shell gate over `restore_chain.sh --dry-run` with fixture manifests).

**WP3 — Code and data as two archives; an upgrade re-bases the code only (D1, D3).**
Needs no particular PostgreSQL: this is what removes the release-night full on every node.
- `backup_files.sh` 1.5.0: `--part code|data` (§ Code and data are archived separately),
  each part with its own identity function and `.tree` file; `--print-tree-id` takes
  `--part` too.
- `BackupRunner`: `run_files_engine()` runs once per part, each through
  `stream_engine()`; `snar_path()` becomes per part; `snapshot_matches_tree()` per part;
  `local_need()` (`:984`) sums both snapshots and the `.pgmanifest`.
- **A failed run puts every snapshot back** rather than clearing it. Each part's snapshot
  and `.tree` are copied aside before its engine runs; on success the copies are
  removed, on failure they are restored. The run's artifacts are already deleted and the
  manifest restored, so the next run is a correct increment against the last committed
  run, and a snapshot truncated by a tar that died mid-run is healed by the same step.
  This is the `.pgmanifest`'s pending/committed rule applied to the tar snapshots. A
  transient failure then costs nothing extra, where clearing the snapshots would cost a
  new chain — 3.9 GB of uploads and a database full. A code swap during a run still
  fails the run (`BackupRunner.php:863`); the next run re-bases the code only.
- `tree_changed` stops being a reason to start a chain (`BackupChain.php:190`). It
  becomes a rebase of that kind inside the current chain: its snapshot is cleared, its
  engine takes level 0, and its artifact records `rebased_because: tree_changed`. The
  other kinds continue their increments.
- `layout_split` is a new chain-start reason: the current chain is version 1. It is
  checked **before** `snar_lost`: the version-2 snapshots live at new paths, so on the
  first version-2 run neither exists and the reason would otherwise be recorded as
  `snar_lost`.
- Every other chain-start reason is unchanged. `snar_lost` (either part's snapshot
  missing) now means a snapshot really was lost, since a failed run no longer clears them.
- The consistency checks at `BackupRunner.php:854`–`:871` compare against the planned
  per-kind levels. `full_size_warning()` compares a `data` level 0 with the previous
  `data` level 0 (version-1 history: the `files` level 0).
- `restore_chain.sh`: extract the `data` plan, then the `code` plan into the site
  directory, each in order with `--incremental`. The machine-owned files it holds across
  the extraction (`config/…`, `config/backup-ledger`) are all under `data`.
- `BackupStaging` and the agent's `stage_chain` stage both parts.
- Tests: `backup_tree_swap` becomes "a swap re-bases the code inside the chain, the data
  continues, and a restore at every run after it extracts byte-identical"; a data
  identity change re-bases the data only; a version-1 chain is followed by a
  `layout_split` chain.

**WP4 — The physical engine.** `backup_pgdata.sh` (§ Taking the backup), eligibility and
the level-0 rules as pure functions beside `should_start_new()` so they test without a
server, the self-set server settings, `run_db_engine()` choosing the engine, the
`.pgmanifest` commit/discard alongside the snapshot's, and the same-run retry.

**WP5 — Restore.** `restore_pgdata.sh` (§ Restoring), `restore_chain.sh` dispatching on
kind, and the disk preflight.

**WP6 — Verification.** Levels 2 and 3 for `pgdata` (§ Verifying).

**WP7 — Room for a whole chain's links — BUILT 2026-09-26 (agent 1.45.0, uncommitted).**
A chain job signs every object in the chain directory (`JobCommandBuilder::sign_chain_links()`)
and the node picks what its manifest names, so the job grows with runs × artifacts per run.
Before this, `stage_chain` and `verify_backup` took 64 links under a 60 KiB job: any chain
over 16 runs could not be staged or verified from the management node. The owner chose
(2026-09-26) to make the job bigger rather than cap chains, because sub-daily backups
(every three hours is under consideration) make long chains the normal case.
- **The existing words, widened**: `stage_chain` and `verify_backup` take up to 1024 links
  (`chainLinksMax`) under a 1020 KiB params ceiling (`ChainParamsBytes`), from 64 under
  60 KiB. This is the one exception to vocabulary rule 11 (a word's contract never
  changes), made by the owner on 2026-09-26 instead of shipping duplicate words.
- **The claim** (the plane's answer that hands the node a job) is read under its own cap,
  1 MiB (`agentMaxClaimBody`), like the release manifest's; every other plane answer
  keeps 64 KiB. The agent reports that cap on every claim (`claim_bytes`).
- **What rule 11 protected is kept at dispatch**: the plane holds each job to the claim's
  `claim_bytes` (64 KiB when absent), and a chain job over 64 links to an agent that
  reports none (before 1.45.0) — each fails when that node claims it, naming the update
  (`AgentChannelEndpoint::dispatch_refusal()`), never sent to be refused. At build, a
  chain past 1024 links or the ceiling is refused (`assert_chain_job_fits()`). The size
  check moved out of `sign_chain_links()`, where it also refused `restore_objects`, which
  sends none of the links.
- **Chains may run 181 runs** (`BackupRunner::MAX_INCREMENTALS` 30 → 180): a week of hourly
  runs, so the day interval is what ends a chain. 181 runs × 5 artifacts = 905 links, about
  440 KB of job — inside both new bounds.
- Tests: agent `primitives/chain_links_size_test.go` (the longest chain fits the chain
  words, one link past the bound is refused, no other word gained the ceiling),
  `remote_test.go` (the claim cap), `vocabulary_test.go` (claim_bytes is reported); plane
  `job_command_builder_test.php` (the whole chain in one job, build-time refusal, the
  claim limit, dispatch refusal for an older agent and its 64/65 edge).
- **Open:** running backups more often than daily needs a scheduling option that does not
  exist yet (the schedule is daily); that is its own change.

**WP8 — Docs** (current state only): `docs/backups.md` — the layout under *What a backup
is* (code and data archives); the database paragraph and the tree-swap paragraph under
*How chains work*;
*Opening a backup with no Joinery anywhere* gains the physical steps (decrypt each,
extract, `pg_combinebackup`, start a PostgreSQL of the same major version on the result,
`pg_dump`); *Restoring* and *Verifying* for the throwaway server and its disk.

**WP9 — Tests.** Pure (safe tier): eligibility, level-0 reasons, plan per kind, decode
1|2, the accept rule, units. Integration (db tier): a physical full + increment + restore
round trip, `harness_skip` with the reason when the server is older than 17 — which is
dev today, so it runs on the Phase 0 box and on dev once dev is on PostgreSQL 18. A pin that a
cluster holding another database always takes the dump engine.

## Phase 0 — prove the behaviour on a scratch 26.04 box

The code/data split was prototyped in a scratch directory (§ Code and data are archived
separately). Phase 0 repeats it on a copy of a real site: take a `data` and a `code` run,
run `utils/upgrade.php`, take the next run, restore every run of the chain onto a clean
directory and diff it against the site. Include a nested `public_html` (a worktree
under `sync/`) and confirm the `data` archive keeps it. Record the sizes of the
release-night run, on a copy of the publishing box so the release archives in
`static_files/` are counted.

Then the PostgreSQL behaviour:

On a scratch Linode (g6-nanode-1, deleted after), with a copy of a real site restored
onto it, confirm each assumption above and record the evidence here:

1. `pg_basebackup -D - -Ft -X fetch` injects `backup_manifest` into the stdout tar, and
   the `tee` extraction yields a manifest `--incremental` accepts.
2. `--incremental` works with tar-to-stdout and `-X fetch`.
3. `summarize_wal` and `wal_keep_size` take effect on reload, without a restart.
4. The refusal for missing WAL summaries: its text, and whether it arrives before any
   bytes stream (shapes the same-run retry).
5. `pg_combinebackup` takes the extracted directories in order; whether `--link` exists.
6. The combined directory starts as a server with the script's own configuration
   (§ Restoring step 5) and opens after replaying its WAL — once from an Ubuntu-packaged
   cluster (configuration outside the data directory) and once from the Docker image
   (configuration inside it, overridden).
7. The container image's `pg_hba.conf` allows loopback replication.
8. **Sizes:** a physical full vs `pg_dump | gzip` of the same database, and an increment
   after a day of the site's normal work.

## Live gate (owner)

On the first PostgreSQL 17+ node with real traffic: a week of nightly runs with the sizes
recorded here; one level-3 verification passing; one restore from a mid-chain run onto a
scratch box.

## Decisions (owner, 2026-09-26)

**D1 — Should the database keep its incremental chain across a code upgrade?**
Today every upgrade starts a new chain, so the night after any release is a full of
files *and* database. We released on 7 of the last 7 days.
- **Yes (WP3):** an upgrade re-bases only the code; the database keeps incrementing.
  Saves the whole database on every release night (1.65 GB on jeremytunnell). Catch: it
  changes a rule that is proven today, and every plan reader must understand a rebase
  mid-chain (WP2 builds that anyway).
- **No:** simpler; a release night stays a full of everything, so while releases are
  daily this spec saves almost nothing.
- **Decided: yes.**

**D2 — Also keep a plain dump once per chain, beside the physical full?**
- **Yes:** each chain can still be opened with only `openssl` and `psql`, restored onto
  any PostgreSQL version, with no throwaway server. Catch: one extra dump per chain
  (+1.65 GB a week on jeremytunnell, about a quarter of the new weekly total).
- **No:** the restore path already produces a dump from any run, and PostgreSQL of the
  right major version is a free, standard install. The manual procedure (WP8) is five
  commands instead of two.
- **Decided: no.**

**D3 — Archive the code and the site's data separately, so an upgrade re-uploads only the
code?** The owner's requirement (2026-09-26): an upgrade must not re-upload `uploads/`.
- **Decided: yes (WP3).**

## Related, not in this spec

- **Getting nodes onto PostgreSQL 18:** the eight Docker sites run it since 2026-09-26
  (`specs/implemented/fleet_ubuntu_2604_postgres_upgrade.md`). jeremytunnell and dev move to it
  on Ubuntu 24.04 by `specs/standalone_boxes_ubuntu_2604.md`.
