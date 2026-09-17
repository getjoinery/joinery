# Backup Verification — proving a backup can be recovered without restoring it

**Status:** Built and reviewed 2026-09-13 (all five packages, review findings B1-B7 fixed); stays here until the owner's live gate below passes, then moves to implemented/. Owner
asked for it after the 2026-09-13 backups review and called it important.

**Decisions taken (owner, 2026-09-13):** level 2 every 30 days as the fleet default
and the site default, stale at 60; level 1 on every pass; level 3 only when a person
asks for it, never scheduled, not for the management node either; the run verified
is always the newest.

---

## For the executor — read this first

This section is the working brief. The design sections after it are the reasons;
the build sections are the checklist. Do the packages in order: each one is
testable on its own and the next one depends on it.

**House rules that bind this work** (from CLAUDE.md and the project memory):

- **Never commit.** Edit files; the owner runs git. Never `git add` either — the
  index is shared with other sessions.
- **Schema changes go through `$field_specifications` only**, then
  `php utils/update_database.php`. Ask the owner before running it (it is a
  database write); naming the command in the ask is enough. Never a migration
  for a column.
- **Settings are declarative:** core in `settings.json` at the `public_html/`
  root, plugin in the plugin's `plugin.json` under `settings`. `Setting::put()`
  refuses an undeclared name.
- **Docs describe the current state only.** No "now", "previously", "replaces".
- **Bump the `@version` header** of every file you touch, with a one-line note.
- **After every PHP edit:** `php -l <file>`, then
  `php /var/www/html/joinerytest/maintenance_scripts/dev_tools/validate_php_file.php <file>`
  — but ONLY on class and function files (`includes/`, `data/`, `logic/`,
  `tasks/`). The validator includes the file, so never run it on `utils/*.php`
  or on a view; those get `php -l` only.
- **Tests use the shared harness** (`tests/lib/harness.php`: `harness_boot()`,
  `section()`, `check()`, `harness_finish()`) and carry the `@joinery-test`
  header (`name`, `tier`, `env`, `needs`, optional `timeout`). A test that
  writes rows calls `harness_test_mode()` after `harness_boot()` and belongs to
  the `test-db` tier; a `db` tier test on dev registers every row it creates
  with `harness_register_row()`. Run `php tests/run.php --changed` as the
  working loop and `php tests/run.php db --changed` before handing back. Never
  run the runner as root.
- **Vanilla HTML and JS only** in the admin UI. Forms through FormWriter
  (`$page->getFormWriter()`); a button that acts is a POST, never a link.
- **Plain language on every page.** The words are "verified restorable",
  "opened and read", "rehearsed". Never "chain", "restore point" or "seq" where
  a person reads.
- **The Go agent is a separate checkout at `/home/user1/joinery-agent`.** Edit
  it and run `go test ./...` there. Do NOT build, sign or publish an agent
  release and do NOT run a platform publish: both are owner steps (see
  memory `project_agent_release_channel`). Hand back with the agent version
  number the new primitive needs.
- **Never touch a live node** (no SSH, no settings writes on getjoinery or any
  other node). The live gate at the end is the owner's.
- **Secrets never appear in output.** The verify script accepts no key and no
  credential, and prints neither.

**Stop points** (hand back to the owner, do not work around):

1. Before `php utils/update_database.php` (WP1 and WP3 add columns).
2. After WP2, to get the agent version number the owner will assign to the
   release that ships `verify_backup`; put it in
   `PRIMITIVE_MIN_AGENT_VERSION['verify_backup']`.
3. When everything is green, for the release and the live gate.

**Facts you will need, verified 2026-09-13:**

- `utils/stage_chain.php` (1.0) reads JSON on stdin only:
  `{"chain_id","profile","manifest_url","artifact_urls":{name:url},"seq"?}`;
  exits 0 ok, 1 transfer/envelope/integrity failure, 2 malformed request; every
  failure line starts `STAGE_FAIL:` on stderr. It stages into
  `{site}/backups/{profile}/{chain_id}/`, recovers the chain key with
  `BackupEnvelope::open_as_site($manifest['envelope'])`, checks each fetch
  against the node-side upload ledger (`config/backup-ledger`), and writes the
  key file the way `BackupRunner` writes a run key (mode before content).
- `JobCommandBuilder::build_stage_chain_primitive()` (from line ~1828) lists
  the chain prefix with `S3Signer::list()`, presigns each object with
  `S3Signer::presign_get()` for `signed_link_seconds('stage_chain')`, keeps only
  bare names matching `^[A-Za-z0-9][A-Za-z0-9._-]*$`, and returns
  `['primitive' => 'stage_chain', 'params' => [...]]`. `has_primitive($node, X)`
  requires `build_X_primitive` to exist and the node's agent to be at least
  `PRIMITIVE_MIN_AGENT_VERSION[X]` (`stage_chain` is `1.13.0`).
- Agent primitives register in `init()` with `Register(Primitive{Name, Class,
  Description, Params: []ParamSpec{...}, Script: &ScriptSpec{Interpreter:
  "/usr/bin/php", ScriptPath: "public_html/utils/<script>.php", StdinFrom:
  <func>}, Timeout})` — copy `primitives/operate_stage_chain.go`. The class
  table in `primitives/gate_test.go` (line ~92) must list the new name. A
  script primitive is verified against the signed release manifest before it
  runs as root; a script not in the manifest is refused, which is why the
  script must ship in the same release as the agent.
- `JobResultProcessor` dispatches by job type to a private static
  `process_<type>($job)`; `process_backup_run()` (line ~808) is the model: it
  unwraps the primitive JSON envelope with `extract_api_envelope_data()`, reads
  the `BACKUP_RESULT=` line with a `^…=(\w+)$/m` regex, stamps `mgn_` columns
  on the node, and stores a `message` in `mjb_result`. The status fold
  (`fold_status_data`, `STATUS_CARRIED_KEY`) carries unmeasured keys forward.
- `FleetBackupPolicy::DEFAULTS` (line ~20) is the field list; `from_form()`
  (line ~105) maps `policy_*` form fields; the settings map (line ~126) names
  the `server_manager_fleet_backup_*` setting for each fleet default;
  `eligible_nodes()` is the one node list the scheduler and the monitor share;
  `is_due($policy, $slug, $latest, $now)` is the due rule.
- `tasks/FleetBackupRun.php` (server_manager) walks eligible nodes each tick
  under a concurrency cap, skips a node with a running job, runs the retention
  listing pass, and stamps `mgn_backup_shelf_checked_time` /
  `mgn_backup_shelf_newest_time` / `mgn_backup_shelf_bytes`. Its `.json`
  declares name, description, `default_frequency`, `activate_on_install`.
- Core scheduled tasks live in `tasks/<Name>.php` + `<Name>.json`, implement
  `ScheduledTaskInterface` (`run(array $config)` returns `'success' | 'skipped'
  | 'error'` or `['status','message']`) and optionally
  `ScheduledTaskDryRunnable` (`dryRun`). `tasks/BackupRun.php` is the model.
- `data/backup_history_class.php` (1.4): `MultiBackupHistory` options `outcome`,
  `offsite`, `deleted`, `profile`, `slug`, `type`, `chained`, `chain_id`,
  `finished_since`, `include_pruned`, `target_id`; any column name also works
  as a filter. `BackupHistory::manager_coverage()` is what the site reports to
  the plane about the plane's own backups of it.
- The node's history is the authority for what ran; the plane's `mgn_` columns
  are its copy, refreshed from job results and from the status report.
- `restore_chain.sh` 1.3.1: `PROJECT --artifacts DIR --key-file PATH [--seq N]
  [--target-dir DIR] [--skip-database] [--skip-reconcile] [--dry-run] [--force]`.
  Artifacts decrypt with `openssl enc -aes-256-cbc -d -pbkdf2 -pass fd:3`.
  `--target-dir`'s last segment must equal the archived directory name.
  `restore_database.sh` loads a dump into a named database; the
  `restore_roundtrip` gate drives it against throwaway databases and is the
  model for level 3's database half. Both scripts probe sudo with
  `sudo -n -l | grep 'NOPASSWD: ALL'`.
- The backup working area is `{site}/backups/{profile}/`; the machine-wide
  mutex is `{site}/backups/.jy_backup_machine.lock` and the profile lock
  `{site}/backups/{profile}/.jy_backup.lock` (see `BackupRunner`).
  `BackupRunner::sweep_local()` is the only thing that deletes there.
- `NodeMonitorHealth::fleet_backup_health($node)` returns
  `result($kind, $title, $detail, $is_problem)`; `backup_run_summary()`
  summarises the node's run rows; `copy_opens_here()` (1.14) is the
  fingerprint rule.
- `adm/admin_backups.php` + `adm/logic/admin_backups_logic.php` (1.6): the
  Status box reads `$milestones` (`newest`, `full`, `oldest`) from
  `_admin_backups_milestones()`; Recent backups is one row per run.
- `plugins/server_manager/includes/node_detail_tabs/backups.php` (1.9): the
  Backups box lists `$shelf_runs` from `BackupChainListHelper::for_node()`
  (`chains[].runs[] = {seq, level, time, bytes}`), with Prepare
  (`stageChain(chainId, profile, btn)` → `backup_actions` `stage_chain`) and
  Restore (`openChainRestoreModal(chainId, profile, runs, seq)`) per row.
  `plugins/server_manager/logic/backup_actions_logic.php` is the API action
  behind those buttons (`stage_chain`, `list_status`, …).

---

## What this is about

A backup that has never been opened is a hope. Today the platform can prove a
backup is *present* (the shelf listing), *intact* (size and hash against the
manifest, inside `restore_chain.sh --dry-run`) and *openable by the key this
machine holds* (Prepare, which recovers the chain key from `config/backup_site_key`).
Nothing proves a backup is *recoverable* short of restoring it over a live site,
nothing runs unattended, and nothing tells a person "this backup was last proven
restorable on this date".

This spec adds one word to every backup the platform shows: **verified**. A
verified backup has been fetched, decrypted, read to the end, and — when a person
asks for it — restored into a scratch location and counted, with nothing on the
live site touched. The proof is recorded where people look: the node card on the
management dashboard, the node's Backups tab, and the site's own Backups page.

## Three levels of proof

Each level is non-destructive. Each costs more than the one before, and each
proves something the one before does not. Numbers are getjoinery's (the largest
managed node: newest set 717 MB, largest 2.6 GB, tree ~600 MB, database 160 MB).

**Level 1 — Shelf check (plane side, free, every pass).** For every chain
manifest on the node's shelf: every artifact it names is present in the bucket
listing at the recorded size, and the envelope is inside the manifest. Catches a
partial upload, an object deleted out from under retention, a manifest rewritten
after its artifacts were pruned. Proves *present and complete*. Costs the
listing the pass already fetches plus one small GET per chain. Seconds. No disk.

**Level 2 — Open and read (node side, scheduled every 30 days).** Stage the
newest run, then for each artifact in restore order decrypt to a pipe with the
recovered chain key and read it to the end: `tar -tz` for the files and meta
archives, `gzip -t` for the database dump, plus `pg_restore --list` when the
dump is custom format. Proves *decryptable and structurally sound with the key
this machine holds*. Downloads the whole set the run depends on (0.7–2.6 GB
today, ~0.5–1 GB once archive retention is at 3); disk equal to the set, freed
at the end; 1–3 minutes.

**Level 3 — Rehearsal (node side, on request only).** Level 2, then replay the
files into a scratch directory under the backup working area and load the dump
into a throwaway database on the node's own PostgreSQL, count what came back
(files, bytes, tables, rows in a few named tables), and delete both. Proves
*recoverable*. Disk: the set plus the extracted tree plus the database (about the
set plus 0.8 GB today), freed at the end; 3–6 minutes.

The recovery-key path — the operator's private key opening a backup after the
machine is gone — is deliberately NOT exercised by any level: no level accepts a
private key, for the same reason `stage_chain.php` refuses one (a key on the wire
is a key in every job record). That path is covered by the ceremony, which proves
the key opens an envelope, together with level 2, which proves the envelope's
contents are sound. The two together are the proof; the site's Backups page says
so by showing the two dates side by side.

## Design decisions

1. **Verification is a job the node runs on itself, like a backup.** A new
   script primitive `verify_backup` (ClassOperate: destroys nothing, needs no
   approval, can run while an operator is deciding), built by
   `JobCommandBuilder::build_verify_backup_primitive()` exactly the way
   `stage_chain` is: the plane signs every object under the chain's prefix and
   hands the links over keyed by bare name; the node reads its own manifest and
   decides what it needs. The plane has no say in the layout and cannot express
   one.

2. **One node-side script, `utils/verify_backup.php`, reusing staging.** The
   fetch-and-ledger-check half of `stage_chain.php` moves into
   `includes/BackupStaging.php`, which both scripts call, so a verify can never
   fetch something a Prepare would refuse. The verify script runs the level
   asked for and removes its working directory whatever happens — a verify that
   leaves a staged chain behind is a disk leak on a machine that may already be
   tight.

3. **The node's history row is the authority for "verified".** Four columns on
   `bkh_backup_history`: `bkh_verify_time`, `bkh_verify_level` (1–3),
   `bkh_verify_outcome` (`pass`/`fail`), `bkh_verify_message`. Stamped by the
   node on the row of the run it verified, whichever profile took the run. The
   site's Backups page reads them directly; the plane learns them from the job's
   result lines and from the status report's backup summary.

4. **The plane records one fact per node: when it last proved a restore.**
   `mgn_backup_verify_time`, `mgn_backup_verify_level`, `mgn_backup_verify_outcome`,
   `mgn_backup_verify_message` on `mgn_managed_nodes`, stamped by
   `JobResultProcessor::process_verify_backup()` from the job's `VERIFY_*` lines.
   `NodeMonitorHealth::fleet_backup_health()` gains three states: never verified
   (information, not a problem, until 45 days after the first successful
   backup), verify failed (a problem, with the node's own reason), and stale
   (last pass older than 60 days). A verify failure is surfaced exactly like a
   backup failure and never triggers anything automatic — not a re-run, not a
   deletion, not a fresh full.

5. **Which run is verified: the newest.** It is the one a restore would start
   from and it exercises the whole chain beneath it (verifying run 6 reads the
   full and runs 1–5 too). A per-run Verify button on the node tab lets an
   operator pick any other.

6. **Cadence lives in the backup policy, per node like everything else.** One
   new policy field with a fleet default: `verify_every_days` (30). 0 means
   never — stored as a decision, like backups-off. The scheduled verify is
   always level 2. Level 3 runs only when a person asks (the Verify button on
   the node tab, the Backups page's own button, or the shell entry); it is not a
   policy position and no schedule can select it. Level 1 needs no setting.

7. **Scheduling reuses the fleet backup pass.** `FleetBackupRun` already walks
   every eligible node once per tick with its policy, its latest run and its
   concurrency budget. A verify is due when `verify_every_days` > 0, the newest
   successful run is newer than the last verify, and `verify_every_days` have
   elapsed since the last verify (or there has never been one and the first
   successful backup is older than a day); it is dispatched under the same
   concurrency cap and never on a node whose backup, stage or verify is still
   running. Level 1 runs inside the existing retention listing pass on every
   tick and stamps `mgn_backup_shelf_problem` (text, empty when whole).

8. **Disk before download.** The node refuses a level 2 or 3 it cannot hold: it
   compares the set's manifest bytes (×1 for level 2; plus the recorded full
   files size ×2 and the dump size ×3 for level 3) against free space under the
   backup working area, and answers `VERIFY_RESULT=skipped VERIFY_REASON=disk`
   with both numbers. A skip is neither a pass nor a failure; the card says
   "could not verify: needs N GB free, has M".

9. **Same locks as a backup.** The verify takes the machine-wide backup mutex
   and the profile lock, so it never races a run that is writing the chain it is
   reading, and a scheduled backup that finds the lock held waits exactly as it
   would for another backup.

10. **Retention interplay.** Retention on the plane may delete a chain while a
    verify of it is in flight. The verify fails with `VERIFY_REASON=gone` naming
    the object; the next pass verifies the newer chain. The retention pass never
    skips a deletion because a verify is running — a verify must not be able to
    keep a chain alive.

11. **The site's own backups verify the same way.** Backups are core; so is
    verification. A core scheduled task `BackupVerify` (activated alongside
    `Backup`, one setting `backup_verify_every_days`, default 30) runs a level 2
    `verify_backup.php` on the site's own newest run with links it signs itself
    from its own target. A site that runs no backups of its own has nothing to
    verify and the task says so, once. Level 3 is the page's own "Rehearse a
    restore" button.

12. **Wording.** The user-facing phrase is **verified restorable**, dated. Level
    names on the page: "checked on the shelf", "opened and read", "rehearsed". A
    failure says what was found, in the node's own words.

---

## Build items

Order: WP1 → WP2 → WP3 → WP4 → WP5. WP1 is the largest and everything else
reads its contract.

### WP1 — Node side: `BackupStaging` and `verify_backup.php` (core)

**Files**

| File | Change |
|---|---|
| `includes/BackupStaging.php` (new, 1.0) | fetch + ledger check + envelope open + key file, extracted from `utils/stage_chain.php`; pure functions over an explicit `$work` directory; throws `BackupStagingException` with the same messages the script printed |
| `utils/stage_chain.php` → 1.1 | thin caller of `BackupStaging`; identical stdout/stderr/exit contract (its existing tests must pass unchanged) |
| `includes/BackupVerifier.php` (new, 1.0) | the level 2 and level 3 engine over an already-staged directory and key file: `read_all($work, $manifest, $seq, $key_file)`, `rehearse(...)`, `disk_needed($manifest, $seq, $level)`; returns the contract as an array; no network. This is what the gate tests and what the shell entry calls |
| `utils/verify_backup.php` (new, 1.0) | the script the primitive runs: stdin → `BackupStaging` (fetch) → `BackupVerifier` (read or rehearse) → stamps the history row → prints the contract |
| `includes/BackupRunner.php` → 1.13 | `sweep_local()` removes `verify-*` working directories older than one day; `BackupRunner::human()` reused for messages |
| `data/backup_history_class.php` → 1.5 | `bkh_verify_time timestamp(6)`, `bkh_verify_level int4`, `bkh_verify_outcome varchar(20)`, `bkh_verify_message text`; option `verified => true/false` (`bkh_verify_time IS NOT NULL / IS NULL`) |
| `maintenance_scripts/sysadmin_tools/verify_backup.sh` (new, 1.0.0) | the shell entry for an operator holding the recovery private key (WP5 documents it; build it here because level 2/3 logic is shared) |

**`utils/verify_backup.php` contract**

CLI only. JSON on stdin, nothing on argv:

```
{"chain_id":"chain-20260912_044520","profile":"manager","level":2,
 "manifest_url":"https://…signed…",
 "artifact_urls":{"files-0000.tar.gz.enc":"https://…", …},
 "seq":1}                       // optional; default = newest run in the manifest
```

Steps, in order, all under the backup locks (decision 9):

1. Parse; refuse a malformed request with exit 2 and `VERIFY_FAIL:` on stderr.
2. Work in `{site}/backups/{profile}/verify-{pid}/`. Register a shutdown
   function that removes it (and level 3's scratch tree and database) on every
   exit path, including a fatal.
3. Fetch the manifest (via `BackupStaging`); pick the run; compute the artifact
   list a restore of that run needs (the full's files, every incremental's files
   up to it, that run's db and meta) using `BackupChain::restore_plan()`.
4. **Disk check** (decision 8) before any artifact download.
5. Fetch each artifact through `BackupStaging` (ledger-checked), verifying size
   and sha256 against the manifest as it lands.
6. Open the envelope with the site key; write the key file (mode before content).
7. **Level 2:** for each artifact in restore order, decrypt to a pipe and read:
   `openssl enc -aes-256-cbc -d -pbkdf2 -pass fd:3 -in <artifact> | tar -tz > /dev/null`
   for files and meta (count entries, sum nothing); `… | gzip -t` for the dump,
   then `… | gunzip | head -c 65536` must contain `PGDMP` or `PostgreSQL database
   dump`. Any non-zero status or a read that ends early is a `fail` naming the
   artifact.
8. **Level 3:** `restore_chain.sh <project> --artifacts <work> --key-file <key>
   --seq <n> --target-dir <work>/scratch/<project> --skip-database
   --skip-reconcile --force`; then `restore_database.sh` into a database named
   `verify_<slug>_<pid>` that this script creates and drops
   (`--no-pre-restore-dump`). Count files and bytes under the scratch tree;
   `SELECT count(*) FROM information_schema.tables WHERE table_schema='public'`;
   row counts for `usr_users` and the three largest tables by
   `pg_total_relation_size`. A database that cannot be created is `skipped`
   with `VERIFY_REASON=createdb`.
9. Stamp `bkh_verify_*` on the history row whose `bkh_chain_id`/`bkh_chain_seq`
   match the run (if the node has the row; a manager run always does).
10. Print the contract on stdout, one key per line, and exit 0 on `pass` or
    `skipped`, 1 on `fail`:

```
VERIFY_RESULT=pass|fail|skipped
VERIFY_LEVEL=2|3
VERIFY_RUN=<chain_id>/<seq>
VERIFY_RUN_TIME=<manifest run time, UTC>
VERIFY_ARTIFACTS=<n read>
VERIFY_BYTES=<bytes read>
VERIFY_FILES=<entries listed (2) or files restored (3)>
VERIFY_TABLES=<n>              (level 3 only)
VERIFY_ROWS=usr_users:<n>,<table>:<n>,…   (level 3 only)
VERIFY_DURATION=<seconds>
VERIFY_REASON=<one line>       (fail or skipped only)
VERIFY_NEEDS_BYTES=<n> VERIFY_FREE_BYTES=<n>   (skipped, disk only)
```

**Acceptance for WP1**

- `tests/backups/backup_verify_test.php` (tier `db`, env `dev-only`): contract
  builder and parser round-trip; disk arithmetic for both levels; the artifact
  plan for run N of a synthetic manifest; `sweep_local` removes a day-old
  `verify-*` directory and leaves a fresh one; `bkh_verify_*` stamping on a
  fixture row (registered for cleanup).
- `tests/backups/backup_verify_gate.sh` (tier `db`, env `dev-only`, timeout
  600): reuse `backup_chain_gate.sh`'s fixture chain and drive
  `BackupVerifier` (through `verify_backup.sh --artifacts DIR --key-file PATH`)
  on the staged fixture directory — no fetch, because `BackupFetch` refuses
  anything but a signed https link (`backup_fetch_test.php` pins that). Level 2
  passes and prints the counts; one artifact flipped by a byte fails naming it;
  level 3 restores into scratch with the fixture's exact file set and a table
  count > 0; after pass and after fail, the work directory and the throwaway
  database are gone. The fetch half is already covered by `stage_chain`'s
  tests, and WP1 keeps those green.
- `stage_chain`'s existing tests (`grep -rl stage_chain tests plugins/*/tests`)
  unchanged and green.
- Schema applied on dev by `php utils/update_database.php` — **stop point 1**.

### WP2 — Agent: the `verify_backup` primitive (`/home/user1/joinery-agent`)

**Files**

| File | Change |
|---|---|
| `primitives/operate_verify_backup.go` (new) | copy of `operate_stage_chain.go` with `Name: "verify_backup"`, an extra `{Name: "level", Type: ParamInt, Min: 2, Max: 3, Required: true}`, `ScriptPath: "public_html/utils/verify_backup.php"`, `StdinFrom: verifyBackupConfig` (adds `level`), `Timeout: 3*time.Hour` |
| `primitives/operate_verify_backup_test.go` (new) | registered, ClassOperate, no credential or key param (copy the `stage_chain` assertions), config marshals `level` |
| `primitives/gate_test.go` | `"verify_backup": ClassOperate` in the class table |
| `main.go` | version bump per the release process — **stop point 2**; do not build or publish |

**Acceptance:** `go test ./...` green; `gofmt -l .` empty.

### WP3 — Plane: builder, result, health, policy, scheduling, listing (server_manager)

**Files**

| File | Change |
|---|---|
| `includes/JobCommandBuilder.php` | `PRIMITIVE_MIN_AGENT_VERSION['verify_backup']` (the version from stop point 2); `build_verify_backup($node, $params)` / `_primitive()`; the link-signing body of `build_stage_chain_primitive()` moves to a private `sign_chain_links($node, $chain_id, $profile, $operation)` used by both; `level` validated to 2 or 3; `seq` optional as today |
| `includes/JobResultProcessor.php` | `process_verify_backup($job)`: parse the `VERIFY_*` lines (unwrap the envelope like `process_backup_run`), stamp the four `mgn_backup_verify_*` columns (`skipped` leaves `_time` alone, records the reason), store `message` in `mjb_result` in plain words ("Opened and read the backup of 2026-09-13 04:45 UTC: 3 archives, 717 MB, 1,842 files") |
| `data/managed_nodes_class.php` | `mgn_backup_verify_time timestamp(6)`, `mgn_backup_verify_level int4`, `mgn_backup_verify_outcome varchar(20)`, `mgn_backup_verify_message text`, `mgn_backup_shelf_problem text` |
| `includes/FleetBackupPolicy.php` | `DEFAULTS['verify_every_days'] = 30`; `from_form()` reads `policy_verify_every_days`; settings map entry `server_manager_fleet_backup_verify_every_days`; `is_verify_due($policy, $node, $now)` per decision 7 |
| `plugin.json` | setting `server_manager_fleet_backup_verify_every_days` (default `"30"`, group `backups`, plain helptext); version bump |
| `tasks/FleetBackupRun.php` | level 1 inside the listing pass → `mgn_backup_shelf_problem` (empty string when whole; the check reads each chain's manifest via `BackupChainListHelper` and compares artifact names and sizes to the listing); level 2 dispatch when `is_verify_due`, under the cap, skipped with a named reason when a `backup_run`, `stage_chain` or `verify_backup` job is active for the node; the pass message names verifies dispatched and skipped like it names backups |
| `includes/NodeMonitorHealth.php` | the three verify states in `fleet_backup_health()`; a non-empty `mgn_backup_shelf_problem` is a problem titled "A backup on the shelf is incomplete" with the text |
| `includes/node_detail_tabs/backups.php` | under the three facts: **Last verified restorable** — level name, date, counts, or the reason; Verify button per run beside Prepare, opening a small dialog with two choices, "Open and read" and "Rehearse a restore (needs about N GB free on the node)"; the schedule summary and the fleet-default dropdown sentence gain ", verified every 30 days by opening and reading the newest backup"; policy editor field "Days between verifications (0 = never)" |
| `logic/backup_actions_logic.php` | action `verify_backup` (`chain_id`, `profile`, `seq`, `level`) creating the job like `stage_chain`; `list_status` unchanged |
| dashboard card | no new markup: the card already renders `fleet_backup_health` |

**Acceptance for WP3**

- `plugins/server_manager/tests/job_command_builder_test.php`: a section for
  `verify_backup` (refuses level 1 and 4, refuses a missing chain id, signs the
  same link set as `stage_chain`, carries `level` and optional `seq`).
- `plugins/server_manager/tests/job_result_processor_test.php`: `VERIFY_*`
  folding for pass, fail and skipped; the plain-words message.
- `plugins/server_manager/tests/fleet_backup_schedule_test.php`: `is_verify_due`
  (never verified + first backup older than a day → due; verified 29 days ago →
  not due; 31 → due; 0 → never; no successful backup → never); the three health
  states and their wording; shelf problem wording; a level 1 comparison over a
  synthetic listing and manifest (whole, short by bytes, missing artifact,
  missing envelope).
- `php tests/run.php db --changed` green. Schema applied — stop point 1 again
  for the `mgn_` columns if WP1's run did not include them.

### WP4 — Site side: the Backups page and the core task (core)

**Files**

| File | Change |
|---|---|
| `adm/logic/admin_backups_logic.php` → 1.7 | `milestones['verified']` = newest row with `bkh_verify_outcome = 'pass'`; `milestones['verify_failed']` = newest `fail` newer than that pass, or null; action `verify_backup` (POST, level 2 or 3) that runs `utils/verify_backup.php` for the site's own newest run with links it signs from its own target — through `S3Signer::presign_get`, never inline credentials — as a background process the way `run_backup` is started today |
| `adm/admin_backups.php` | Status box: fourth row **Last verified restorable** (level name, date, counts) with the recovery-key ceremony date beside it: "your recovery key was last proven on …"; a failed verify newer than the last pass shows in red with the reason. Buttons under the Status box: "Verify the newest backup" (level 2) and "Rehearse a restore" (level 3, with the disk note), FormWriter POST forms. Recent backups: Availability adds "verified restorable · date" or "verification failed · reason" from the row's `bkh_verify_*` |
| `tasks/BackupVerify.php` + `.json` (new) | `ScheduledTaskInterface` + `ScheduledTaskDryRunnable`; `run()` finds the site's newest successful offsite run, checks `backup_verify_every_days`, builds links from the site's target and runs the script at level 2; returns `skipped` with a sentence when the site takes no backups of its own; `dryRun()` says which run it would open and how big it is |
| `settings.json` | `backup_verify_every_days`, default `"30"`, group `backups` |
| `adm/logic/admin_backups_logic.php` (the query near line 373 that activates `BackupRun` when a target and key are ready) and `includes/SetupSteps.php` (line ~708 reads `BackupRun`'s active state for the setup checklist) | activate `BackupVerify` alongside `BackupRun`; the checklist treats them as one item |
| `data/backup_history_class.php` | the status report's `backups` summary (what `manager_coverage()` feeds) carries `last_verify_time`, `last_verify_level`, `last_verify_outcome` so the plane sees a verify the site ran itself; `JobResultProcessor`'s status fold stamps `mgn_backup_verify_*` from it when newer than what it holds |

**Acceptance for WP4**

- `tests/backups/backup_verify_test.php` gains: milestone selection (a pass, a
  newer fail, a fail older than the pass); `BackupVerify::run()` skips with the
  right sentence on a site with no own backups; `dryRun` names the run.
- The page smoke-renders from the CLI with fixture rows (the technique used on
  2026-09-13: eval the Status and Recent backups blocks with a stub `$page`).
- `php tests/run.php db --changed` green.

### WP5 — Manual path and docs

- `maintenance_scripts/sysadmin_tools/verify_backup.sh` (built in WP1): usage
  `verify_backup.sh --artifacts DIR --key-file PATH [--seq N] [--level 2|3]
  [--project NAME]` — the same level 2/3 steps against a chain directory an
  operator downloaded by hand, with the key recovered by
  `backup_envelope.php open --private /path/to/recovery.key`. This is the one
  path that exercises the recovery private key, and the runbook says so.
- Docs, written at build time, describing the end state only:
  - `docs/backups.md`: a **Verifying backups** section — the three levels in the
    page's words, what each proves, what none proves and how the ceremony
    closes it, the two settings, the shell runbook, the disk and egress costs.
  - `plugins/server_manager/docs/overview.md`: the Backups tab line (Last
    verified restorable, the Verify button), the policy field, `verify_backup`
    in the job-types table (ClassOperate, script, no approval), the shelf check
    in the fleet backup pass.
  - `docs/scheduled_tasks.md`: `BackupVerify`; `FleetBackupRun`'s verify
    dispatch and shelf check.
- Memory: update `project_backup_listing_investigation_2026_09_13.md` status
  when each package lands.

---

## Owner's live gate (after release; not the executor's)

1. Level 2 on getjoinery from the dashboard; the card and both pages show the
   date and counts.
2. Level 3 on getjoinery; counts match the site (files ≈ tree, tables ≈ 200+).
3. Delete one artifact from the bucket by hand; the next pass's shelf check
   names it on the card; a level 2 of that chain fails with `gone`.
4. A node with its disk nearly full skips with the two numbers on the card.
5. Restore the deleted artifact from a fresh backup run; the card clears.

## Costs to state on the pages and in the docs

- Level 2 downloads the whole set each time; on Backblaze that is egress. At the
  monthly default on a 1 GB set it is inside the free allowance for the whole
  fleet; weekly across nine nodes would still be under 40 GB a month.
- Level 3 needs scratch disk of roughly twice the site plus the database, and a
  PostgreSQL role that can create a database. Both are checked before anything
  is downloaded, and the node says the numbers when it declines.
