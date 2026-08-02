# Review brief — Backups: envelope keys, core self-backup, incremental chains

**For:** independent review
**Change set:** uncommitted working tree, 2026-08-02
**Spec:** `specs/backups_core_and_incremental.md` (Phases 1-3 of 4)
**Scale:** ~2,830 lines new core PHP, ~820 lines new shell/CLI, ~1,550 lines deleted,
10 test suites in `tests/backups/`

---

## What this changes, in one paragraph

Backups were server_manager-only, manual, full-copy, never expiring, and the
project archive was **not encrypted at all**. This makes backups a core
capability any site can run on a schedule with no agent and no control plane;
replaces per-node key escrow with per-backup envelope keys; encrypts the project
archive; adds retention; and makes file backups incremental with deletion replay.

---

## The three design decisions worth reviewing hardest

### 1. Envelope keys replace per-node escrow

Each run mints a random data key, encrypts with it, and seals that key to two
recipients — the operator's recovery public key, and a disposable per-site
keypair (`config/backup_site_key`). The sealed keys travel with the backup
(`.keys.json` sidecar, or the chain manifest). Nothing in a database holds key
material.

*Consequence:* a node holds nothing precious. Retires `bke_backup_key_escrow`,
`BackupKeyCustody`, `escrow_node_key.php`, `mgn_backup_key_fingerprint`, three
job steps, and the agent-signing-key escrow record.

*Reviewer question:* the agent signing key now relies on being inside the
encrypted project archive rather than having its own sealed record. Is that
equivalent? I argued yes (same recovery key opens it); it is worth a second
opinion, since it trades an explicit guarantee for an implicit one.

### 2. Chains archive the LIVE tree, not a staging copy

The spec said add `--incremental` to `backup_project.sh`. I measured first:
`backup_project.sh` tars an rsync staging copy, and a copy resets ctime, so tar
sees the whole site as changed. **3 of 3 unchanged files re-shipped over a
staging copy; 0 of 3 over the live tree.** Every "incremental" would have been a
full while appearing to work.

So chains use a new `backup_files.sh` against the live tree, and
`backup_project.sh` is untouched.

*Reviewer question:* tarring the live tree means no point-in-time consistency
across the file set (files can change mid-tar). `--warning=no-file-changed` is
set, so tar does not fail on it. For a web tree this is the normal trade — but
confirm you agree it is acceptable, and that the database dump being separate
does not create a files/database skew problem worth documenting.

### 3. Retention is chain-atomic and history-driven

Retention deletes whole chains, oldest first, and is driven by this site's own
`bkh_backup_history` rows rather than a bucket listing. It runs **last**, and
only after an upload is confirmed.

*Rationale:* a bucket listing would delete another site's objects if two sites
ever shared a slug; and a run that failed to upload must never be the run that
decides an older backup is surplus.

*Reviewer question:* history-driven retention cannot clean up objects that exist
in the bucket but not in history (e.g. written by a site whose database was
since restored from an older snapshot). Is that orphan risk acceptable, given
`TargetBackups::delete_prefix` still exists for manual cleanup?

---

## File inventory

**New core** — `includes/BackupEnvelope.php`, `BackupRecoveryKey.php`,
`BackupNaming.php`, `BackupChain.php`, `BackupRunner.php`;
`data/backup_history_class.php`; `adm/admin_backups.php` +
`adm/logic/admin_backups_logic.php`; `tasks/BackupRun.{php,json}`;
`plugins/server_manager/includes/FleetBackups.php`.

**New engines** — `maintenance_scripts/sysadmin_tools/backup_envelope.php`
(standalone, no platform bootstrap), `backup_files.sh`, `restore_chain.sh`.

**Moved plugin → core** — `S3Signer.php`, `backup_target_class.php`,
`TargetTester/TargetLister/TargetUploader/TargetBackups.php`,
`backup_key_verify.js`, and three test suites. `TargetBackups::list_grouped()`
now takes slug ownership as an argument; `FleetBackups` supplies the fleet map.

**Deleted** — `BackupKeyCustody.php`, `escrow_node_key.php`,
`backup_key_escrow_class.php`, `BackupKeyWalkthrough.php`,
`backup_key_escrow_test.php`.

**Modified engines** — `backup_database.sh` 3.4 (`--key-file`),
`backup_project.sh` 2.4.0 (encrypts the archive; refuses to downgrade),
`restore_project.sh` 1.3.0 (opens encrypted archives; detects by openssl magic,
not filename), `fix_permissions.sh` 2.5 (pins `config/backup_site_key`).

**Migrations** — 161 carries the proven recovery key to core setting names;
162 removes the retired rows. Both idempotent; 162 refuses to delete if the
core value is somehow empty.

---

## Verified, and how

| Claim | Evidence |
|---|---|
| Envelope round trip, both recipients, wrong-key refusal | `backup_envelope_test.php` 31 checks |
| CLI and core agree on the envelope format, both directions | `backup_envelope_cli_test.php` 20 checks |
| `--key-file` is honoured (decoy key in `$HOME` does NOT open it) | `backup_key_file_gate.sh`, real pg + openssl |
| Encrypted archive restores; renamed archive still opens; wrong key fails intact | `backup_key_file_gate.sh` 31 checks |
| **Chain deletion replay** — restore asserts the exact file set | `backup_chain_gate.sh` 23 checks, real tar |
| Snapshot loss / empty snapshot degrade to a new full | `backup_chain_gate.sh` |
| Truncated / hash-mismatched / headless chain refused before writing | `backup_chain_gate.sh` |
| Retention never empties the shelf at any keep value incl. 0/negative | `backup_runner_test.php` |
| Local sweep keeps envelope with archive; 0 means never | `backup_runner_test.php` |
| Naming: longest suffix wins (`.sql.gz` vs `.sql.gz.enc`) | `backup_naming_test.php` 52 checks |
| **Live**: full 193 MB → incremental **37 kB**, correct bucket layout | real run to B2, since deleted |
| Admin page: save, connection test, target picker | driven in browser |

Tiers: **`db` 220/220 (6642 checks), `safe` 79/79 (1902 checks)**, zero failures.
`validate_php_file.php` clean on all new core files.

---

## NOT verified — the honest risk list

1. **Cloud retention has never deleted a real chain.** Needs five chains to
   exist. Selection logic is unit-tested; deletion reuses the `S3Signer::delete`
   path proven by the Phase 2 bucket cleanup. **Highest-value thing to review by
   reading.**
2. **`restore_chain.sh` has never run against a bucket-downloaded chain**, only
   locally produced artifacts.
3. **No full-tree production backup has completed.** The dev box has `config/*`
   files owned by `user1`; the verification run excluded `config` and `specs` to
   prove orchestration.
4. **Remote node core-history display** is not built (needs a new management API
   endpoint; v1 decision was observe-only).
5. **Install-from-backup cannot open an envelope from another site.** Pre-existing
   gap for encrypted cross-node restores, not a regression. Clean fix is the
   source node re-sealing to the destination's site key at provisioning.
6. **Multipart upload still absent** — single-PUT ceiling (5 GB) unchanged.

---

## Where I would look for bugs

- `BackupRunner::execute_chain()` — the branch deciding new-chain vs extend, and
  the guard asserting the engine's reported level matches the decision. Sequence
  numbering comes from `count($manifest['runs'])`; a partially-written manifest
  could in principle collide.
- `BackupRunner::run_db_engine()` — identifies its dump by diffing the directory
  before/after, then renames. Concurrent runs in one chain directory would race.
  There is no run lock anywhere in this change set.
- `BackupChain::should_start_new()` — order of reasons is load-bearing
  (`snar_lost` must outrank `age`/`length`).
- `restore_chain.sh` — the embedded python3 verifier is the pre-flight gate; a
  new hard dependency on python3 for restores.
- Envelope `open()` public-key detection compares against recipient fingerprints;
  confirm that cannot false-positive.
- `BackupEnvelope::site_keypair()` uses `link()` for atomic no-clobber mint —
  check the failure path re-reads correctly.

---

## Environment / state notes

- Migrations 161 and 162 have **already run on dev**; `bkh_backup_history` exists
  and the test DB was refreshed.
- The `BackupRun` task is **deactivated** on dev deliberately (would fail nightly
  on the `config/` permissions).
- ~300 MB of test chain remains at `/var/www/html/joinerytest/backups_selftest/`
  (`www-data`-owned; needs sudo to remove).
- `docs/backups.md` is written but **not linked** from the CLAUDE.md docs index —
  that index is generated from `agf_agent_files` and must be edited via
  `/admin/admin_agent_files`, not on disk.
- `plugins/joinery_ai/views/admin/edit.php` shows as modified: a comment-only
  reword, **not part of this change set** and not made by me.
- `VERSION` and two unrelated specs were already modified before this work began.
