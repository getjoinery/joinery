# Backups: Core Engine, Retention, and Incremental Chains

**Status:** IMPLEMENTED. Phases 1-3 built, live-verified 2026-08-02 and committed as
`b0bd8e16`; filed 2026-08-13. Chains were then exercised end to end against a real
bucket on 2026-08-06 (full plus two genuinely incremental runs, restore replaying
an added, a changed and a deleted file) — which closed two of the three
verification risks the review brief raised. The third, retention actually
deleting an aged chain from a real bucket, has not been observed and travels with
the gaps spec below.

**Scope of this spec:** the envelope key model, the core engine with self-backup
and retention, and incremental file chains — all built. The three gaps the build
deliberately left open are split out to `specs/backups_remaining_gaps.md`.
**Date:** 2026-08-01

## Phase 3 as built

Cadence default taken: a fresh full every 7 days, plus a hard ceiling of 30
incrementals on one full.

New: `maintenance_scripts/sysadmin_tools/backup_files.sh` (tar
`--listed-incremental`), `restore_chain.sh` (ordered apply + pre-flight
verification), `includes/BackupChain.php` (manifest, chain decisions, restore
plan), chain mode in `BackupRunner`, and settings `backup_mode`,
`backup_full_interval_days`, `backup_output_dir`, `backup_exclude`.

**Deviation from the spec, deliberate.** The spec put `--incremental` on
`backup_project.sh`. That cannot work: `backup_project.sh` tars an rsync staging
copy, and a copy resets every file's ctime, so tar sees the whole site as
changed and every incremental is silently a full. Measured before building —
3 of 3 files re-shipped over a staging copy, 0 of 3 over the live tree. Chains
therefore use a separate engine that archives the live tree, and
`backup_project.sh` is untouched and still serves the full-archive path.

Live-verified on dev against the real B2 bucket: a full (193 MB files) followed
by an incremental (**37 kB files**) in one chain, correct object layout,
manifest rewritten per run, history rows carrying chain id and sequence.
`backup_chain_gate.sh` proves deletion replay, snapshot loss, and refusal of
truncated/hash-mismatched/headless chains with real tar and openssl.

Open:

- **Retention has not been exercised against a live bucket** — it needs five
  chains to exist. The selection logic is unit-tested (including that it never
  empties the shelf whatever it is asked for) and deletion reuses the same
  `S3Signer::delete` path proven by the Phase 2 cleanup.
- `restore_chain.sh` has not been run against a bucket-downloaded chain, only
  against locally produced artifacts.
- The dev box has `config/*` files owned by `user1` that `fix_permissions.sh`
  would make `www-data:user1`, so a full-tree run there fails (loudly, naming
  the file). The verification run excluded `config` and `specs` to prove the
  orchestration; that chain was deleted from the bucket afterwards.

## Phase 2 as built

Defaults taken: keep 4 restore points cloud / 7 days local, history table
`bkh_backup_history`, fleet tab observe-only.

Moved to core: `S3Signer`, `BackupTarget` (same table), `TargetTester`,
`TargetLister`, `TargetUploader`, `TargetBackups`. `TargetBackups::list_grouped()`
now takes the slug-ownership map as an argument rather than reading the node
table — a standalone site owns one slug, a control plane owns dozens — and
server_manager's new `FleetBackups` supplies the fleet answer.

New in core: `includes/BackupRunner.php`, `includes/BackupNaming.php`,
`data/backup_history_class.php`, `tasks/BackupRun.php` (+ dry run),
`adm/admin_backups.php`, `CoreSettingOptions::backupTargets`, and six declared
settings under a new `backups` group.

Verified live on dev: the page renders, saving settings works through
`SettingsWriter`, the B2 connection test passes from core, and the dry run
resolves a full plan. `db` tier 218/218 (6616 checks), `safe` 78/78.

Deferred with reasons:

- **A remote node's core backup history is not shown on its Backups tab.** That
  history lives in the node's own database, so the control plane needs a new
  management API endpoint to read it. Worth doing, but it is a new endpoint plus
  a client plus UI, and the v1 decision was observe-only.
- **`/backups` does not exist on dev**, so no real end-to-end run has executed
  here. The dry run reports this as its one problem, which is the intended
  behaviour.
- The core Backups page has target CRUD and history; the fleet **bucket browser**
  (cross-node grouping, orphaned-prefix cleanup) stays on the server_manager
  Backup Targets page, which now links to core for recovery-key setup rather
  than rendering a second copy of the panel.

## Phase 1 as built

Envelope encryption is live. New core: `includes/BackupEnvelope.php` (per-run data
keys, sealed to recovery + site recipients), `includes/BackupRecoveryKey.php` (the
recovery key and its possession ceremony), and the standalone node tool
`maintenance_scripts/sysadmin_tools/backup_envelope.php`
(`mint` / `open` / `relabel` / `site-key`). Migrations 161-162 carry the proven
recovery key onto core setting names and drop the retired rows.

Retired: `bke_backup_key_escrow` + `BackupKeyEscrow`, `BackupKeyCustody`,
`escrow_node_key.php`, `mgn_backup_key_fingerprint`, the three escrow job steps,
the per-node "seal backup key" action, the dashboard's per-node escrow rows, and
the agent-signing-key escrow record.

Known gaps carried forward, none blocking:

- **Install-from-backup cannot open an envelope from another site.** The archive's
  envelope is sealed to the *source* site's key and the recovery key; a freshly
  provisioned node holds neither. Cross-node encrypted restores already required
  a manually placed key, so this is not a regression — but the clean fix is for
  the source node to re-seal the data key to the destination's site public key at
  provisioning time, which belongs with Phase 2's provisioning work.
- **The `bke_backup_key_escrow` table and `mgn_backup_key_fingerprint` column are
  left in place.** Nothing reads them. They still hold the sealed node keys for
  pre-envelope archives sitting in buckets, so they are the recovery path for
  those until the standing "purge and recreate all backups" task clears them.
- **No full `backup_project.sh` run has been executed end to end.** The script
  hardcodes `/var/www/html/PROJECT` plus an Apache vhost, so it cannot run on a
  scratch fixture. Every piece is covered (`--key-file`, the streaming encrypt,
  the restore side, the whole node lifecycle), but one real run on a node is
  outstanding.
- `escrow_keypair.php` keeps its name — it still generates and unseals recovery
  keypairs, and renaming it would break a documented DR command.

## Problem

Backups today are full archives only, manually triggered, never expire, and live entirely in the server_manager plugin:

- Every backup is a complete `tar.gz` (files) or `pg_dump` (database), even when almost nothing changed. Daily backups of a site with large `uploads/` re-upload the same gigabytes every day.
- There is no retention anywhere. Nothing deletes old backups locally (`/backups`, including the `auto_pre_restore_*` safety snapshots) or in cloud buckets. Everything accumulates until an operator deletes it by hand.
- Nothing schedules backups. A backup happens only when an operator clicks a button on the node detail Backups tab.
- A plain Joinery install without server_manager has no backup capability at all. Core contains only two read-only management API endpoints (`includes/management_api/backups/`); all orchestration, targets, key custody, and UI live in the plugin.

## Goals

1. Every Joinery site can back itself up — scheduled, encrypted, uploaded to an S3-compatible target, with retention enforced — using only core, with no agent, no SSH, and no control plane.
2. Backups become incremental at the file level: a periodic full plus small daily deltas, with file deletions honored on restore.
3. Retention is chain-aware: a full and the incrementals that depend on it are deleted as one unit, never orphaning a chain.
4. server_manager becomes the fleet layer on top of the core engine: remote on-demand jobs, restores, install-from-backup provisioning, and the cross-node bucket browser.
5. Backup encryption converges on one root secret held by the operator (see Key Model).

## Current State (summary)

- Engines: `maintenance_scripts/sysadmin_tools/backup_project.sh` (rsync copy → single `tar.gz`) and `backup_database.sh` (`pg_dump | gzip | openssl enc -aes-256-cbc`). Both support `--non-interactive` with the key from `~/.joinery_backup_key`.
- Orchestration: `plugins/server_manager/includes/JobCommandBuilder.php` emits SSH steps; the Go agent runs them; upload is a single (never multipart) SigV4 PUT signed on the node by a heredoc'd PHP uploader (`S3Signer.php` + `node_uploader.php`).
- Object layout: flat `{bkt_path_prefix}/{mgn_slug}/{filename}`. Recognized extensions (`.sql.gz`, `.sql.gz.enc`, `.tar.gz`) are hardcoded in ~8 places.
- Key custody: per-node random key at `~/.joinery_backup_key`, sealed to an operator recovery public key (`sodium_crypto_box_seal`), escrow rows in `bke_backup_key_escrow` replicated to `escrow/{slug}/{fpr}.sealed` in the bucket, prove-possession ceremony gating the recovery public key.
- Restore: one archive handed to `restore_project.sh` / `restore_database.sh`; restore type inferred from file extension.

## Phase Order

The key model decision (Phase 1) comes first because Phase 2 moves custody code into core — the escrow machinery should not be ported if envelope encryption retires it.

---

## Phase 1 — Key Model: Envelope Encryption

### What it does for the operator

One secret — the recovery private key already in the operator's password manager — can decrypt every backup from every node, forever. Nodes no longer hold precious keys: losing a node key (or the whole node) loses nothing, so the per-node escrow bookkeeping disappears.

### Design

Each backup run generates a fresh random 32-byte data key. The archive is encrypted with that data key exactly as today (`openssl enc -aes-256-cbc -pbkdf2`). The data key itself is then sealed to one or more recipients with `sodium_crypto_box_seal` (X25519; PHP sodium is a platform requirement, present on every node):

- **Recovery recipient (always):** the operator's recovery public key. This is the same keypair generated by `escrow_keypair.php` and verified by the existing prove-possession ceremony. Because a site only ever holds the public half, the same recovery public key may be configured on any number of instances — a standalone site, or an operator's whole fleet — so one private key in the password manager covers every site that pastes it. Per-site keypairs remain possible (paste a different public key per site) but are a choice, not a requirement.
- **Site recipient (always):** a keypair the site itself holds (`config/backup_site_key`). This lets the site decrypt its own backups for operator-free restores, pre-restore rollback snapshots, and install-from-backup provisioning. This key is *disposable*: if it is lost, the recovery recipient still opens everything; a new one is minted on the next backup.

The sealed data keys travel **inside the backup artifact set** (in the chain manifest, Phase 3; for standalone full backups, in a `.keys.json` sidecar uploaded next to the archive). No key material or sealed blob is stored in the control-plane database.

### What this retires

- `bke_backup_key_escrow` table and `BackupKeyEscrow` class (append-only escrow rows).
- `BackupKeyCustody` node-key minting, no-clobber writes, fingerprint reconciliation, and `replicateBlob()` bucket replication.
- `escrow_node_key.php` and the `escrow_backup_key` / key-verify job steps prepended to every encrypted backup.
- `mgn_backup_key_fingerprint` on managed nodes.
- **Agent signing key escrow** (`escrowAgentSigningKey`, the `kind='agent_signing'` rows, the escrow-on-every-publish step). `config/agent_signing_key` sits inside the project tree, so once the project archive is encrypted (below) the control plane's own backup already protects it under the recovery key — the same guarantee escrow gave, with none of the machinery. The readiness surface changes from "is the signing key escrowed" to "is this site's project backup current", which is Phase 2's history table.
- `~/.joinery_backup_key` as a load-bearing secret (existing keys remain readable for restoring pre-migration backups; see Migration).

### What survives

- The recovery keypair and its prove-possession ceremony (moved to core, still gating encrypted backups: no proven recovery public key → encrypted backups refuse to run).
- `restore_database.sh --key-file` decryption path, extended to accept a data key unsealed from the envelope.

### The project archive was never encrypted

`backup_project.sh` ended in a plain `tar -czf`. Only the database dump *inside*
the tarball was encrypted, so every full project backup in a bucket is a
readable archive containing `config/` — `Globalvars_site.php` with the database
password, `secret_box_key`, `agent_signing_key`, `relay_pull_key`. Phase 1 fixes
this: the archive streams through `openssl` on the way out (plaintext never
lands on disk), the artifact becomes `.tar.gz.enc`, and an automated run that
cannot encrypt fails rather than silently writing the old plaintext shape.

Existing plaintext archives in buckets stay readable to anyone holding them.
They age out under Phase 2 retention; the standing "purge and recreate all
backups" task is what clears them sooner.

### Standalone-first key UX

The setup walkthrough must be completable by a self-hoster with no CLI: the keypair is generated **in the browser** (X25519 via WebCrypto, as the possession-check script already does), the private half is shown exactly once with the canonical password-manager label to save it under, and the possession proof runs immediately after — the private key never reaches the server in either step. The CLI generator (`escrow_keypair.php`) remains as the offline-preferred alternative and the DR unseal tool.

### Recovery-key replacement without re-encryption

Because archives are encrypted with per-backup data keys and only the *sealed copies* of those keys reference recipients, replacing a lost recovery key while the site is alive is cheap: generate + prove a new keypair, then the site unseals each existing backup's data key with its own site recipient and adds a sealed copy for the new recovery key — rewriting a few KB of manifest per chain, never re-uploading archives. Losing the recovery key is therefore only fatal *together with* losing the site. The readiness page's verify-nag exists to keep that conjunction improbable.

### Migration

Pre-envelope backups (`.sql.gz.enc` / `.tar.gz` encrypted with node keys) remain restorable: keep the escrow *reading* path (unseal a legacy blob with the recovery private key) as a documented manual procedure, not live code beyond a small decrypt helper. No re-encryption of old backups. Old backups age out under Phase 2 retention.

---

## Phase 2 — Core Engine: Self-Backup, Targets, Retention

### What it does for the operator

Any Joinery site — including ones server_manager has never heard of — gets scheduled, encrypted, retained offsite backups by filling in one settings panel: target credentials, schedule, retention count.

**The control plane has no special status.** A site running server_manager backs itself up with the same core `BackupRun` task as a standalone site — there is no separate "control plane backup" path, and no site's recovery may depend on the control plane being alive. Losing the control plane is recovered the same way as losing any site: provider console → fetch its own chain → recovery key opens it → restore; the fleet registry (nodes, targets, jobs, escrow history) comes back because it is simply rows in that site's database. The Phase 2 acceptance gate includes this drill for the control plane itself.

### Moves to core (from server_manager)

| Piece | Today | In core |
|---|---|---|
| `S3Signer.php` | plugin includes | `includes/S3Signer.php`, unchanged API |
| `BackupTarget` / `bkt_backup_targets` | plugin data class | `data/backup_target_class.php` (same table; credentials stay SecretBox-sealed) |
| Target test / list / delete (`TargetTester`, `TargetLister`, `TargetUploader`, `TargetBackups` minus `slug_status_map`) | plugin includes | core includes; `slug_status_map()` (raw SQL against `mgn_managed_nodes`) stays in the plugin as a decorator |
| Recovery-key settings + possession ceremony (`BackupKeyWalkthrough`, `backup_key_verify.js`, possession challenge endpoints) | plugin | core admin settings page section |
| Recognized-extension / artifact-name logic | ~8 hardcoded sites | one core helper (`BackupNaming`), consumed everywhere including the management API list/fetch handlers |

### New in core

- **`tasks/BackupRun` scheduled task.** Runs the backup engine locally (shells `backup_project.sh` / `backup_database.sh` — the scripts already ship with every install), uploads via `S3Signer` from the same process, enforces retention after a successful upload, records outcome. Config fields: enabled, backup type (project/database), schedule, target, retention count. Declared inactive by default (zero-config: a site with no target configured runs nothing and warns nothing).
- **Backup history:** a small core data class recording each run (time, type, artifact keys, size, outcome, chain id). This is what admin UI and server_manager observe — replacing "run `list_backups` and grep the bucket" as the primary source of truth. Bucket listing remains the reconciliation/DR path.
- **Local sweep:** part of `BackupRun` — prune `/backups` by age (including `auto_pre_*` snapshots), honoring the existing delete-local-after-upload behavior.
- **Core admin page** (superadmin): target CRUD + test, recovery key setup, schedule/retention settings, history list with download/delete. Self-documenting controls, no explainer prose.

### Retention rules

- Cloud: keep the newest N **restore points** per site per type (a restore point = one full backup, or one chain once Phase 3 lands). Delete oldest-first, whole restore points at a time.
- Local: keep M days in `/backups` (default small; nodes are not the archive).
- Deletion is enforced by the site that owns the backups (in `BackupRun`), not by the control plane. `TargetBackups::delete_prefix` remains for fleet cleanup of decommissioned/orphaned slugs.

### server_manager after the move

Keeps: management jobs (on-demand remote backup/restore, still valuable for "back up now before I touch this node"), install-from-backup provisioning, the fleet bucket browser (grouping by slug + live/decommissioned/orphaned status), node decommission cleanup. All of it consumes the core classes. The Backups tab gains "configure self-backup on this node" (writing the node's core settings via the management channel) and shows core backup history alongside job-driven artifacts.

---

## Phase 3 — Incremental File Chains

### What it does for the operator

Daily backups stop re-uploading unchanged files. A week of backups costs one full plus six small deltas, and restoring any day replays file additions, changes, **and deletions** to that day exactly.

### Mechanism

GNU tar `--listed-incremental` (present everywhere; no new dependency):

- A **chain** starts with a level-0 full (`tar --listed-incremental=<snar>` with a fresh snapshot file) and continues with incrementals against the evolving snar. tar records directory contents, so extracting a chain in order with `--incremental` removes files deleted between runs — deletion rules come from the format, not custom code.
- The snar lives at `/backups/{project}.snar`. **Loss of the snar is safe:** next run detects it and starts a new chain with a full. Node rebuilds, chain deletion, and corruption all degrade to "extra full," never to a broken backup.
- The database is dumped **in full on every run** (dump is the small artifact; per-table incremental detection on PG 16 is not crash-safe and is out of scope — see Later).
- Chain policy: new chain every N days (default 7) or when incremental count/size thresholds hit; incrementals on the scheduled cadence between.

### Object layout

```
{prefix}/{slug}/chain-{YYYYMMDD_HHMMSS}/
    manifest.json          # chain id, sequence, artifact names+sizes+hashes,
                           # sealed data keys (recovery + site recipients),
                           # db dump filename per run, engine versions
    full.tar.gz.enc
    db-0000.sql.gz.enc     # db dump taken with the full
    inc-0001.tar.gz.enc
    db-0001.sql.gz.enc
    ...
```

- `manifest.json` is rewritten (single PUT overwrite) after each successful run; it is the unit of listing and the restore contract.
- Retention deletes whole `chain-*/` prefixes, oldest-first, keeping the newest N chains. A chain is never partially deleted.
- Standalone full backups (Phase 2, and on-demand jobs) keep the flat layout; the Backups/history UI presents both as restore points.

### Restore

Restore of a chain point = download full + incrementals 1..k + the k-th db dump, verify manifest hashes, extract in order with `--incremental`, then restore the db dump. `restore_project.sh` gains a chain mode driven by the manifest; single-archive restore is unchanged. The extension-sniffing restore-type inference in the Backups tab is replaced by manifest/`BackupNaming` metadata.

### Engine changes

- `backup_project.sh`: `--incremental --snar <path> --chain-dir <name>` mode; emits the archive plus a run descriptor (consumed by the PHP side to update the manifest). Full-run behavior unchanged.
- Upload: multi-artifact per run (archive + db dump + manifest) — replaces the "newest file wins" `ls -t | head -1` resolution for chain runs.

---

## Out of scope

Everything this build deliberately left open — remote core-history display,
cross-site envelope portability on restore, multipart S3 upload — plus the
rejected alternatives (per-table logical incrementals, restic/borg,
client-custody integration) and the PG 17+ incremental database backups that
wait on the OS campaign, is collected in `specs/backups_remaining_gaps.md`.

## Testing

- Phase 1: envelope seal/unseal round-trip (site + recovery recipients); legacy `.enc` restore path still green (`restore_roundtrip_gate.sh` extended, not replaced).
- Phase 2: `BackupRun` task end-to-end against a test bucket (db tier); retention keeps exactly N and never deletes the newest; history rows match bucket contents; core admin page smoke.
- Phase 3: chain round-trip gate — create full + edits + deletions + incrementals, restore at each point, assert file sets (including deletions) and db state; snar-loss produces a new chain; chain-atomic retention never orphans; manifest hash verification fails loudly on a truncated artifact.
- Existing `job_command_builder_test.php` backup sections updated for retired escrow steps and multi-artifact upload.

## Documentation (update at build time, current-state only)

- New `docs/backups.md`: self-backup configuration, key model, chain format, restore procedures, retention.
- `plugins/server_manager/docs/overview.md`: fleet-layer sections rewritten around the core engine; escrow sections replaced by the envelope model; fix the phantom `bkt_delete_local` column reference.
- `docs/scheduled_tasks.md`: BackupRun entry.
- `docs/api.md`: management backups endpoints if list shapes change.

## Open Decisions

1. ~~**Key model approval (gates Phase 1)**~~ **RESOLVED 2026-08-02 — adopt envelope encryption.** Two recipients (operator recovery key + disposable site key); per-node key escrow retired. The password-manager key is the single root secret; automated restores keep working via the site recipient.
2. ~~**Retention defaults**~~ **TAKEN** — keep-4-chains cloud / 7-days local, as
   shipped. Worth re-confirming against a real site's storage bill rather than
   re-deciding here.
3. ~~**Chain cadence defaults**~~ **TAKEN** — a fresh full every 7 days plus a
   hard ceiling of 30 incrementals on one full, as shipped and exercised.
4. **Backup history table naming/prefix**, and observe-only vs. writing core
   settings on remote nodes — moved to `specs/backups_remaining_gaps.md`, where
   it belongs to the remote-history gap it gates.
